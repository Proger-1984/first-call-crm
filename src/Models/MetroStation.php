<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class MetroStation
 * 
 * Модель станции метро
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $location_id
 * @property string $name
 * @property string|null $line
 * @property string|null $line_id ID линии из API hh.ru (например: 137)
 * @property string|null $station_id ID станции из API hh.ru (например: 137.961)
 * @property string|null $color
 * @property float|null $lat
 * @property float|null $lng
 * 
 * @property-read Location $location
 * @property-read \Illuminate\Database\Eloquent\Collection|Listing[] $listings
 * 
 * @method static Builder|self query()
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static MetroStation|null find(int $id)
 * @method static MetroStation findOrFail(int $id)
 */
class MetroStation extends Model
{
    protected $table = 'metro_stations';

    public $timestamps = false;

    protected $fillable = [
        'location_id',
        'name',
        'line',
        'line_id',
        'station_id',
        'color',
        'lat',
        'lng',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * Локация (город), к которой относится станция
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Объявления рядом с этой станцией метро
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_metro', 'metro_station_id', 'listing_id')
            ->withPivot(['travel_time_min', 'travel_type'])
            ->withTimestamps();
    }
}
