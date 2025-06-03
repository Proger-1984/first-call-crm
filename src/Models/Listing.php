<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Listing
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $source_id
 * @property int $category_id
 * @property int $listing_status_id
 * @property int|null $location_id
 * @property int|null $city_id
 * @property string $external_id
 * @property string|null $title
 * @property string|null $description
 * @property int|null $rooms
 * @property float|null $price
 * @property float|null $square_meters
 * @property int|null $floor
 * @property int|null $floors_total
 * @property string|null $phone
 * @property string|null $city
 * @property string|null $street
 * @property string|null $building
 * @property string|null $address
 * @property string|null $url
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_promoted
 * @property bool $is_paid
 * @property Carbon|null $promoted_at
 * @property Carbon|null $auto_call_processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * 
 * @property-read Source $source
 * @property-read Category $category
 * @property-read ListingStatus $listingStatus
 * @property-read Location|null $location
 * @property-read City|null $cityModel
 * 
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static Builder|self whereIn($column, $values)
 */
class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'source_id',
        'category_id',
        'listing_status_id',
        'location_id',
        'city_id',
        'external_id',
        'title',
        'description',
        'rooms',
        'price',
        'square_meters',
        'floor',
        'floors_total',
        'phone',
        'city',
        'street',
        'building',
        'address',
        'url',
        'lat',
        'lng',
        'is_promoted',
        'is_paid',
        'promoted_at',
        'auto_call_processed_at'
    ];

    protected $casts = [
        'rooms' => 'integer',
        'price' => 'float',
        'square_meters' => 'float',
        'floor' => 'integer',
        'floors_total' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'is_promoted' => 'boolean',
        'is_paid' => 'boolean',
        'promoted_at' => 'datetime',
        'auto_call_processed_at' => 'datetime'
    ];

    /**
     * Получить источник объявления
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Получить категорию объявления
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Получить статус объявления
     */
    public function listingStatus(): BelongsTo
    {
        return $this->belongsTo(ListingStatus::class);
    }

    /**
     * Получить основную локацию
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Получить конкретный город (избегаем конфликта с полем city)
     */
    public function cityModel(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * Scope для поиска объявлений в локации
     */
    public function scopeInLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Scope для поиска объявлений в городе
     */
    public function scopeInCity(Builder $query, int $cityId): Builder
    {
        return $query->where('city_id', $cityId);
    }

    /**
     * Scope для поиска объявлений в группе городов
     */
    public function scopeInCityGroup(Builder $query, int $cityParentId): Builder
    {
        return $query->whereHas('cityModel', function($q) use ($cityParentId) {
            $q->where('city_parent_id', $cityParentId);
        });
    }

    /**
     * Получить полный адрес объявления
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->city,
            $this->street,
            $this->building
        ]);
        
        return implode(', ', $parts) ?: ($this->address ?: 'Адрес не указан');
    }

    /**
     * Проверить, есть ли координаты
     */
    public function hasCoordinates(): bool
    {
        return !is_null($this->lat) && !is_null($this->lng);
    }

    /**
     * Получить нормализованное название города
     */
    public function getNormalizedCityName(): ?string
    {
        if ($this->cityModel) {
            return $this->cityModel->name;
        }
        
        return $this->city;
    }

    /**
     * Получить название локации
     */
    public function getLocationName(): ?string
    {
        if ($this->location) {
            return $this->location->city;
        }
        
        return null;
    }
} 