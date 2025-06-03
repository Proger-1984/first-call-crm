<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class ListingStatus
 * 
 * @package App\Models
 * 
 * @property int $id
 * @property string $name
 * @property string|null $description
 * 
 * @property-read Collection|Listing[] $listings
 * @method static Builder|self query()
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static ListingStatus findOrFail($id)
 */
class ListingStatus extends Model
{
    protected $table = 'listing_statuses';

    protected $fillable = ['name', 'description'];

    public $timestamps = false;

    /**
     * Объявления с этим статусом
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
} 