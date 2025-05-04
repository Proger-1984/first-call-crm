<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class User
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $name
 * @property string $telegram_id
 * @property string|null $telegram_username
 * @property string|null $telegram_photo_url
 * @property int|null $telegram_auth_date
 * @property string|null $telegram_hash
 * @property string $password
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @method static User|null find(int $id)
 * @method static User findOrFail(int $id)
 */
class User extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'name', 
        'telegram_id', 
        'telegram_username', 
        'telegram_photo_url', 
        'telegram_auth_date', 
        'telegram_hash',
        'password'
    ];

    protected $hidden = [
        'password',
        'telegram_hash'
    ];

    /**
     * Настройки пользователя
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * Refresh токены пользователя
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Источники, выбранные пользователем
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'user_sources')
                   ->withTimestamps();
    }

    /**
     * Категории, выбранные пользователем
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
        return password_verify($password, $this->password);
    }

    /**
     * Хеширует пароль перед сохранением
     */
    public function setPasswordAttribute(?string $password): void
    {
        if ($password) {
            $this->attributes['password'] = password_hash($password, PASSWORD_DEFAULT);
        } else {
            $this->attributes['password'] = null;
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
} 