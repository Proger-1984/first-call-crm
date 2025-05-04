<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $fillable = ['name'];

    /**
     * Пользователи, которые выбрали эту категорию
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_categories')
                    ->withTimestamps();
    }
} 