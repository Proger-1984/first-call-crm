<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

/**
 * Сервис бизнес-логики CRM: справочник контактов
 */
class ContactService
{
    /**
     * Получить список контактов с фильтрами и пагинацией
     *
     * @param int $userId
     * @param array $params Фильтры: search, is_archived, sort, order, page, per_page
     * @return array{contacts: array, pagination: array}
     */
    public function getContacts(int $userId, array $params = []): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 20)));
        $sortField = $params['sort'] ?? 'created_at';
        $sortDirection = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSortFields = ['created_at', 'name', 'phone'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        $query = Contact::where('user_id', $userId)
            ->withCount('objectClients');

        // Фильтр: архив/активные
        $isArchived = $params['is_archived'] ?? null;
        if ($isArchived !== null) {
            $query->where('is_archived', filter_var($isArchived, FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('is_archived', false);
        }

        // Поиск
        $search = $params['search'] ?? null;
        if ($search) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('name', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('phone', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('email', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('telegram_username', 'ILIKE', "%{$escapedSearch}%");
            });
        }

        $total = $query->count();

        $contacts = $query->orderBy($sortField, $sortDirection)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'contacts' => $contacts->map(fn(Contact $contact) => $this->formatContact($contact))->toArray(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Получить контакт по ID с проверкой владельца
     *
     * @param int $contactId
     * @param int $userId
     * @return Contact|null
     */
    public function getContact(int $contactId, int $userId): ?Contact
    {
        return Contact::where('id', $contactId)
            ->where('user_id', $userId)
            ->with(['objectClients.property', 'objectClients.pipelineStage'])
            ->withCount('objectClients')
            ->first();
    }

    /**
     * Создать контакт
     *
     * @param int $userId
     * @param array $data
     * @return Contact
     */
    public function createContact(int $userId, array $data): Contact
    {
        return Contact::create([
            'user_id' => $userId,
            'name' => mb_substr(trim($data['name']), 0, 255),
            'phone' => isset($data['phone']) ? mb_substr(trim($data['phone']), 0, 20) : null,
            'phone_secondary' => isset($data['phone_secondary']) ? mb_substr(trim($data['phone_secondary']), 0, 20) : null,
            'email' => isset($data['email']) ? mb_substr(trim($data['email']), 0, 255) : null,
            'telegram_username' => isset($data['telegram_username']) ? mb_substr(trim($data['telegram_username']), 0, 100) : null,
            'comment' => $data['comment'] ?? null,
        ]);
    }

    /**
     * Обновить контакт
     *
     * @param Contact $contact
     * @param array $data
     * @return Contact
     */
    public function updateContact(Contact $contact, array $data): Contact
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
        if (array_key_exists('comment', $data)) {
            $updateData['comment'] = $data['comment'];
        }

        if (!empty($updateData)) {
            $contact->update($updateData);
        }

        return $contact;
    }

    /**
     * Удалить контакт
     *
     * @param Contact $contact
     * @return bool
     */
    public function deleteContact(Contact $contact): bool
    {
        return (bool)$contact->delete();
    }

    /**
     * Поиск контактов (для модалки ContactPicker)
     *
     * @param int $userId
     * @param string $search
     * @param int $limit
     * @return array
     */
    public function searchContacts(int $userId, string $search, int $limit = 20): array
    {
        $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        $contacts = Contact::where('user_id', $userId)
            ->where('is_archived', false)
            ->where(function ($q) use ($escapedSearch) {
                $q->where('name', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('phone', 'ILIKE', "%{$escapedSearch}%");
            })
            ->limit($limit)
            ->get();

        return $contacts->map(fn(Contact $contact) => $this->formatContact($contact))->toArray();
    }

    /**
     * Форматировать контакт для API ответа
     */
    public function formatContact(Contact $contact): array
    {
        $result = [
            'id' => $contact->id,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'phone_secondary' => $contact->phone_secondary,
            'email' => $contact->email,
            'telegram_username' => $contact->telegram_username,
            'comment' => $contact->comment,
            'is_archived' => $contact->is_archived,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];

        // Количество связок
        if (isset($contact->object_clients_count)) {
            $result['properties_count'] = (int)$contact->object_clients_count;
        }

        // Связанные объекты
        if ($contact->relationLoaded('objectClients')) {
            $result['object_clients'] = $contact->objectClients->map(function ($oc) {
                $item = [
                    'id' => $oc->id,
                    'property_id' => $oc->property_id,
                    'comment' => $oc->comment,
                    'next_contact_at' => $oc->next_contact_at?->toIso8601String(),
                ];

                if ($oc->relationLoaded('property') && $oc->property) {
                    $item['property'] = [
                        'id' => $oc->property->id,
                        'title' => $oc->property->title,
                        'address' => $oc->property->address,
                        'price' => $oc->property->price,
                        'deal_type' => $oc->property->deal_type,
                    ];
                }

                if ($oc->relationLoaded('pipelineStage') && $oc->pipelineStage) {
                    $item['pipeline_stage'] = [
                        'id' => $oc->pipelineStage->id,
                        'name' => $oc->pipelineStage->name,
                        'color' => $oc->pipelineStage->color,
                    ];
                }

                return $item;
            })->toArray();
        }

        return $result;
    }
}
