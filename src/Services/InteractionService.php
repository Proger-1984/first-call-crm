<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\ObjectClient;
use App\Models\PipelineStage;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Сервис бизнес-логики CRM: таймлайн взаимодействий
 */
class InteractionService
{
    /**
     * Получить таймлайн по объекту (все связки)
     *
     * @param int $propertyId
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array{interactions: array, total: int}
     */
    public function getByProperty(int $propertyId, int $userId, int $limit = 50, int $offset = 0): array
    {
        $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
        if (!$property) {
            throw new InvalidArgumentException('Объект не найден');
        }

        $objectClientIds = ObjectClient::where('property_id', $propertyId)->pluck('id');

        $query = Interaction::whereIn('object_client_id', $objectClientIds)
            ->with(['user', 'objectClient.contact']);

        $total = $query->count();

        $interactions = $query->orderBy('interaction_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'interactions' => $interactions->map(fn(Interaction $item) => $this->formatInteraction($item))->toArray(),
            'total' => $total,
        ];
    }

    /**
     * Получить таймлайн по контакту (все связки с этим контактом)
     *
     * @param int $contactId
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array{interactions: array, total: int}
     */
    public function getByContact(int $contactId, int $userId, int $limit = 50, int $offset = 0): array
    {
        $contact = Contact::where('id', $contactId)->where('user_id', $userId)->first();
        if (!$contact) {
            throw new InvalidArgumentException('Контакт не найден');
        }

        $objectClientIds = ObjectClient::where('contact_id', $contactId)
            ->whereHas('property', fn($q) => $q->where('user_id', $userId))
            ->pluck('id');

        $query = Interaction::whereIn('object_client_id', $objectClientIds)
            ->with(['user', 'objectClient.property']);

        $total = $query->count();

        $interactions = $query->orderBy('interaction_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'interactions' => $interactions->map(fn(Interaction $item) => $this->formatInteraction($item))->toArray(),
            'total' => $total,
        ];
    }

    /**
     * Получить таймлайн конкретной связки объект+контакт
     *
     * @param int $propertyId
     * @param int $contactId
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array{interactions: array, total: int}
     */
    public function getByObjectClient(int $propertyId, int $contactId, int $userId, int $limit = 50, int $offset = 0): array
    {
        $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
        if (!$property) {
            throw new InvalidArgumentException('Объект не найден');
        }

        $objectClient = ObjectClient::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->first();

        if (!$objectClient) {
            throw new InvalidArgumentException('Связка не найдена');
        }

        $query = Interaction::where('object_client_id', $objectClient->id)
            ->with(['user']);

        $total = $query->count();

        $interactions = $query->orderBy('interaction_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'interactions' => $interactions->map(fn(Interaction $item) => $this->formatInteraction($item))->toArray(),
            'total' => $total,
        ];
    }

    /**
     * Создать взаимодействие
     *
     * @param int $propertyId
     * @param int $contactId
     * @param int $userId
     * @param array $data {type, description?, interaction_at?, metadata?}
     * @return Interaction
     */
    public function create(int $propertyId, int $contactId, int $userId, array $data): Interaction
    {
        $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
        if (!$property) {
            throw new InvalidArgumentException('Объект не найден');
        }

        $objectClient = ObjectClient::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->first();

        if (!$objectClient) {
            throw new InvalidArgumentException('Связка не найдена');
        }

        $type = $data['type'] ?? '';
        if (!in_array($type, Interaction::MANUAL_TYPES, true)) {
            throw new InvalidArgumentException('Недопустимый тип взаимодействия');
        }

        $interactionAt = Carbon::now();
        if (!empty($data['interaction_at'])) {
            try {
                $interactionAt = Carbon::parse($data['interaction_at']);
            } catch (\Exception $exception) {
                throw new InvalidArgumentException('Неверный формат даты');
            }
        }

        $interaction = Interaction::create([
            'object_client_id' => $objectClient->id,
            'user_id' => $userId,
            'type' => $type,
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'interaction_at' => $interactionAt,
        ]);

        // Обновляем last_contact_at у связки для звонков, встреч и показов
        if (in_array($type, [Interaction::TYPE_CALL, Interaction::TYPE_MEETING, Interaction::TYPE_SHOWING], true)) {
            $objectClient->update(['last_contact_at' => $interactionAt]);
        }

        $interaction->load(['user', 'objectClient.contact', 'objectClient.property']);

        return $interaction;
    }

    /**
     * Логировать смену стадии (автоматическая запись)
     *
     * @param ObjectClient $objectClient
     * @param int $userId
     * @param int $oldStageId
     * @param int $newStageId
     * @return Interaction
     */
    public function logStageChange(ObjectClient $objectClient, int $userId, int $oldStageId, int $newStageId): Interaction
    {
        $oldStage = PipelineStage::find($oldStageId);
        $newStage = PipelineStage::find($newStageId);

        $oldStageName = $oldStage ? $oldStage->name : "ID:{$oldStageId}";
        $newStageName = $newStage ? $newStage->name : "ID:{$newStageId}";

        return Interaction::create([
            'object_client_id' => $objectClient->id,
            'user_id' => $userId,
            'type' => Interaction::TYPE_STAGE_CHANGE,
            'description' => "Стадия изменена: {$oldStageName} → {$newStageName}",
            'metadata' => [
                'old_stage_id' => $oldStageId,
                'new_stage_id' => $newStageId,
                'old_stage_name' => $oldStageName,
                'new_stage_name' => $newStageName,
            ],
            'interaction_at' => Carbon::now(),
        ]);
    }

    /**
     * Форматировать взаимодействие для API ответа
     */
    public function formatInteraction(Interaction $interaction): array
    {
        $result = [
            'id' => $interaction->id,
            'object_client_id' => $interaction->object_client_id,
            'user_id' => $interaction->user_id,
            'type' => $interaction->type,
            'description' => $interaction->description,
            'metadata' => $interaction->metadata,
            'interaction_at' => $interaction->interaction_at?->toIso8601String(),
            'created_at' => $interaction->created_at?->toIso8601String(),
        ];

        if ($interaction->relationLoaded('user') && $interaction->user) {
            $result['user'] = [
                'id' => $interaction->user->id,
                'name' => $interaction->user->name,
            ];
        }

        if ($interaction->relationLoaded('objectClient') && $interaction->objectClient) {
            if ($interaction->objectClient->relationLoaded('contact') && $interaction->objectClient->contact) {
                $result['contact'] = [
                    'id' => $interaction->objectClient->contact->id,
                    'name' => $interaction->objectClient->contact->name,
                ];
            }
            if ($interaction->objectClient->relationLoaded('property') && $interaction->objectClient->property) {
                $result['property'] = [
                    'id' => $interaction->objectClient->property->id,
                    'address' => $interaction->objectClient->property->address,
                ];
            }
        }

        return $result;
    }
}
