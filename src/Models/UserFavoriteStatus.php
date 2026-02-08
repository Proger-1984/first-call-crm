<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * Модель пользовательского статуса для избранных объявлений
 * 
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $color
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read User $user
 * @property-read Collection<UserFavorite> $favorites
 */
class UserFavoriteStatus extends Model
{
    protected $table = 'user_favorite_statuses';
    
    protected $fillable = [
        'user_id',
        'name',
        'color',
        'sort_order',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Пользователь-владелец статуса
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Избранные объявления с этим статусом
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(UserFavorite::class, 'status_id');
    }

    /**
     * Получить все статусы пользователя
     *
     * @param int $userId
     * @return Collection<UserFavoriteStatus>
     */
    public static function getByUser(int $userId): Collection
    {
        return self::where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Создать статус для пользователя
     */
    public static function createForUser(int $userId, string $name, string $color = '#808080'): self
    {
        // Получаем максимальный sort_order для пользователя
        $maxOrder = self::where('user_id', $userId)->max('sort_order') ?? 0;
        
        return self::create([
            'user_id' => $userId,
            'name' => mb_substr(trim($name), 0, 50),
            'color' => $color,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    /**
     * Проверить, принадлежит ли статус пользователю
     */
    public static function belongsToUser(int $statusId, int $userId): bool
    {
        return self::where('id', $statusId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Получить количество избранных с этим статусом
     */
    public function getFavoritesCount(): int
    {
        return $this->favorites()->count();
    }
}
