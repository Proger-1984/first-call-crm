<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Source extends Model
{
    protected $fillable = ['name'];

    /**
     * Пользователи, которые выбрали этот источник
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sources')
                    ->withTimestamps();
    }
} 