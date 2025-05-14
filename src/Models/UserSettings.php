<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class UserSettings
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property bool $log_events
 * @property bool $auto_call
 * @property bool $telegram_notifications
 * @property Carbon $created_at
 * @method static UserSettings firstOrNew(array $data)
 * @property Carbon $updated_at
 */
class UserSettings extends Model
{
    protected $fillable = [
        'user_id',
        'log_events',
        'auto_call',
        'telegram_notifications'
    ];

    protected $casts = [
        'log_events' => 'boolean',
        'auto_call' => 'boolean',
        'telegram_notifications' => 'boolean'
    ];

    /**
     * Пользователь, которому принадлежат настройки
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
} 