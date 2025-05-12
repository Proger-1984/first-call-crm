<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class SubscriptionHistory
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $subscription_id
 * @property string $action
 * @property string $tariff_name
 * @property string $category_name
 * @property string $location_name
 * @property float $price_paid
 * @property Carbon $action_date
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read User $user
 * @property-read UserSubscription|null $subscription
 */
class SubscriptionHistory extends Model
{
    use HasFactory;

    protected $table = 'subscription_history';

    protected $fillable = [
        'user_id',
        'subscription_id',
        'action',
        'tariff_name',
        'category_name',
        'location_name',
        'price_paid',
        'action_date',
        'notes'
    ];

    protected $casts = [
        'price_paid' => 'float',
        'action_date' => 'datetime'
    ];

    /**
     * Get the user that owns this history record
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subscription that owns this history record
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class);
    }
} 