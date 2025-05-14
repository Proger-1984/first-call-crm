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
 * @property bool $is_trial_used
 * @property bool $phone_status
 * @property string $role
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read UserSettings $settings
 * @property-read Collection|Source[] $sources
 * @property-read Collection|UserSubscription[] $subscriptions
 * @property-read Collection|UserSubscription[] $activeSubscriptions
 * @property-read Collection|SubscriptionHistory[] $subscriptionHistory
 * @property-read Collection|RefreshToken[] $refreshTokens
 * 
 * @method static User|null find(int $id)
 * @method static User findOrFail(int $id)
 * @method static Builder where(string $column, mixed $operator = null, mixed $value = null)
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
        'is_trial_used',
        'role'
    ];

    protected $hidden = [
        'password_hash',
        'telegram_hash'
    ];

    protected $casts = [
        'is_trial_used' => 'boolean',
        'phone_status' => 'boolean'
    ];

    /**
     * Обработчик события создания модели
     * Автоматически добавляет все источники для нового пользователя
     */
    protected static function booted()
    {
        static::created(function ($user) {
            $user->addAllSources();
        });
    }

    /**
     * Добавляет все доступные источники для пользователя
     * с установленным флагом enabled = true
     * @noinspection PhpUnused
     */
    public function addAllSources(): void
    {
        $sources = Source::where('is_active', true)->get();

        $sourcesToAttach = [];
        foreach ($sources as $source) {
            $sourcesToAttach[$source->id] = ['enabled' => true];
        }

        if (!empty($sourcesToAttach)) {
            $this->sources()->sync($sourcesToAttach);
        }
    }

    /**
     * Настройки пользователя
     * @return HasOne<UserSettings>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * Подписки пользователя
     * @return HasMany<UserSubscription>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * История подписок пользователя
     * @return HasMany<SubscriptionHistory>
     */
    public function subscriptionHistory(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    /**
     * Активные подписки пользователя
     * @return HasMany<UserSubscription>
     */
    public function activeSubscriptions(): HasMany
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('end_date', '>', Carbon::now())
            ->with(['category', 'location']);
    }

    /**
     * Подписки пользователя, ожидающие подтверждения
     * @return HasMany<UserSubscription>
     */
    public function pendingSubscriptions(): HasMany
    {
        return $this->subscriptions()->where('status', 'pending');
    }

    /**
     * Истекшие подписки пользователя
     * @return HasMany<UserSubscription>
     */
    public function expiredSubscriptions(): HasMany
    {
        return $this->subscriptions()->where('status', 'expired')
                     ->orWhere(function ($query) {
                         $query->where('status', 'active')
                               ->where('end_date', '<', Carbon::now());
                     });
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
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'user_sources')
                    ->withPivot('enabled')
                    ->withTimestamps();
    }

    /**
     * Активные (включенные) источники пользователя
     * @return BelongsToMany<Source>
     */
    public function enabledSources(): BelongsToMany
    {
        return $this->sources()->wherePivot('enabled', true);
    }

    /**
     * Отключенные источники пользователя
     * @return BelongsToMany<Source>
     */
    public function disabledSources(): BelongsToMany
    {
        return $this->sources()->wherePivot('enabled', false);
    }

    /**
     * Включает источник для пользователя
     */
    public function enableSource(int $sourceId): bool
    {
        if ($this->sources()->where('source_id', $sourceId)->exists()) {
            return (bool) $this->sources()->updateExistingPivot($sourceId, ['enabled' => true]);
        } else {
            $this->sources()->attach($sourceId, ['enabled' => true]);
            return true;
        }
    }

    /**
     * Отключает источник для пользователя
     */
    public function disableSource(int $sourceId): bool
    {
        if ($this->sources()->where('source_id', $sourceId)->exists()) {
            return (bool) $this->sources()->updateExistingPivot($sourceId, ['enabled' => false]);
        }
        return false;
    }

    /**
     * Включает несколько источников
     */
    public function enableSources(array $sourceIds): void
    {
        foreach ($sourceIds as $sourceId) {
            $this->enableSource($sourceId);
        }
    }

    /**
     * Отключает несколько источников
     */
    public function disableSources(array $sourceIds): void
    {
        foreach ($sourceIds as $sourceId) {
            $this->disableSource($sourceId);
        }
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
     * Проверяет, есть ли у пользователя активная подписка для указанной категории и локации
     */
    public function hasActiveSubscription(int $categoryId, int $locationId): bool
    {
        return $this->activeSubscriptions()
                    ->where('category_id', $categoryId)
                    ->where('location_id', $locationId)
                    ->exists();
    }
    
    /**
     * Проверяет, есть ли у пользователя хотя бы одна активная подписка
     */
    public function hasAnyActiveSubscription(): bool
    {
        return $this->activeSubscriptions()->exists();
    }
    
    /**
     * Проверяет, есть ли у пользователя активная демо-подписка
     */
    public function hasActiveDemoSubscription(): bool
    {
        return $this->activeSubscriptions()
                    ->whereHas('tariff', function($query) {
                        $query->where('code', 'demo');
                    })
                    ->exists();
    }
    
    /**
     * Проверяет, ожидает ли подтверждения подписка пользователя для указанной категории и локации
     */
    public function hasPendingSubscription(int $categoryId, int $locationId): bool
    {
        return $this->pendingSubscriptions()
                    ->where('category_id', $categoryId)
                    ->where('location_id', $locationId)
                    ->exists();
    }

    /**
     * Проверяет, использовал ли пользователь демо-режим
     */
    public function hasUsedTrial(): bool
    {
        return $this->is_trial_used;
    }
    
    /**
     * Проверяет, является ли пользователь администратором
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Получить ID пользователя
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Получить Telegram ID пользователя
     */
    public function getTelegramId(): string
    {
        return $this->telegram_id;
    }

    /**
     * Создает заявку на подписку (ожидающую подтверждения администратором)
     * Для демо-тарифа подписка активируется автоматически
     * 
     * @param int $tariffId ID тарифа
     * @param int $categoryId ID категории
     * @param int $locationId ID локации
     * @return UserSubscription Созданная подписка
     */
    public function requestSubscription(int $tariffId, int $categoryId, int $locationId): UserSubscription
    {
        // Получаем тариф и проверяем доступность
        $tariff = Tariff::findOrFail($tariffId);
        
        // Проверка для демо-тарифа - доступен только если пользователь еще не использовал демо
        if ($tariff->isDemo() && $this->is_trial_used) {
            throw new \Exception('Вы уже использовали демо-тариф ранее');
        }
        
        // Проверка для демо-тарифа - только одна категория и локация
        if ($tariff->isDemo() && $this->pendingSubscriptions()->whereHas('tariff', function($query) {
                $query->where('code', 'demo');
            })->exists()) {
            throw new \Exception('Для демо-тарифа доступна только одна категория и локация');
        }
        
        // Получаем стандартную цену
        $tariffPrice = TariffPrice::where('tariff_id', $tariffId)
                                 ->where('location_id', $locationId)
                                 ->first();
        $price = $tariffPrice ? $tariffPrice->price : $tariff->price;
        
        // Для демо-тарифа сразу устанавливаем даты и активируем
        $status = $tariff->isDemo() ? 'active' : 'pending';
        $startDate = $tariff->isDemo() ? Carbon::now() : null;
        $endDate = $tariff->isDemo() ? Carbon::now()->addHours($tariff->duration_hours) : null;
        
        // Создаем подписку
        $subscription = UserSubscription::create([
            'user_id' => $this->id,
            'tariff_id' => $tariffId,
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'price_paid' => $price,
            'status' => $status,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        // Записываем в историю
        $category = Category::findOrFail($categoryId);
        $location = Location::findOrFail($locationId);
        
        $action = $tariff->isDemo() ? 'created' : 'requested';
        $notes = $tariff->isDemo() ? 'Демо-подписка активирована автоматически' : 'Пользователь запросил подписку';
        
        SubscriptionHistory::create([
            'user_id' => $this->id,
            'subscription_id' => $subscription->id,
            'action' => $action,
            'tariff_name' => $tariff->name,
            'category_name' => $category->name,
            'location_name' => $location->getFullName(),
            'price_paid' => $price,
            'action_date' => Carbon::now(),
            'notes' => $notes
        ]);
        
        // Если это демо-тариф, отмечаем, что пользователь использовал демо
        if ($tariff->isDemo()) {
            $this->is_trial_used = true;
            $this->save();
        }
        
        return $subscription;
    }
} 