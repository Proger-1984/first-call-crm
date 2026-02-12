<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * Модель клиента CRM
 *
 * @property int $id
 * @property int $user_id
 * @property int $pipeline_stage_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $phone_secondary
 * @property string|null $email
 * @property string|null $telegram_username
 * @property string $client_type
 * @property string|null $source_type
 * @property string|null $source_details
 * @property float|null $budget_min
 * @property float|null $budget_max
 * @property string|null $comment
 * @property bool $is_archived
 * @property Carbon|null $last_contact_at
 * @property Carbon|null $next_contact_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $user
 * @property-read PipelineStage $pipelineStage
 * @property-read Collection<ClientSearchCriteria> $searchCriteria
 * @property-read Collection<ClientListing> $clientListings
 */
class Client extends Model
{
    /** Типы клиентов */
    public const TYPE_BUYER = 'buyer';
    public const TYPE_SELLER = 'seller';
    public const TYPE_RENTER = 'renter';
    public const TYPE_LANDLORD = 'landlord';

    /** Все допустимые типы */
    public const ALLOWED_TYPES = [
        self::TYPE_BUYER,
        self::TYPE_SELLER,
        self::TYPE_RENTER,
        self::TYPE_LANDLORD,
    ];

    protected $table = 'clients';

    protected $fillable = [
        'user_id',
        'pipeline_stage_id',
        'name',
        'phone',
        'phone_secondary',
        'email',
        'telegram_username',
        'client_type',
        'source_type',
        'source_details',
        'budget_min',
        'budget_max',
        'comment',
        'is_archived',
        'last_contact_at',
        'next_contact_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'pipeline_stage_id' => 'integer',
        'budget_min' => 'float',
        'budget_max' => 'float',
        'is_archived' => 'boolean',
        'last_contact_at' => 'datetime',
        'next_contact_at' => 'datetime',
    ];

    /**
     * Агент-владелец клиента
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Текущая стадия воронки
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    /**
     * Критерии поиска клиента
     */
    public function searchCriteria(): HasMany
    {
        return $this->hasMany(ClientSearchCriteria::class, 'client_id');
    }

    /**
     * Привязанные объявления (подборка)
     */
    public function clientListings(): HasMany
    {
        return $this->hasMany(ClientListing::class, 'client_id');
    }

    /**
     * Скоуп: только активные (не архивные) клиенты
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Скоуп: только архивные клиенты
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * Скоуп: по стадии воронки
     */
    public function scopeByStage(Builder $query, int $stageId): Builder
    {
        return $query->where('pipeline_stage_id', $stageId);
    }

    /**
     * Скоуп: по типу клиента
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('client_type', $type);
    }
}
