<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class TariffPrice
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $tariff_id
 * @property int $location_id
 * @property float $price
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read Tariff $tariff
 * @property-read Location $location
 */
class TariffPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'tariff_id',
        'location_id',
        'price'
    ];

    protected $casts = [
        'price' => 'float'
    ];

    /**
     * Get the tariff that owns the price
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /**
     * Get the location that owns the price
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
} 