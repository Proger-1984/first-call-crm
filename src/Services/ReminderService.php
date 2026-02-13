<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ObjectClient;
use App\Models\Reminder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Сервис работы с напоминаниями CRM
 */
class ReminderService
{
    /**
     * Получить все напоминания пользователя (неотправленные, будущие)
     *
     * @param int $userId ID пользователя
     * @return Collection<Reminder>
     */
    public function getByUser(int $userId): Collection
    {
        return Reminder::where('user_id', $userId)
            ->where('is_sent', false)
            ->where('remind_at', '>', Carbon::now())
            ->with(['objectClient.property', 'objectClient.contact', 'objectClient.pipelineStage'])
            ->orderBy('remind_at', 'asc')
            ->get();
    }

    /**
     * Получить напоминания по связке объект+контакт
     *
     * @param int $propertyId ID объекта
     * @param int $contactId  ID контакта
     * @param int $userId     ID пользователя
     * @return Collection<Reminder>
     */
    public function getByObjectClient(int $propertyId, int $contactId, int $userId): Collection
    {
        $objectClient = ObjectClient::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->whereHas('property', fn($query) => $query->where('user_id', $userId))
            ->first();

        if (!$objectClient) {
            return new Collection();
        }

        return Reminder::where('object_client_id', $objectClient->id)
            ->where('is_sent', false)
            ->orderBy('remind_at', 'asc')
            ->get();
    }

    /**
     * Создать напоминание
     *
     * @param int    $propertyId ID объекта
     * @param int    $contactId  ID контакта
     * @param int    $userId     ID пользователя
     * @param array  $data       Данные: remind_at, message
     * @return array Форматированное напоминание
     * @throws \RuntimeException
     */
    public function create(int $propertyId, int $contactId, int $userId, array $data): array
    {
        $objectClient = ObjectClient::where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->whereHas('property', fn($query) => $query->where('user_id', $userId))
            ->first();

        if (!$objectClient) {
            throw new \RuntimeException('Связка объект+контакт не найдена');
        }

        $remindAt = Carbon::parse($data['remind_at']);
        if ($remindAt->isPast()) {
            throw new \RuntimeException('Дата напоминания должна быть в будущем');
        }

        $reminder = Reminder::create([
            'object_client_id' => $objectClient->id,
            'user_id' => $userId,
            'remind_at' => $remindAt,
            'message' => trim($data['message']),
        ]);

        $reminder->load(['objectClient.property', 'objectClient.contact']);

        return $this->formatReminder($reminder);
    }

    /**
     * Удалить напоминание
     *
     * @param int $reminderId ID напоминания
     * @param int $userId     ID пользователя
     * @return bool
     * @throws \RuntimeException
     */
    public function delete(int $reminderId, int $userId): bool
    {
        $reminder = Reminder::where('id', $reminderId)
            ->where('user_id', $userId)
            ->first();

        if (!$reminder) {
            throw new \RuntimeException('Напоминание не найдено');
        }

        return (bool) $reminder->delete();
    }

    /**
     * Получить неотправленные напоминания, время которых наступило
     *
     * @return Collection<Reminder>
     */
    public function getPendingReminders(): Collection
    {
        return Reminder::pending()
            ->whereNull('sent_at')
            ->with([
                'objectClient.property',
                'objectClient.contact',
                'objectClient.pipelineStage',
                'user',
            ])
            ->orderBy('remind_at', 'asc')
            ->get();
    }

    /**
     * Атомарно пометить напоминание как отправленное
     *
     * @param int $reminderId ID напоминания
     * @return bool Удалось ли пометить (false = другой процесс уже обработал)
     */
    public function markAsSent(int $reminderId): bool
    {
        $updated = Reminder::where('id', $reminderId)
            ->whereNull('sent_at')
            ->update([
                'is_sent' => true,
                'sent_at' => Carbon::now(),
            ]);

        return $updated > 0;
    }

    /**
     * Форматировать напоминание для API-ответа
     *
     * @param Reminder $reminder
     * @return array
     */
    public function formatReminder(Reminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'object_client_id' => $reminder->object_client_id,
            'user_id' => $reminder->user_id,
            'remind_at' => $reminder->remind_at?->toISOString(),
            'message' => $reminder->message,
            'is_sent' => $reminder->is_sent,
            'sent_at' => $reminder->sent_at?->toISOString(),
            'property' => $reminder->objectClient?->property ? [
                'id' => $reminder->objectClient->property->id,
                'address' => $reminder->objectClient->property->address,
                'title' => $reminder->objectClient->property->title,
            ] : null,
            'contact' => $reminder->objectClient?->contact ? [
                'id' => $reminder->objectClient->contact->id,
                'name' => $reminder->objectClient->contact->name,
                'phone' => $reminder->objectClient->contact->phone,
            ] : null,
            'pipeline_stage' => $reminder->objectClient?->pipelineStage ? [
                'id' => $reminder->objectClient->pipelineStage->id,
                'name' => $reminder->objectClient->pipelineStage->name,
                'color' => $reminder->objectClient->pipelineStage->color,
            ] : null,
            'created_at' => $reminder->created_at?->toISOString(),
        ];
    }
}
