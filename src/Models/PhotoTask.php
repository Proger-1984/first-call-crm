<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель задачи на обработку фото
 *
 * @property int $id
 * @property int $listing_id
 * @property int $source_id
 * @property string $external_id
 * @property string $url
 * @property string $status pending|processing|completed|failed
 * @property string|null $error_message
 * @property int $photos_count
 * @property string|null $archive_path
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Listing $listing
 */
class PhotoTask extends Model
{
    protected $table = 'photo_tasks';

    protected $fillable = [
        'listing_id',
        'source_id',
        'external_id',
        'url',
        'status',
        'error_message',
        'photos_count',
        'archive_path',
    ];

    protected $casts = [
        'listing_id' => 'integer',
        'source_id' => 'integer',
        'photos_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Статусы задач
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Объявление
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Проверка: задача в процессе?
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Проверка: задача завершена?
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Проверка: задача провалена?
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Проверка: задача ожидает обработки?
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Получить задачи на обработку
     */
    public static function getPendingTasks(int $limit = 10): Collection
    {
        return self::where('status', self::STATUS_PENDING)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Найти активную задачу для объявления
     */
    public static function findActiveForListing(int $listingId): ?self
    {
        return self::where('listing_id', $listingId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED])
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Обновить статус задачи
     */
    public function updateStatus(string $status, ?string $errorMessage = null): bool
    {
        $this->status = $status;
        if ($errorMessage !== null) {
            $this->error_message = $errorMessage;
        }
        return $this->save();
    }
}
