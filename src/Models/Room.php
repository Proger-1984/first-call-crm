<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class Room
 * 
 * Модель типа комнат (Студия, 1-комн, 2-комн и т.д.)
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection|Listing[] $listings
 * @property-read \Illuminate\Database\Eloquent\Collection|Category[] $categories
 * 
 * @method static Builder|self query()
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static Room|null find(int $id)
 * @method static Room findOrFail(int $id)
 */
class Room extends Model
{
    protected $table = 'rooms';

    protected $fillable = [
        'name',
        'code',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Объявления с этим типом комнат
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'room_id');
    }

    /**
     * Категории, для которых доступен этот тип комнат
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_rooms', 'room_id', 'category_id');
    }
}
