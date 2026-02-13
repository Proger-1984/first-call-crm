<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * Модель объекта недвижимости (главная сущность CRM)
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $listing_id
 * @property string|null $title
 * @property string|null $address
 * @property float|null $price
 * @property int|null $rooms
 * @property float|null $area
 * @property int|null $floor
 * @property int|null $floors_total
 * @property string|null $description
 * @property string|null $url
 * @property string $deal_type
 * @property string|null $owner_name
 * @property string|null $owner_phone
 * @property string|null $owner_phone_secondary
 * @property string|null $source_type
 * @property string|null $source_details
 * @property string|null $comment
 * @property bool $is_archived
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $user
 * @property-read Listing|null $listing
 * @property-read Collection<ObjectClient> $objectClients
 * @property-read Collection<Contact> $contacts
 */
class Property extends Model
{
    /** Типы сделок */
    public const DEAL_TYPE_SALE = 'sale';
    public const DEAL_TYPE_RENT = 'rent';

    /** Все допустимые типы сделок */
    public const ALLOWED_DEAL_TYPES = [
        self::DEAL_TYPE_SALE,
        self::DEAL_TYPE_RENT,
    ];

    protected $table = 'properties';

    protected $fillable = [
        'user_id',
        'listing_id',
        'title',
        'address',
        'price',
        'rooms',
        'area',
        'floor',
        'floors_total',
        'description',
        'url',
        'deal_type',
        'owner_name',
        'owner_phone',
        'owner_phone_secondary',
        'source_type',
        'source_details',
        'comment',
        'is_archived',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'listing_id' => 'integer',
        'price' => 'float',
        'rooms' => 'integer',
        'area' => 'float',
        'floor' => 'integer',
        'floors_total' => 'integer',
        'is_archived' => 'boolean',
    ];

    /**
     * Агент-владелец объекта
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Связанное объявление из парсера (nullable)
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Связки объект+контакт (через промежуточную таблицу)
     */
    public function objectClients(): HasMany
    {
        return $this->hasMany(ObjectClient::class, 'property_id');
    }

    /**
     * Контакты, привязанные к объекту (через object_clients)
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'object_clients', 'property_id', 'contact_id')
            ->withPivot(['id', 'pipeline_stage_id', 'comment', 'next_contact_at', 'last_contact_at'])
            ->withTimestamps();
    }

    /**
     * Скоуп: только активные (не архивные)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Скоуп: только архивные
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * Скоуп: по типу сделки
     */
    public function scopeByDealType(Builder $query, string $dealType): Builder
    {
        return $query->where('deal_type', $dealType);
    }

    /**
     * Скоуп: по пользователю
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
