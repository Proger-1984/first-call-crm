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
     * Получить полное название локации (город, регион)
     */
    public function getFullName(): string
    {
        return "$this->city, $this->region";
    }
} 