<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель привязки объявления к клиенту (подборка)
 *
 * @property int $id
 * @property int $client_id
 * @property int $listing_id
 * @property string $status
 * @property string|null $comment
 * @property Carbon|null $showed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Client $client
 * @property-read Listing $listing
 */
class ClientListing extends Model
{
    /** Статусы привязки */
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_SHOWED = 'showed';
    public const STATUS_LIKED = 'liked';
    public const STATUS_REJECTED = 'rejected';

    /** Все допустимые статусы */
    public const ALLOWED_STATUSES = [
        self::STATUS_PROPOSED,
        self::STATUS_SHOWED,
        self::STATUS_LIKED,
        self::STATUS_REJECTED,
    ];

    protected $table = 'client_listings';

    protected $fillable = [
        'client_id',
        'listing_id',
        'status',
        'comment',
        'showed_at',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'listing_id' => 'integer',
        'showed_at' => 'datetime',
    ];

    /**
     * Клиент
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Объявление
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
