<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class User
 *
 * @package App\Models
 *
 * @mixin Model
 * @mixin HasRelationships
 *
 * @property int $id
 * @property string $name
 * @property string $telegram_id
 * @property string|null $telegram_username
 * @property string|null $telegram_photo_url
 * @property int|null $telegram_auth_date
 * @property string|null $telegram_hash
 * @property string $password_hash
 * @property int|null $tariff_id
 * @property Carbon|null $tariff_expires_at
 * @property bool $is_trial_used
 * @property bool $phone_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read UserSettings $settings
 * @property-read Collection|Source[] $sources
 * @property-read Collection|Category[] $categories
 * @property-read Tariff|null $tariff
 * @property-read Collection|RefreshToken[] $refreshTokens
 * 
 * @method static User|null find(int $id)
 * @method static User findOrFail(int $id)
 * @method static Builder where(string $column, mixed $operator = null, mixed $value = null)
 * @method HasOne<UserSettings> settings()
 * @method BelongsTo<Tariff> tariff()
 * @method HasMany<RefreshToken> refreshTokens()
 * @method BelongsToMany<Source> sources()
 */
class User extends Model
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name', 
        'telegram_id', 
        'telegram_username', 
        'telegram_photo_url', 
        'telegram_auth_date', 
        'telegram_hash',
        'password_hash',
        'tariff_id',
        'tariff_expires_at',
        'is_trial_used'
    ];

    protected $hidden = [
        'password_hash',
        'telegram_hash'
    ];

    protected $casts = [
        'is_trial_used' => 'boolean',
        'tariff_expires_at' => 'datetime'
    ];

    /**
     * Настройки пользователя
     * @return HasOne<UserSettings>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * Текущий тариф пользователя
     * @return BelongsTo<Tariff>
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /**
     * Refresh токены пользователя
     * @return HasMany<RefreshToken>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Источники, выбранные пользователем
     * @return BelongsToMany<Source>
     * @method BelongsToMany<Source> sources()
     * @method BelongsToMany<Source> sources() attach(array|int $ids, array $attributes = [], bool $touch = true)
     * @method BelongsToMany<Source> sources() detach(array|int $ids = null, bool $touch = true)
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'user_sources')
                   ->withTimestamps();
    }

    /**
     * Категории, выбранные пользователем
     * @return BelongsToMany<Category>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'user_categories')
                   ->withTimestamps();
    }

    /**
     * Проверяет пароль пользователя
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    /**
     * Хеширует пароль перед сохранением
     */
    public function setPasswordHashAttribute(?string $password): void
    {
        if ($password) {
            $this->attributes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        } else {
            $this->attributes['password_hash'] = null;
        }
    }
    
    /**
     * Создает или обновляет рефреш-токен для указанного типа устройства
     */
    public function createOrUpdateRefreshToken(string $token, string $deviceType, int $expiresInSeconds): RefreshToken
    {
        return RefreshToken::createOrUpdateToken($this->id, $token, $deviceType, $expiresInSeconds);
    }
    
    /**
     * Удаляет все рефреш-токены пользователя
     */
    public function removeAllRefreshTokens(): bool
    {
        return (bool) $this->refreshTokens()->delete();
    }

    /**
     * Проверяет, истек ли срок действия тарифа
     */
    public function isTariffExpired(): bool
    {
        if (!$this->tariff_expires_at) {
            return true;
        }

        return Carbon::now()->isAfter($this->tariff_expires_at);
    }

    /**
     * Проверяет, является ли текущий тариф демо
     */
    public function isDemoTariff(): bool
    {
        return $this->tariff && $this->tariff->isDemo();
    }

    /**
     * Проверяет, является ли текущий тариф премиум
     */
    public function isPremiumTariff(): bool
    {
        return $this->tariff && $this->tariff->isPremium();
    }
} 