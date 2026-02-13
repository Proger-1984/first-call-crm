<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\Listing;
use App\Models\ObjectClient;
use App\Models\PipelineStage;
use App\Models\Property;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Сервис бизнес-логики CRM: объекты недвижимости, воронка, статистика
 */
class PropertyService
{
    // ==========================================
    // ОБЪЕКТЫ — CRUD
    // ==========================================

    /**
     * Получить список объектов с фильтрами и пагинацией
     *
     * @param int $userId
     * @param array $params Фильтры: search, deal_type, stage_ids, is_archived, sort, order, page, per_page
     * @return array{properties: array, pagination: array}
     */
    public function getProperties(int $userId, array $params = []): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 20)));
        $sortField = $params['sort'] ?? 'created_at';
        $sortDirection = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Разрешённые поля для сортировки
        $allowedSortFields = ['created_at', 'price', 'address', 'deal_type', 'owner_name', 'stage'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        $query = Property::where('user_id', $userId)
            ->with(['objectClients.pipelineStage', 'objectClients.contact']);

        // Фильтр: архив/активные
        $isArchived = $params['is_archived'] ?? null;
        if ($isArchived !== null) {
            $query->where('is_archived', filter_var($isArchived, FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('is_archived', false);
        }

        // Фильтр: тип сделки
        $dealType = $params['deal_type'] ?? null;
        if ($dealType && in_array($dealType, Property::ALLOWED_DEAL_TYPES, true)) {
            $query->byDealType($dealType);
        }

        // Фильтр: стадии воронки (множественный выбор)
        $stageIds = $params['stage_ids'] ?? null;
        if ($stageIds) {
            $stageIdArray = is_array($stageIds) ? $stageIds : explode(',', (string)$stageIds);
            $stageIdArray = array_filter(array_map('intval', $stageIdArray));
            if (!empty($stageIdArray)) {
                $query->whereHas('objectClients', function ($q) use ($stageIdArray) {
                    $q->whereIn('pipeline_stage_id', $stageIdArray);
                });
            }
        }

        // Поиск по адресу, собственнику, телефону
        $search = $params['search'] ?? null;
        if ($search) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('address', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('title', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('owner_name', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('owner_phone', 'ILIKE', "%{$escapedSearch}%");
            });
        }

        $total = $query->count();

        // Сортировка по стадии — подзапрос к pipeline_stages через object_clients
        if ($sortField === 'stage') {
            $nullsPosition = 'NULLS LAST';
            $query->orderByRaw("(
                SELECT MIN(ps.sort_order)
                FROM object_clients oc
                JOIN pipeline_stages ps ON oc.pipeline_stage_id = ps.id
                WHERE oc.property_id = properties.id
            ) {$sortDirection} {$nullsPosition}");
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        $properties = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'properties' => $properties->map(fn(Property $property) => $this->formatProperty($property))->toArray(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Получить объект по ID с проверкой владельца
     *
     * @param int $propertyId
     * @param int $userId
     * @return Property|null
     */
    public function getProperty(int $propertyId, int $userId): ?Property
    {
        return Property::where('id', $propertyId)
            ->where('user_id', $userId)
            ->with(['objectClients.contact', 'objectClients.pipelineStage', 'listing'])
            ->first();
    }

    /**
     * Создать объект недвижимости
     *
     * @param int $userId
     * @param array $data
     * @return Property
     */
    public function createProperty(int $userId, array $data): Property
    {
        $propertyData = [
            'user_id' => $userId,
            'deal_type' => in_array($data['deal_type'] ?? '', Property::ALLOWED_DEAL_TYPES, true)
                ? $data['deal_type'] : Property::DEAL_TYPE_SALE,
            'is_archived' => false,
        ];

        // Если указан listing_id — подтягиваем данные из объявления
        // listing_id может быть как внутренним PK, так и external_id с источника
        $listingIdInput = isset($data['listing_id']) ? trim((string)$data['listing_id']) : null;
        if ($listingIdInput) {
            // Сначала ищем по external_id, затем по внутреннему ID (если вмещается в integer)
            $listing = Listing::where('external_id', $listingIdInput)->first();
            if (!$listing) {
                $numericId = (int)$listingIdInput;
                // PostgreSQL integer макс. ~2.1 млрд — не отправляем запрос с бОльшим числом
                if ($numericId > 0 && $numericId <= 2147483647) {
                    $listing = Listing::find($numericId);
                }
            }
            if (!$listing) {
                throw new InvalidArgumentException('Объявление не найдено');
            }

            $listingId = $listing->id;

            // Защита от дубликатов: проверяем, не добавлял ли агент уже этот объект
            $existingProperty = Property::where('user_id', $userId)
                ->where('listing_id', $listingId)
                ->first();
            if ($existingProperty) {
                throw new InvalidArgumentException('Этот объект уже добавлен в ваш список');
            }

            // Автоопределение типа сделки из категории объявления
            $categoryId = $listing->category_id ?? null;
            $rentCategoryIds = [1, 2]; // Аренда жилая, Аренда коммерческая
            if ($categoryId && in_array($categoryId, $rentCategoryIds, true)) {
                $propertyData['deal_type'] = Property::DEAL_TYPE_RENT;
            }

            $propertyData['listing_id'] = $listingId;
            $propertyData['title'] = $data['title'] ?? $listing->title ?? null;
            $propertyData['address'] = $data['address'] ?? $listing->getFullAddress() ?? null;
            $propertyData['price'] = $data['price'] ?? $listing->price ?? null;
            // Комнаты — из связи room (room_id → таблица rooms)
            $roomsCount = null;
            if ($listing->room_id) {
                $room = $listing->room;
                if ($room) {
                    $roomsCount = is_numeric($room->code) ? (int)$room->code : null;
                }
            }
            $propertyData['rooms'] = $data['rooms'] ?? $roomsCount;
            $propertyData['area'] = $data['area'] ?? $listing->square_meters ?? null;
            $propertyData['floor'] = $data['floor'] ?? $listing->floor ?? null;
            $propertyData['floors_total'] = $data['floors_total'] ?? $listing->floors_total ?? null;
            $propertyData['url'] = $data['url'] ?? $listing->url ?? null;
            $propertyData['description'] = $data['description'] ?? null;
        } else {
            // Ручной ввод
            $propertyData['title'] = isset($data['title']) ? mb_substr(trim($data['title']), 0, 500) : null;
            $propertyData['address'] = isset($data['address']) ? mb_substr(trim($data['address']), 0, 500) : null;
            $propertyData['price'] = isset($data['price']) ? (float)$data['price'] : null;
            $propertyData['rooms'] = isset($data['rooms']) ? (int)$data['rooms'] : null;
            $propertyData['area'] = isset($data['area']) ? (float)$data['area'] : null;
            $propertyData['floor'] = isset($data['floor']) ? (int)$data['floor'] : null;
            $propertyData['floors_total'] = isset($data['floors_total']) ? (int)$data['floors_total'] : null;
            $propertyData['description'] = $data['description'] ?? null;
            $propertyData['url'] = isset($data['url']) ? mb_substr(trim($data['url']), 0, 1000) : null;
        }

        // Собственник
        $propertyData['owner_name'] = isset($data['owner_name']) ? mb_substr(trim($data['owner_name']), 0, 255) : null;
        $propertyData['owner_phone'] = isset($data['owner_phone']) ? mb_substr(trim($data['owner_phone']), 0, 20) : null;
        $propertyData['owner_phone_secondary'] = isset($data['owner_phone_secondary']) ? mb_substr(trim($data['owner_phone_secondary']), 0, 20) : null;

        // Дополнительно
        $propertyData['source_type'] = isset($data['source_type']) ? mb_substr(trim($data['source_type']), 0, 50) : null;
        $propertyData['source_details'] = isset($data['source_details']) ? mb_substr(trim($data['source_details']), 0, 255) : null;
        $propertyData['comment'] = $data['comment'] ?? null;

        $property = Property::create($propertyData);
        $property->load(['objectClients.contact', 'objectClients.pipelineStage']);

        return $property;
    }

    /**
     * Обновить объект
     *
     * @param Property $property
     * @param array $data
     * @return Property
     */
    public function updateProperty(Property $property, array $data): Property
    {
        $updateData = [];

        $stringFields = [
            'title' => 500, 'address' => 500, 'owner_name' => 255,
            'owner_phone' => 20, 'owner_phone_secondary' => 20,
            'source_type' => 50, 'source_details' => 255, 'url' => 1000,
        ];

        foreach ($stringFields as $field => $maxLength) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field] ? mb_substr(trim($data[$field]), 0, $maxLength) : null;
            }
        }

        if (isset($data['deal_type']) && in_array($data['deal_type'], Property::ALLOWED_DEAL_TYPES, true)) {
            $updateData['deal_type'] = $data['deal_type'];
        }
        if (array_key_exists('price', $data)) {
            $updateData['price'] = $data['price'] !== null ? (float)$data['price'] : null;
        }
        if (array_key_exists('rooms', $data)) {
            $updateData['rooms'] = $data['rooms'] !== null ? (int)$data['rooms'] : null;
        }
        if (array_key_exists('area', $data)) {
            $updateData['area'] = $data['area'] !== null ? (float)$data['area'] : null;
        }
        if (array_key_exists('floor', $data)) {
            $updateData['floor'] = $data['floor'] !== null ? (int)$data['floor'] : null;
        }
        if (array_key_exists('floors_total', $data)) {
            $updateData['floors_total'] = $data['floors_total'] !== null ? (int)$data['floors_total'] : null;
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }
        if (array_key_exists('comment', $data)) {
            $updateData['comment'] = $data['comment'];
        }

        if (!empty($updateData)) {
            $property->update($updateData);
        }

        $property->load(['objectClients.contact', 'objectClients.pipelineStage']);
        return $property;
    }

    /**
     * Архивировать/разархивировать объект
     *
     * @param Property $property
     * @param bool $archive
     * @return Property
     */
    public function toggleArchive(Property $property, bool $archive): Property
    {
        $property->update(['is_archived' => $archive]);
        return $property;
    }

    /**
     * Удалить объект (каскадно удалит object_clients)
     *
     * @param Property $property
     * @return bool
     */
    public function deleteProperty(Property $property): bool
    {
        return (bool)$property->delete();
    }

    // ==========================================
    // ВОРОНКА (PIPELINE)
    // ==========================================

    /**
     * Получить данные для kanban-доски (стадии + пары объект+контакт)
     *
     * @param int $userId
     * @return array
     */
    public function getPipelineBoard(int $userId): array
    {
        $stages = PipelineStage::getOrCreateForUser($userId);

        // Загружаем все связки одним запросом с eager loading
        $objectClients = ObjectClient::whereHas('property', function ($q) use ($userId) {
            $q->where('user_id', $userId)->where('is_archived', false);
        })
            ->with(['property', 'contact', 'pipelineStage'])
            ->orderBy('updated_at', 'desc')
            ->get()
            ->groupBy('pipeline_stage_id');

        return $stages->map(function (PipelineStage $stage) use ($objectClients) {
            $stageCards = $objectClients->get($stage->id, collect());

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'sort_order' => $stage->sort_order,
                'is_system' => $stage->is_system,
                'is_final' => $stage->is_final,
                'cards' => $stageCards->map(fn(ObjectClient $oc) => $this->formatPipelineCard($oc))->toArray(),
            ];
        })->toArray();
    }

    // ==========================================
    // СТАТИСТИКА
    // ==========================================

    /**
     * Получить статистику по объектам
     *
     * @param int $userId
     * @return array
     */
    public function getStats(int $userId): array
    {
        $now = Carbon::now();
        $weekAgo = $now->copy()->subDays(7);

        $stats = Property::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) FILTER (WHERE is_archived = false) as total_active,
                COUNT(*) FILTER (WHERE is_archived = true) as total_archived,
                COUNT(*) FILTER (WHERE is_archived = false AND deal_type = 'sale') as type_sale,
                COUNT(*) FILTER (WHERE is_archived = false AND deal_type = 'rent') as type_rent,
                COUNT(*) FILTER (WHERE is_archived = false AND created_at >= ?) as new_this_week
            ", [$weekAgo])
            ->first();

        // Количество связок с просроченными контактами
        $overdueContacts = ObjectClient::whereHas('property', function ($q) use ($userId) {
            $q->where('user_id', $userId)->where('is_archived', false);
        })
            ->whereNotNull('next_contact_at')
            ->where('next_contact_at', '<', $now)
            ->count();

        // Общее количество контактов
        $totalContacts = Contact::where('user_id', $userId)->where('is_archived', false)->count();

        return [
            'total_active' => (int)$stats->total_active,
            'total_archived' => (int)$stats->total_archived,
            'by_deal_type' => [
                Property::DEAL_TYPE_SALE => (int)$stats->type_sale,
                Property::DEAL_TYPE_RENT => (int)$stats->type_rent,
            ],
            'new_this_week' => (int)$stats->new_this_week,
            'overdue_contacts' => $overdueContacts,
            'total_contacts' => $totalContacts,
        ];
    }

    // ==========================================
    // ФОРМАТИРОВАНИЕ
    // ==========================================

    /**
     * Форматировать объект для API ответа (полная версия)
     */
    public function formatProperty(Property $property): array
    {
        $result = [
            'id' => $property->id,
            'listing_id' => $property->listing_id,
            'title' => $property->title,
            'address' => $property->address,
            'price' => $property->price,
            'rooms' => $property->rooms,
            'area' => $property->area,
            'floor' => $property->floor,
            'floors_total' => $property->floors_total,
            'description' => $property->description,
            'url' => $property->url,
            'deal_type' => $property->deal_type,
            'owner_name' => $property->owner_name,
            'owner_phone' => $property->owner_phone,
            'owner_phone_secondary' => $property->owner_phone_secondary,
            'source_type' => $property->source_type,
            'source_details' => $property->source_details,
            'comment' => $property->comment,
            'is_archived' => $property->is_archived,
            'created_at' => $property->created_at?->toIso8601String(),
            'updated_at' => $property->updated_at?->toIso8601String(),
        ];

        // Связки объект+контакт
        if ($property->relationLoaded('objectClients')) {
            $result['object_clients'] = $property->objectClients->map(function (ObjectClient $oc) {
                $item = [
                    'id' => $oc->id,
                    'contact_id' => $oc->contact_id,
                    'comment' => $oc->comment,
                    'next_contact_at' => $oc->next_contact_at?->toIso8601String(),
                    'last_contact_at' => $oc->last_contact_at?->toIso8601String(),
                    'created_at' => $oc->created_at?->toIso8601String(),
                ];

                if ($oc->relationLoaded('contact') && $oc->contact) {
                    $item['contact'] = [
                        'id' => $oc->contact->id,
                        'name' => $oc->contact->name,
                        'phone' => $oc->contact->phone,
                        'email' => $oc->contact->email,
                    ];
                }

                if ($oc->relationLoaded('pipelineStage') && $oc->pipelineStage) {
                    $item['pipeline_stage'] = [
                        'id' => $oc->pipelineStage->id,
                        'name' => $oc->pipelineStage->name,
                        'color' => $oc->pipelineStage->color,
                        'is_final' => $oc->pipelineStage->is_final,
                    ];
                }

                return $item;
            })->toArray();
            $result['contacts_count'] = $property->objectClients->count();
        }

        // Связанное объявление
        if ($property->relationLoaded('listing') && $property->listing) {
            $result['listing'] = [
                'id' => $property->listing->id,
                'title' => $property->listing->title,
                'price' => $property->listing->price,
                'url' => $property->listing->url,
            ];
        }

        return $result;
    }

    /**
     * Форматировать карточку для kanban (пара объект+контакт)
     */
    public function formatPipelineCard(ObjectClient $objectClient): array
    {
        $property = $objectClient->property;
        $contact = $objectClient->contact;

        return [
            'id' => $objectClient->id,
            'property_id' => $objectClient->property_id,
            'contact_id' => $objectClient->contact_id,
            'comment' => $objectClient->comment,
            'next_contact_at' => $objectClient->next_contact_at?->toIso8601String(),
            'last_contact_at' => $objectClient->last_contact_at?->toIso8601String(),
            'property' => $property ? [
                'id' => $property->id,
                'title' => $property->title,
                'address' => $property->address,
                'price' => $property->price,
                'rooms' => $property->rooms,
                'area' => $property->area,
                'floor' => $property->floor,
                'deal_type' => $property->deal_type,
            ] : null,
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->phone,
            ] : null,
            'updated_at' => $objectClient->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Безопасный парсинг даты
     */
    private function safeParseDate(string $value): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException("Неверный формат даты: {$value}");
        }
    }
}
