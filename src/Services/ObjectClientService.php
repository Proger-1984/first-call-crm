<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\ObjectClient;
use App\Models\PipelineStage;
use App\Models\Property;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Сервис бизнес-логики CRM: связки объект+контакт
 */
class ObjectClientService
{
    /**
     * Привязать контакт к объекту
     *
     * @param int $propertyId
     * @param int $contactId
     * @param int $userId
     * @param int|null $stageId Стадия (если не указана — берём первую)
     * @return ObjectClient
     */
    public function attachContact(int $propertyId, int $contactId, int $userId, ?int $stageId = null): ObjectClient
    {
        // Проверяем, что объект принадлежит пользователю
        $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
        if (!$property) {
            throw new InvalidArgumentException('Объект не найден');
        }

        // Проверяем, что контакт принадлежит пользователю
        $contact = Contact::where('id', $contactId)->where('user_id', $userId)->first();
        if (!$contact) {
            throw new InvalidArgumentException('Контакт не найден');
        }

        // Проверяем уникальность связки
        $existing = ObjectClient::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException('Контакт уже привязан к этому объекту');
        }

        // Определяем стадию
        if ($stageId) {
            if (!PipelineStage::belongsToUser($stageId, $userId)) {
                throw new InvalidArgumentException('Стадия воронки не найдена');
            }
        } else {
            $firstStage = PipelineStage::getFirstStage($userId);
            if (!$firstStage) {
                throw new InvalidArgumentException('Не удалось создать стадии воронки');
            }
            $stageId = $firstStage->id;
        }

        $objectClient = ObjectClient::create([
            'property_id' => $propertyId,
            'contact_id' => $contactId,
            'pipeline_stage_id' => $stageId,
        ]);

        $objectClient->load(['contact', 'pipelineStage', 'property']);

        return $objectClient;
    }

    /**
     * Отвязать контакт от объекта
     *
     * @param int $propertyId
     * @param int $contactId
     * @param int $userId
     * @return bool
     */
    public function detachContact(int $propertyId, int $contactId, int $userId): bool
    {
        // Проверяем владельца объекта
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

        return (bool)$objectClient->delete();
    }

    /**
     * Переместить связку на другую стадию
     *
     * @param int $propertyId
     * @param int $contactId
     * @param int $stageId
     * @param int $userId
     * @return ObjectClient
     */
    public function moveToStage(int $propertyId, int $contactId, int $stageId, int $userId): ObjectClient
    {
        // Проверяем владельца объекта
        $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
        if (!$property) {
            throw new InvalidArgumentException('Объект не найден');
        }

        if (!PipelineStage::belongsToUser($stageId, $userId)) {
            throw new InvalidArgumentException('Стадия воронки не найдена');
        }

        $objectClient = ObjectClient::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->first();

        if (!$objectClient) {
            throw new InvalidArgumentException('Связка не найдена');
        }

        $objectClient->update(['pipeline_stage_id' => $stageId]);
        $objectClient->load(['contact', 'pipelineStage', 'property']);

        return $objectClient;
    }

    /**
     * Обновить связку (комментарий, даты контакта)
     *
     * @param int $propertyId
     * @param int $contactId
     * @param int $userId
     * @param array $data
     * @return ObjectClient
     */
    public function updateObjectClient(int $propertyId, int $contactId, int $userId, array $data): ObjectClient
    {
        // Проверяем владельца объекта
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

        $updateData = [];

        if (array_key_exists('comment', $data)) {
            $updateData['comment'] = $data['comment'];
        }
        if (array_key_exists('next_contact_at', $data)) {
            $updateData['next_contact_at'] = $data['next_contact_at']
                ? $this->safeParseDate($data['next_contact_at']) : null;
        }
        if (array_key_exists('last_contact_at', $data)) {
            $updateData['last_contact_at'] = $data['last_contact_at']
                ? $this->safeParseDate($data['last_contact_at']) : null;
        }

        if (!empty($updateData)) {
            $objectClient->update($updateData);
        }

        $objectClient->load(['contact', 'pipelineStage', 'property']);
        return $objectClient;
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
