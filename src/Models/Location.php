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
        'region'
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
} 