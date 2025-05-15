<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

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
     * Get the tariff prices for this location
     */
    public function tariffPrices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    /**
     * Get the user subscriptions for this location
     */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }
    
    /**
     * Get the full location name (city, region)
     */
    public function getFullName(): string
    {
        return "{$this->city}, {$this->region}";
    }
    
    /**
     * Проверяет, находится ли точка внутри границ локации (по грубым границам)
     * 
     * @param float $lat Широта
     * @param float $lng Долгота
     * @return bool
     */
    public function containsPointInBounds(float $lat, float $lng): bool
    {
        if (!$this->bounds) {
            return false;
        }
        
        return $lat >= $this->bounds['south'] && 
               $lat <= $this->bounds['north'] && 
               $lng >= $this->bounds['west'] && 
               $lng <= $this->bounds['east'];
    }
    
    /**
     * Получает расстояние от центра локации до указанной точки (в метрах)
     * 
     * @param float $lat Широта
     * @param float $lng Долгота
     * @return float|null Расстояние в метрах или null, если центр не задан
     */
    public function getDistanceToPoint(float $lat, float $lng): ?float
    {
        if (!$this->center_point) {
            return null;
        }
        
        // Вычисляем расстояние с использованием функции ST_Distance
        $result = DB::selectOne(
            "SELECT ST_Distance(
                center_point::geography, 
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            ) AS distance",
            [$lng, $lat] // PostGIS порядок координат (долгота, широта)
        );
        
        return $result ? (float)$result->distance : null;
    }
} 