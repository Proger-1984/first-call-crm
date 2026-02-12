<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель критериев поиска клиента
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $category_id
 * @property int|null $location_id
 * @property array|null $room_ids
 * @property float|null $price_min
 * @property float|null $price_max
 * @property float|null $area_min
 * @property float|null $area_max
 * @property int|null $floor_min
 * @property int|null $floor_max
 * @property array|null $metro_ids
 * @property array|null $districts
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Client $client
 * @property-read Category|null $category
 * @property-read Location|null $location
 */
class ClientSearchCriteria extends Model
{
    protected $table = 'client_search_criteria';

    protected $fillable = [
        'client_id',
        'category_id',
        'location_id',
        'room_ids',
        'price_min',
        'price_max',
        'area_min',
        'area_max',
        'floor_min',
        'floor_max',
        'metro_ids',
        'districts',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'category_id' => 'integer',
        'location_id' => 'integer',
        'room_ids' => 'array',
        'price_min' => 'float',
        'price_max' => 'float',
        'area_min' => 'float',
        'area_max' => 'float',
        'floor_min' => 'integer',
        'floor_max' => 'integer',
        'metro_ids' => 'array',
        'districts' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Клиент-владелец критерия
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Категория недвижимости
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Локация (город/регион)
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
