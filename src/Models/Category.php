<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class Category
 * 
 * @package App\Models
 * 
 * @property int $id
 * @property string $name
 * 
 * @method static Builder|self query()
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static Builder|self whereIn($column, $values)
 * @method static Category findOrFail($id)
 */
class Category extends Model
{
    protected $fillable = ['name'];

    /**
     * Подписки, связанные с этой категорией
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }
} 