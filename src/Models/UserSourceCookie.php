<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель для хранения кук авторизации пользователей на источниках (CIAN, Avito)
 * 
 * @property int $id
 * @property int $user_id
 * @property string $source_type
 * @property string|null $cookies
 * @property bool $is_valid
 * @property array|null $subscription_info
 * @property Carbon|null $last_validated_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User $user
 */
class UserSourceCookie extends Model
{
    protected $table = 'user_source_cookies';

    protected $fillable = [
        'user_id',
        'source_type',
        'cookies',
        'is_valid',
        'subscription_info',
        'last_validated_at',
        'expires_at',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'subscription_info' => 'array',
        'last_validated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Связь с пользователем
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить куки пользователя для источника
     */
    public static function getForUser(int $userId, string $sourceType): ?self
    {
        return self::where('user_id', $userId)
            ->where('source_type', $sourceType)
            ->first();
    }

    /**
     * Сохранить или обновить куки пользователя
     */
    public static function saveCookies(
        int $userId,
        string $sourceType,
        string $cookies,
        bool $isValid = false,
        ?array $subscriptionInfo = null,
        ?Carbon $expiresAt = null
    ): self {
        return self::updateOrCreate(
            [
                'user_id' => $userId,
                'source_type' => $sourceType,
            ],
            [
                'cookies' => $cookies,
                'is_valid' => $isValid,
                'subscription_info' => $subscriptionInfo,
                'last_validated_at' => Carbon::now(),
                'expires_at' => $expiresAt,
            ]
        );
    }

    /**
     * Пометить куки как невалидные
     */
    public function markAsInvalid(): bool
    {
        $this->is_valid = false;
        $this->last_validated_at = Carbon::now();
        return $this->save();
    }

    /**
     * Пометить куки как валидные
     */
    public function markAsValid(?array $subscriptionInfo = null, ?Carbon $expiresAt = null): bool
    {
        $this->is_valid = true;
        $this->last_validated_at = Carbon::now();
        
        if ($subscriptionInfo !== null) {
            $this->subscription_info = $subscriptionInfo;
        }
        
        if ($expiresAt !== null) {
            $this->expires_at = $expiresAt;
        }
        
        return $this->save();
    }

    /**
     * Проверить, истекли ли куки
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }
        
        return $this->expires_at->isPast();
    }

    /**
     * Получить информацию о тарифе
     */
    public function getTariffName(): ?string
    {
        return $this->subscription_info['tariff'] ?? null;
    }

    /**
     * Получить информацию о лимите
     */
    public function getLimitInfo(): ?string
    {
        return $this->subscription_info['limit_info'] ?? null;
    }

    /**
     * Получить текст срока действия
     */
    public function getExpireText(): ?string
    {
        return $this->subscription_info['expire_text'] ?? null;
    }

    /**
     * Получить телефон из подписки
     */
    public function getPhone(): ?string
    {
        return $this->subscription_info['phone'] ?? null;
    }
}
