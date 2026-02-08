<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель связи агента с объявлением
 * 
 * Хранит историю звонков оператора по объявлениям.
 * Если записи нет — объявление "Новое" для этого оператора.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $listing_id
 * @property int|null $call_status_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property-read User $user
 * @property-read Listing $listing
 * @property-read CallStatus|null $callStatus
 */
class AgentListing extends Model
{
    protected $table = 'agent_listings';

    protected $fillable = [
        'user_id',
        'listing_id',
        'call_status_id',
        'notes',
    ];

    /**
     * Пользователь (агент)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Объявление
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Статус звонка
     */
    public function callStatus(): BelongsTo
    {
        return $this->belongsTo(CallStatus::class);
    }
}
