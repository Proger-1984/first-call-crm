<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель избранного объявления пользователя
 * 
 * @property int $id
 * @property int $user_id
 * @property int $listing_id
 * @property string|null $comment
 * @property int|null $status_id
 * @property Carbon $created_at
 * 
 * @property-read User $user
 * @property-read Listing $listing
 * @property-read UserFavoriteStatus|null $status
 */
class UserFavorite extends Model
{
    protected $table = 'user_favorites';
    
    /**
     * Отключаем updated_at — у нас только created_at
     */
    public $timestamps = false;
    
    protected $fillable = [
        'user_id',
        'listing_id',
        'comment',
        'status_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'listing_id' => 'integer',
        'status_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Пользователь, добавивший в избранное
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Объявление в избранном
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Статус избранного
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(UserFavoriteStatus::class, 'status_id');
    }

    /**
     * Проверить, есть ли объявление в избранном у пользователя
     */
    public static function isFavorite(int $userId, int $listingId): bool
    {
        return self::where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->exists();
    }

    /**
     * Добавить/удалить из избранного (toggle)
     * 
     * @return bool true если добавлено, false если удалено
     */
    public static function toggle(int $userId, int $listingId): bool
    {
        $existing = self::where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->first();

        if ($existing) {
            $existing->delete();
            return false;
        }

        self::create([
            'user_id' => $userId,
            'listing_id' => $listingId,
        ]);
        
        return true;
    }

    /**
     * Получить ID всех избранных объявлений пользователя
     * 
     * @return array<int>
     */
    public static function getFavoriteIds(int $userId): array
    {
        return self::where('user_id', $userId)
            ->pluck('listing_id')
            ->toArray();
    }

    /**
     * Обновить комментарий к избранному
     */
    public static function updateComment(int $userId, int $listingId, ?string $comment): bool
    {
        $favorite = self::where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->first();

        if (!$favorite) {
            return false;
        }

        // Обрезаем до 250 символов
        $comment = $comment ? mb_substr(trim($comment), 0, 250) : null;
        
        $favorite->comment = $comment;
        $favorite->save();
        
        return true;
    }

    /**
     * Получить комментарий к избранному
     */
    public static function getComment(int $userId, int $listingId): ?string
    {
        return self::where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->value('comment');
    }

    /**
     * Обновить статус избранного
     */
    public static function updateStatus(int $userId, int $listingId, ?int $statusId): bool
    {
        $favorite = self::where('user_id', $userId)
            ->where('listing_id', $listingId)
            ->first();

        if (!$favorite) {
            return false;
        }

        // Проверяем, что статус принадлежит пользователю (если указан)
        if ($statusId !== null && !UserFavoriteStatus::belongsToUser($statusId, $userId)) {
            return false;
        }
        
        $favorite->status_id = $statusId;
        $favorite->save();
        
        return true;
    }
}
