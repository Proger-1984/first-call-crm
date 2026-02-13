<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель взаимодействия (таймлайн)
 *
 * Фиксирует все события по связке объект+контакт:
 * звонки, встречи, показы, заметки, автоматические записи о смене стадии.
 *
 * @property int $id
 * @property int $object_client_id
 * @property int $user_id
 * @property string $type
 * @property string|null $description
 * @property array|null $metadata
 * @property Carbon $interaction_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read ObjectClient $objectClient
 * @property-read User $user
 */
class Interaction extends Model
{
    // Типы взаимодействий
    public const TYPE_CALL = 'call';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_SHOWING = 'showing';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_NOTE = 'note';
    public const TYPE_STAGE_CHANGE = 'stage_change';

    /** Все допустимые типы */
    public const ALLOWED_TYPES = [
        self::TYPE_CALL,
        self::TYPE_MEETING,
        self::TYPE_SHOWING,
        self::TYPE_MESSAGE,
        self::TYPE_NOTE,
        self::TYPE_STAGE_CHANGE,
    ];

    /** Типы, которые пользователь может создать вручную */
    public const MANUAL_TYPES = [
        self::TYPE_CALL,
        self::TYPE_MEETING,
        self::TYPE_SHOWING,
        self::TYPE_MESSAGE,
        self::TYPE_NOTE,
    ];

    protected $table = 'interactions';

    protected $fillable = [
        'object_client_id',
        'user_id',
        'type',
        'description',
        'metadata',
        'interaction_at',
    ];

    protected $casts = [
        'object_client_id' => 'integer',
        'user_id' => 'integer',
        'metadata' => 'array',
        'interaction_at' => 'datetime',
    ];

    /**
     * Связка объект+контакт
     */
    public function objectClient(): BelongsTo
    {
        return $this->belongsTo(ObjectClient::class);
    }

    /**
     * Пользователь, создавший запись
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
