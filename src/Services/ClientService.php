<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\ClientListing;
use App\Models\ClientSearchCriteria;
use App\Models\Listing;
use App\Models\PipelineStage;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Сервис бизнес-логики CRM-модуля (клиенты, воронка, подборки)
 */
class ClientService
{
    // ==========================================
    // КЛИЕНТЫ — CRUD
    // ==========================================

    /**
     * Получить список клиентов с фильтрами и пагинацией
     *
     * @param int $userId
     * @param array $params Фильтры: search, client_type, stage_id, is_archived, source_type, sort, order, page, per_page
     * @return array{clients: array, pagination: array}
     */
    public function getClients(int $userId, array $params = []): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 20)));
        $sortField = $params['sort'] ?? 'created_at';
        $sortDirection = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Разрешённые поля для сортировки
        $allowedSortFields = ['created_at', 'name', 'last_contact_at', 'next_contact_at', 'budget_max'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        $query = Client::where('user_id', $userId)
            ->with(['pipelineStage']);

        // Фильтр: архив/активные
        $isArchived = $params['is_archived'] ?? null;
        if ($isArchived !== null) {
            $query->where('is_archived', filter_var($isArchived, FILTER_VALIDATE_BOOLEAN));
        } else {
            // По умолчанию показываем только активных
            $query->where('is_archived', false);
        }

        // Фильтр: тип клиента
        $clientType = $params['client_type'] ?? null;
        if ($clientType && in_array($clientType, Client::ALLOWED_TYPES, true)) {
            $query->byType($clientType);
        }

        // Фильтр: стадия воронки
        $stageId = $params['stage_id'] ?? null;
        if ($stageId) {
            $query->byStage((int)$stageId);
        }

        // Фильтр: источник
        $sourceType = $params['source_type'] ?? null;
        if ($sourceType) {
            $query->where('source_type', $sourceType);
        }

        // Поиск по имени, телефону, email
        $search = $params['search'] ?? null;
        if ($search) {
            // Экранируем спецсимволы LIKE (%, _, \)
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('name', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('phone', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('email', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('telegram_username', 'ILIKE', "%{$escapedSearch}%");
            });
        }

        $total = $query->count();

        $clients = $query->orderBy($sortField, $sortDirection)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'clients' => $clients->map(fn(Client $client) => $this->formatClient($client))->toArray(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Получить клиента по ID с проверкой владельца
     *
     * @param int $clientId
     * @param int $userId
     * @return Client|null
     */
    public function getClient(int $clientId, int $userId): ?Client
    {
        return Client::where('id', $clientId)
            ->where('user_id', $userId)
            ->with(['pipelineStage', 'searchCriteria.category', 'searchCriteria.location', 'clientListings.listing'])
            ->first();
    }

    /**
     * Создать клиента
     *
     * @param int $userId
     * @param array $data
     * @return Client
     */
    public function createClient(int $userId, array $data): Client
    {
        // Если стадия не указана — берём первую (начальную)
        $stageId = $data['pipeline_stage_id'] ?? null;
        if (!$stageId) {
            $firstStage = PipelineStage::getFirstStage($userId);
            if (!$firstStage) {
                throw new Exception('Не удалось создать стадии воронки');
            }
            $stageId = $firstStage->id;
        }

        // Проверяем, что стадия принадлежит пользователю
        if (!PipelineStage::belongsToUser((int)$stageId, $userId)) {
            throw new Exception('Стадия воронки не найдена');
        }

        $client = Client::create([
            'user_id' => $userId,
            'pipeline_stage_id' => (int)$stageId,
            'name' => mb_substr(trim($data['name']), 0, 255),
            'phone' => isset($data['phone']) ? mb_substr(trim($data['phone']), 0, 20) : null,
            'phone_secondary' => isset($data['phone_secondary']) ? mb_substr(trim($data['phone_secondary']), 0, 20) : null,
            'email' => isset($data['email']) ? mb_substr(trim($data['email']), 0, 255) : null,
            'telegram_username' => isset($data['telegram_username']) ? mb_substr(trim($data['telegram_username']), 0, 100) : null,
            'client_type' => in_array($data['client_type'] ?? '', Client::ALLOWED_TYPES, true) ? $data['client_type'] : Client::TYPE_BUYER,
            'source_type' => isset($data['source_type']) ? mb_substr(trim($data['source_type']), 0, 50) : null,
            'source_details' => isset($data['source_details']) ? mb_substr(trim($data['source_details']), 0, 255) : null,
            'budget_min' => isset($data['budget_min']) ? (float)$data['budget_min'] : null,
            'budget_max' => isset($data['budget_max']) ? (float)$data['budget_max'] : null,
            'comment' => $data['comment'] ?? null,
            'next_contact_at' => isset($data['next_contact_at']) ? Carbon::parse($data['next_contact_at']) : null,
        ]);

        $client->load('pipelineStage');
        return $client;
    }

    /**
     * Обновить клиента
     *
     * @param Client $client
     * @param array $data
     * @return Client
     */
    public function updateClient(Client $client, array $data): Client
    {
        $updateData = [];

        if (isset($data['name'])) {
            $updateData['name'] = mb_substr(trim($data['name']), 0, 255);
        }
        if (array_key_exists('phone', $data)) {
            $updateData['phone'] = $data['phone'] ? mb_substr(trim($data['phone']), 0, 20) : null;
        }
        if (array_key_exists('phone_secondary', $data)) {
            $updateData['phone_secondary'] = $data['phone_secondary'] ? mb_substr(trim($data['phone_secondary']), 0, 20) : null;
        }
        if (array_key_exists('email', $data)) {
            $updateData['email'] = $data['email'] ? mb_substr(trim($data['email']), 0, 255) : null;
        }
        if (array_key_exists('telegram_username', $data)) {
            $updateData['telegram_username'] = $data['telegram_username'] ? mb_substr(trim($data['telegram_username']), 0, 100) : null;
        }
        if (isset($data['client_type']) && in_array($data['client_type'], Client::ALLOWED_TYPES, true)) {
            $updateData['client_type'] = $data['client_type'];
        }
        if (array_key_exists('source_type', $data)) {
            $updateData['source_type'] = $data['source_type'] ? mb_substr(trim($data['source_type']), 0, 50) : null;
        }
        if (array_key_exists('source_details', $data)) {
            $updateData['source_details'] = $data['source_details'] ? mb_substr(trim($data['source_details']), 0, 255) : null;
        }
        if (array_key_exists('budget_min', $data)) {
            $updateData['budget_min'] = $data['budget_min'] !== null ? (float)$data['budget_min'] : null;
        }
        if (array_key_exists('budget_max', $data)) {
            $updateData['budget_max'] = $data['budget_max'] !== null ? (float)$data['budget_max'] : null;
        }
        if (array_key_exists('comment', $data)) {
            $updateData['comment'] = $data['comment'];
        }
        if (array_key_exists('next_contact_at', $data)) {
            $updateData['next_contact_at'] = $data['next_contact_at'] ? Carbon::parse($data['next_contact_at']) : null;
        }
        if (array_key_exists('last_contact_at', $data)) {
            $updateData['last_contact_at'] = $data['last_contact_at'] ? Carbon::parse($data['last_contact_at']) : null;
        }

        if (!empty($updateData)) {
            $client->update($updateData);
        }

        $client->load('pipelineStage');
        return $client;
    }

    /**
     * Переместить клиента на другую стадию воронки
     *
     * @param Client $client
     * @param int $stageId
     * @param int $userId
     * @return Client
     */
    public function moveToStage(Client $client, int $stageId, int $userId): Client
    {
        if (!PipelineStage::belongsToUser($stageId, $userId)) {
            throw new Exception('Стадия воронки не найдена');
        }

        $client->update(['pipeline_stage_id' => $stageId]);
        $client->load('pipelineStage');
        return $client;
    }

    /**
     * Архивировать/разархивировать клиента
     *
     * @param Client $client
     * @param bool $archive
     * @return Client
     */
    public function toggleArchive(Client $client, bool $archive): Client
    {
        $client->update(['is_archived' => $archive]);
        return $client;
    }

    /**
     * Удалить клиента
     *
     * @param Client $client
     * @return bool
     */
    public function deleteClient(Client $client): bool
    {
        return (bool)$client->delete();
    }

    // ==========================================
    // ВОРОНКА (PIPELINE)
    // ==========================================

    /**
     * Получить сводку по воронке (количество клиентов по стадиям)
     *
     * @param int $userId
     * @return array
     */
    public function getPipelineSummary(int $userId): array
    {
        // Используем withCount вместо N+1 запросов
        $stages = PipelineStage::where('user_id', $userId)
            ->withCount(['clients' => fn($query) => $query->where('is_archived', false)])
            ->orderBy('sort_order')
            ->get();

        if ($stages->isEmpty()) {
            PipelineStage::createDefaultStages($userId);
            $stages = PipelineStage::where('user_id', $userId)
                ->withCount(['clients' => fn($query) => $query->where('is_archived', false)])
                ->orderBy('sort_order')
                ->get();
        }

        return $stages->map(function (PipelineStage $stage) {
            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'sort_order' => $stage->sort_order,
                'is_system' => $stage->is_system,
                'is_final' => $stage->is_final,
                'clients_count' => $stage->clients_count,
            ];
        })->toArray();
    }

    /**
     * Получить данные для kanban-доски (стадии + клиенты)
     *
     * @param int $userId
     * @return array
     */
    public function getPipelineBoard(int $userId): array
    {
        $stages = PipelineStage::getOrCreateForUser($userId);

        // Загружаем всех активных клиентов одним запросом, группируем по стадии
        $clientsByStage = Client::where('user_id', $userId)
            ->where('is_archived', false)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->groupBy('pipeline_stage_id');

        return $stages->map(function (PipelineStage $stage) use ($clientsByStage) {
            $stageClients = $clientsByStage->get($stage->id, collect());

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'sort_order' => $stage->sort_order,
                'is_system' => $stage->is_system,
                'is_final' => $stage->is_final,
                'clients' => $stageClients->map(fn(Client $client) => $this->formatClientShort($client))->toArray(),
            ];
        })->toArray();
    }

    // ==========================================
    // СТАТИСТИКА
    // ==========================================

    /**
     * Получить статистику по клиентам
     *
     * @param int $userId
     * @return array
     */
    public function getStats(int $userId): array
    {
        $now = Carbon::now();
        $weekAgo = $now->copy()->subDays(7);

        // Один запрос с условными подсчётами вместо 8 отдельных COUNT
        $stats = Client::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) FILTER (WHERE is_archived = false) as total_active,
                COUNT(*) FILTER (WHERE is_archived = true) as total_archived,
                COUNT(*) FILTER (WHERE is_archived = false AND client_type = 'buyer') as type_buyer,
                COUNT(*) FILTER (WHERE is_archived = false AND client_type = 'seller') as type_seller,
                COUNT(*) FILTER (WHERE is_archived = false AND client_type = 'renter') as type_renter,
                COUNT(*) FILTER (WHERE is_archived = false AND client_type = 'landlord') as type_landlord,
                COUNT(*) FILTER (WHERE is_archived = false AND created_at >= ?) as new_this_week,
                COUNT(*) FILTER (WHERE is_archived = false AND next_contact_at IS NOT NULL AND next_contact_at < ?) as overdue_contacts
            ", [$weekAgo, $now])
            ->first();

        return [
            'total_active' => (int)$stats->total_active,
            'total_archived' => (int)$stats->total_archived,
            'by_type' => [
                Client::TYPE_BUYER => (int)$stats->type_buyer,
                Client::TYPE_SELLER => (int)$stats->type_seller,
                Client::TYPE_RENTER => (int)$stats->type_renter,
                Client::TYPE_LANDLORD => (int)$stats->type_landlord,
            ],
            'new_this_week' => (int)$stats->new_this_week,
            'overdue_contacts' => (int)$stats->overdue_contacts,
        ];
    }

    // ==========================================
    // КРИТЕРИИ ПОИСКА
    // ==========================================

    /**
     * Добавить критерий поиска клиенту
     *
     * @param int $clientId
     * @param array $data
     * @return ClientSearchCriteria
     */
    public function addSearchCriteria(int $clientId, array $data): ClientSearchCriteria
    {
        return ClientSearchCriteria::create([
            'client_id' => $clientId,
            'category_id' => $data['category_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'room_ids' => $data['room_ids'] ?? null,
            'price_min' => isset($data['price_min']) ? (float)$data['price_min'] : null,
            'price_max' => isset($data['price_max']) ? (float)$data['price_max'] : null,
            'area_min' => isset($data['area_min']) ? (float)$data['area_min'] : null,
            'area_max' => isset($data['area_max']) ? (float)$data['area_max'] : null,
            'floor_min' => isset($data['floor_min']) ? (int)$data['floor_min'] : null,
            'floor_max' => isset($data['floor_max']) ? (int)$data['floor_max'] : null,
            'metro_ids' => $data['metro_ids'] ?? null,
            'districts' => $data['districts'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Обновить критерий поиска
     *
     * @param ClientSearchCriteria $criteria
     * @param array $data
     * @return ClientSearchCriteria
     */
    public function updateSearchCriteria(ClientSearchCriteria $criteria, array $data): ClientSearchCriteria
    {
        $updateData = [];

        $fields = [
            'category_id', 'location_id', 'room_ids', 'price_min', 'price_max',
            'area_min', 'area_max', 'floor_min', 'floor_max', 'metro_ids',
            'districts', 'notes', 'is_active',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $criteria->update($updateData);
        }

        return $criteria;
    }

    /**
     * Удалить критерий поиска
     *
     * @param ClientSearchCriteria $criteria
     * @return bool
     */
    public function deleteSearchCriteria(ClientSearchCriteria $criteria): bool
    {
        return (bool)$criteria->delete();
    }

    // ==========================================
    // ПОДБОРКИ (ПРИВЯЗКА ОБЪЯВЛЕНИЙ)
    // ==========================================

    /**
     * Привязать объявление к клиенту
     *
     * @param int $clientId
     * @param int $listingId
     * @param string|null $comment
     * @return ClientListing
     */
    public function addListing(int $clientId, int $listingId, ?string $comment = null): ClientListing
    {
        // Проверяем, что объявление существует
        $listing = Listing::find($listingId);
        if (!$listing) {
            throw new InvalidArgumentException('Объявление не найдено');
        }

        // Проверяем уникальность
        $existing = ClientListing::where('client_id', $clientId)
            ->where('listing_id', $listingId)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException('Объявление уже привязано к этому клиенту');
        }

        return ClientListing::create([
            'client_id' => $clientId,
            'listing_id' => $listingId,
            'status' => ClientListing::STATUS_PROPOSED,
            'comment' => $comment ? mb_substr(trim($comment), 0, 500) : null,
        ]);
    }

    /**
     * Отвязать объявление от клиента
     *
     * @param int $clientId
     * @param int $listingId
     * @return bool
     */
    public function removeListing(int $clientId, int $listingId): bool
    {
        $clientListing = ClientListing::where('client_id', $clientId)
            ->where('listing_id', $listingId)
            ->first();

        if (!$clientListing) {
            throw new InvalidArgumentException('Привязка не найдена');
        }

        return (bool)$clientListing->delete();
    }

    /**
     * Обновить статус привязки объявления
     *
     * @param int $clientId
     * @param int $listingId
     * @param string $status
     * @param string|null $comment
     * @return ClientListing
     */
    public function updateListingStatus(int $clientId, int $listingId, string $status, ?string $comment = null): ClientListing
    {
        if (!in_array($status, ClientListing::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Недопустимый статус');
        }

        $clientListing = ClientListing::where('client_id', $clientId)
            ->where('listing_id', $listingId)
            ->first();

        if (!$clientListing) {
            throw new InvalidArgumentException('Привязка не найдена');
        }

        $updateData = ['status' => $status];

        // При смене на showed — фиксируем дату показа
        if ($status === ClientListing::STATUS_SHOWED && !$clientListing->showed_at) {
            $updateData['showed_at'] = Carbon::now();
        }

        if ($comment !== null) {
            $updateData['comment'] = mb_substr(trim($comment), 0, 500);
        }

        $clientListing->update($updateData);
        $clientListing->load('listing');

        return $clientListing;
    }

    // ==========================================
    // ФОРМАТИРОВАНИЕ
    // ==========================================

    /**
     * Форматировать клиента для API ответа (полная версия)
     */
    public function formatClient(Client $client): array
    {
        $result = [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone,
            'phone_secondary' => $client->phone_secondary,
            'email' => $client->email,
            'telegram_username' => $client->telegram_username,
            'client_type' => $client->client_type,
            'source_type' => $client->source_type,
            'source_details' => $client->source_details,
            'budget_min' => $client->budget_min,
            'budget_max' => $client->budget_max,
            'comment' => $client->comment,
            'is_archived' => $client->is_archived,
            'last_contact_at' => $client->last_contact_at?->toIso8601String(),
            'next_contact_at' => $client->next_contact_at?->toIso8601String(),
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];

        // Стадия воронки
        if ($client->relationLoaded('pipelineStage') && $client->pipelineStage) {
            $result['pipeline_stage'] = [
                'id' => $client->pipelineStage->id,
                'name' => $client->pipelineStage->name,
                'color' => $client->pipelineStage->color,
                'is_final' => $client->pipelineStage->is_final,
            ];
        }

        // Критерии поиска
        if ($client->relationLoaded('searchCriteria')) {
            $result['search_criteria'] = $client->searchCriteria->map(function (ClientSearchCriteria $criteria) {
                return [
                    'id' => $criteria->id,
                    'category' => $criteria->relationLoaded('category') && $criteria->category
                        ? ['id' => $criteria->category->id, 'name' => $criteria->category->name]
                        : null,
                    'location' => $criteria->relationLoaded('location') && $criteria->location
                        ? ['id' => $criteria->location->id, 'name' => $criteria->location->getFullName()]
                        : null,
                    'room_ids' => $criteria->room_ids,
                    'price_min' => $criteria->price_min,
                    'price_max' => $criteria->price_max,
                    'area_min' => $criteria->area_min,
                    'area_max' => $criteria->area_max,
                    'floor_min' => $criteria->floor_min,
                    'floor_max' => $criteria->floor_max,
                    'metro_ids' => $criteria->metro_ids,
                    'districts' => $criteria->districts,
                    'notes' => $criteria->notes,
                    'is_active' => $criteria->is_active,
                ];
            })->toArray();
        }

        // Привязанные объявления
        if ($client->relationLoaded('clientListings')) {
            $result['listings'] = $client->clientListings->map(function (ClientListing $cl) {
                $listingData = [
                    'id' => $cl->id,
                    'listing_id' => $cl->listing_id,
                    'status' => $cl->status,
                    'comment' => $cl->comment,
                    'showed_at' => $cl->showed_at?->toIso8601String(),
                    'created_at' => $cl->created_at?->toIso8601String(),
                ];

                if ($cl->relationLoaded('listing') && $cl->listing) {
                    $listingData['listing'] = [
                        'id' => $cl->listing->id,
                        'title' => $cl->listing->title,
                        'price' => $cl->listing->price,
                        'address' => $cl->listing->getFullAddress(),
                        'url' => $cl->listing->url,
                    ];
                }

                return $listingData;
            })->toArray();
            $result['listings_count'] = $client->clientListings->count();
        }

        return $result;
    }

    /**
     * Форматировать клиента для API ответа (краткая версия, для kanban)
     */
    public function formatClientShort(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone,
            'client_type' => $client->client_type,
            'budget_min' => $client->budget_min,
            'budget_max' => $client->budget_max,
            'last_contact_at' => $client->last_contact_at?->toIso8601String(),
            'next_contact_at' => $client->next_contact_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }
}
