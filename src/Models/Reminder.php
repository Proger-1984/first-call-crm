<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель напоминания
 *
 * Агент создаёт напоминание по связке объект+контакт.
 * Cron-команда проверяет каждые 5 минут и отправляет Telegram-уведомление.
 *
 * @property int $id
 * @property int $object_client_id
 * @property int $user_id
 * @property Carbon $remind_at
 * @property string $message
 * @property bool $is_sent
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read ObjectClient $objectClient
 * @property-read User $user
 */
class Reminder extends Model
{
    protected $table = 'reminders';

    protected $fillable = [
        'object_client_id',
        'user_id',
        'remind_at',
        'message',
        'is_sent',
        'sent_at',
    ];

    protected $casts = [
        'object_client_id' => 'integer',
        'user_id' => 'integer',
        'remind_at' => 'datetime',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /**
     * Связка объект+контакт
     */
    public function objectClient(): BelongsTo
    {
        return $this->belongsTo(ObjectClient::class);
    }

    /**
     * Пользователь, создавший напоминание
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Скоуп: неотправленные напоминания, время которых наступило
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->where('is_sent', false)
            ->where('remind_at', '<=', Carbon::now());
    }
}
