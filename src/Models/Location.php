<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Location
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $city
 * @property string $region
 * @property float|null $center_lat
 * @property float|null $center_lng
 * @property array|null $bounds
 * @property string|null $center_point
 * @property string|null $bounds_polygon
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read Collection|TariffPrice[] $tariffPrices
 * @property-read Collection|UserSubscription[] $userSubscriptions
 * @property-read Collection|City[] $cities
 * @method static Location findOrFail(int $id)
 * @method static Builder|self where($column, $operator = null, $value = null)
 */
class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'city',
        'region',
        'center_lat',
        'center_lng',
        'bounds'
    ];
    
    protected $casts = [
        'bounds' => 'array',
        'center_lat' => 'float',
        'center_lng' => 'float'
    ];

    /**
     * Получить цены тарифов для этой локации
     */
    public function tariffPrices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    /**
     * Получить подписки пользователей для этой локации
     */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * Получить все города в этой локации
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'location_parent_id');
    }

    /**
     * Получить только основные города в локации
     */
    public function mainCities(): HasMany
    {
        return $this->cities()->whereRaw('id = city_parent_id');
    }
    
    /**
     * Получить полное название локации (город, регион)
     */
    public function getFullName(): string
    {
        return "$this->city, $this->region";
    }

    /**
     * Получить все объявления в этой локации (через города)
     */
    public function getAllListings(): Collection
    {
        $cityIds = $this->cities()->pluck('id')->toArray();
        
        if (empty($cityIds)) {
            return collect();
        }
        
        return Listing::whereIn('city_id', $cityIds)->get();
    }

    /**
     * Получить количество объявлений в локации
     */
    public function getListingsCount(): int
    {
        $cityIds = $this->cities()->pluck('id')->toArray();
        
        if (empty($cityIds)) {
            return 0;
        }
        
        return Listing::whereIn('city_id', $cityIds)->count();
    }

    /**
     * Получить статистику по группам городов
     */
    public function getCityGroupsStats(): array
    {
        $stats = [];
        $mainCities = $this->mainCities()->get();
        
        foreach ($mainCities as $mainCity) {
            $groupCities = $this->cities()->where('city_parent_id', $mainCity->id)->get();
            $listingsCount = Listing::whereIn('city_id', $groupCities->pluck('id'))->count();
            
            $stats[] = [
                'main_city' => $mainCity->name,
                'cities_count' => $groupCities->count(),
                'listings_count' => $listingsCount,
                'cities' => $groupCities->pluck('name')->toArray()
            ];
        }
        
        return $stats;
    }
} 