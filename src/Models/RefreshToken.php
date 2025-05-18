<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class RefreshToken
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $device_type
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * 
 * @method static self|null find(int $id)
 * @method static self findOrFail(int $id)
 * @method static self updateOrCreate(array $attributes, array $values = [])
 * @method static self where(string $column, mixed $operator = null, mixed $value = null)
 * @method static self|null first()
 */
class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'device_type',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Пользователь, которому принадлежит токен
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Проверяет, истек ли срок действия токена
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Создать или обновить токен для пользователя и устройства
     */
    public static function createOrUpdateToken(int $userId, string $token, string $deviceType, int $expiresInSeconds): self
    {
        $expiresAt = Carbon::now()->addSeconds($expiresInSeconds);

        return self::updateOrCreate(
            ['user_id' => $userId, 'device_type' => $deviceType],
            ['token' => $token, 'expires_at' => $expiresAt]
        );
    }
} 