<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель связки Объект + Контакт (здесь живёт воронка)
 *
 * @property int $id
 * @property int $property_id
 * @property int $contact_id
 * @property int $pipeline_stage_id
 * @property string|null $comment
 * @property Carbon|null $next_contact_at
 * @property Carbon|null $last_contact_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Property $property
 * @property-read Contact $contact
 * @property-read PipelineStage $pipelineStage
 * @property-read \Illuminate\Database\Eloquent\Collection|Interaction[] $interactions
 */
class ObjectClient extends Model
{
    protected $table = 'object_clients';

    protected $fillable = [
        'property_id',
        'contact_id',
        'pipeline_stage_id',
        'comment',
        'next_contact_at',
        'last_contact_at',
    ];

    protected $casts = [
        'property_id' => 'integer',
        'contact_id' => 'integer',
        'pipeline_stage_id' => 'integer',
        'next_contact_at' => 'datetime',
        'last_contact_at' => 'datetime',
    ];

    /**
     * Объект недвижимости
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Контакт (покупатель/арендатор)
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Стадия воронки
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    /**
     * Взаимодействия (таймлайн)
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class)->orderBy('interaction_at', 'desc');
    }
}
