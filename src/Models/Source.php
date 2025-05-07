<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class Source
 *
 * @package App\Models
 *
 * @mixin Model
 * @mixin HasAttributes
 * @mixin HasRelationships
 *
 * @property int $id
 * @property string $name
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @method static Builder|Source where(string $column, mixed $operator = null, mixed $value = null)
 * @method static Builder|Source whereIn(string $column, array $values)
 * @method static Source|null find(int $id)
 * @method static Source findOrFail(int $id)
 */
class Source extends Model
{
    use HasFactory;
    use HasAttributes;
    use HasRelationships;

    protected $fillable = ['name'];

    /**
     * Пользователи, которые выбрали этот источник
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sources')
                    ->withTimestamps();
    }
} 