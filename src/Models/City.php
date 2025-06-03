<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class City
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $name
 * @property int $city_parent_id
 * @property int $location_parent_id
 * @property float|null $lat
 * @property float|null $lng
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read Location $parentLocation
 * @property-read City $parentCity
 * @property-read Collection|City[] $childCities
 * @property-read Collection|Listing[] $listings
 * @property-read Collection|UserSubscription[] $subscriptions
 * 
 * @method static City findOrFail(int $id)
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static Builder|self whereIn($column, $values)
 */
class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'city_parent_id',
        'location_parent_id',
        'lat',
        'lng'
    ];
    
    protected $casts = [
        'lat' => 'float',
        'lng' => 'float'
    ];

    /**
     * Получить родительскую локацию (регион)
     */
    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_parent_id');
    }

    /**
     * Получить родительский город в группе
     * Примечание: для основных городов city_parent_id = id самого города
     */
    public function parentCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_parent_id');
    }

    /**
     * Получить дочерние города в группе
     */
    public function childCities(): HasMany
    {
        return $this->hasMany(City::class, 'city_parent_id');
    }

    /**
     * Получить все объявления в этом городе
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Получить подписки на этот город
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * Проверить, является ли город основным в группе
     */
    public function isMainCity(): bool
    {
        return $this->id === $this->city_parent_id;
    }

    /**
     * Получить основной город группы
     */
    public function getMainCity(): City
    {
        if ($this->isMainCity()) {
            return $this;
        }
        
        return $this->parentCity;
    }

    /**
     * Получить все города в группе (включая основной)
     */
    public function getCityGroup(): Collection
    {
        $mainCityId = $this->isMainCity() ? $this->id : $this->city_parent_id;
        
        return self::where('city_parent_id', $mainCityId)->get();
    }

    /**
     * Получить полное название с указанием родительского города
     */
    public function getFullName(): string
    {
        if ($this->isMainCity()) {
            return $this->name;
        }
        
        $mainCity = $this->getMainCity();
        return "{$this->name} ({$mainCity->name})";
    }

    /**
     * Scope для поиска городов по основной локации
     */
    public function scopeInLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_parent_id', $locationId);
    }

    /**
     * Scope для поиска городов в группе
     */
    public function scopeInCityGroup(Builder $query, int $cityParentId): Builder
    {
        return $query->where('city_parent_id', $cityParentId);
    }

    /**
     * Scope для поиска только основных городов
     */
    public function scopeMainCities(Builder $query): Builder
    {
        return $query->whereRaw('id = city_parent_id');
    }

    /**
     * Статический метод для поиска города по названию
     */
    public static function findByName(string $name, int $locationId = null): ?City
    {
        $query = self::where('name', $name);
        
        if ($locationId) {
            $query->where('location_parent_id', $locationId);
        }
        
        return $query->first();
    }

    /**
     * Статический метод для поиска города по частичному совпадению названия
     */
    public static function findByPartialName(string $partialName, int $locationId = null): Collection
    {
        $query = self::where('name', 'LIKE', "%{$partialName}%");
        
        if ($locationId) {
            $query->where('location_parent_id', $locationId);
        }
        
        return $query->get();
    }
} 