<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * Модель контакта (покупатель/арендатор)
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $phone_secondary
 * @property string|null $email
 * @property string|null $telegram_username
 * @property string|null $comment
 * @property bool $is_archived
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $user
 * @property-read Collection<ObjectClient> $objectClients
 * @property-read Collection<Property> $properties
 */
class Contact extends Model
{
    protected $table = 'contacts';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'phone_secondary',
        'email',
        'telegram_username',
        'comment',
        'is_archived',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'is_archived' => 'boolean',
    ];

    /**
     * Агент-владелец контакта
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Связки контакт+объект
     */
    public function objectClients(): HasMany
    {
        return $this->hasMany(ObjectClient::class, 'contact_id');
    }

    /**
     * Объекты, к которым привязан контакт (через object_clients)
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'object_clients', 'contact_id', 'property_id')
            ->withPivot(['id', 'pipeline_stage_id', 'comment', 'next_contact_at', 'last_contact_at'])
            ->withTimestamps();
    }

    /**
     * Скоуп: только активные (не архивные)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Скоуп: только архивные
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }
}
