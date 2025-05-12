<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class Source
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @method static Builder|Source where(string $column, mixed $operator = null, mixed $value = null)
 * @method static Builder|Source whereIn(string $column, array $values)
 * @method static Source|null find(int $id)
 * @method static Source findOrFail(int $id)
 */
class Source extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active'];
    
    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Пользователи, которые выбрали этот источник
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sources')
                    ->withPivot('enabled')
                    ->withTimestamps();
    }
    
    /**
     * Пользователи, у которых этот источник включен
     * @return BelongsToMany<User>
     */
    public function enabledUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('enabled', true);
    }
    
    /**
     * Пользователи, у которых этот источник отключен
     * @return BelongsToMany<User>
     */
    public function disabledUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('enabled', false);
    }
} 