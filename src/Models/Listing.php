<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $house
 * @property string|null $address
 * @property string|null $url
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_paid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read Source $source
 * @property-read Category $category
 * @property-read ListingStatus $listingStatus
 * @property-read Location|null $location
 * 
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static Builder|self whereIn($column, $values)
 */
class Listing extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'category_id',
        'listing_status_id',
        'location_id',
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
        'house',
        'address',
        'url',
        'lat',
        'lng',
        'is_paid',
    ];

    protected $casts = [
        'rooms' => 'integer',
        'price' => 'float',
        'square_meters' => 'float',
        'floor' => 'integer',
        'floors_total' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'is_paid' => 'boolean',
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
     * Scope для поиска объявлений в локации
     */
    public function scopeInLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Получить полный адрес объявления
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->city,
            $this->street,
            $this->house
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
     * Получить название локации
     */
    public function getLocationName(): ?string
    {
        if ($this->location) {
            return $this->location->city;
        }
        
        return null;
    }

    /**
     * Обновляет PostGIS point поле на основе lat/lng
     */
    public function updatePointField(): bool
    {
        if (!$this->id || !$this->hasCoordinates()) {
            return false;
        }

        try {
            DB::statement(
                "UPDATE listings SET point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$this->lng, $this->lat, $this->id]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Scope для поиска объявлений внутри полигонов подписки
     */
    public function scopeInSubscriptionPolygons(Builder $query, int $subscriptionId): Builder
    {
        return $query->whereRaw('EXISTS (
            SELECT 1 FROM user_location_polygons p 
            WHERE p.subscription_id = ? 
            AND ST_Contains(p.polygon, listings.point)
        )', [$subscriptionId]);
    }

    /**
     * Scope для поиска объявлений с учётом полигонов (гибридный подход)
     * Если полигоны есть — фильтруем, если нет — возвращаем все
     */
    public function scopeWithPolygonFilter(Builder $query, int $subscriptionId): Builder
    {
        // Проверяем, есть ли полигоны у подписки
        $hasPolygons = DB::table('user_location_polygons')
            ->where('subscription_id', $subscriptionId)
            ->exists();

        if ($hasPolygons) {
            return $query->inSubscriptionPolygons($subscriptionId);
        }

        return $query;
    }
} 