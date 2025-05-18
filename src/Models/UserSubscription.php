<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class UserSubscription
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property int $tariff_id
 * @property int $category_id
 * @property int $location_id
 * @property float $price_paid
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string $status
 * @property string|null $payment_method
 * @property string|null $admin_notes
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property bool $is_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read User $user
 * @property-read Tariff $tariff
 * @property-read Category $category
 * @property-read Location $location
 * @property-read User|null $approver
 * @property-read Collection|SubscriptionHistory[] $history
 * @method static Builder|self where($column, $operator = null, $value = null)
 * @method static Builder|Source whereIn(string $column, array $values)
 */
class UserSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tariff_id',
        'category_id',
        'location_id',
        'price_paid',
        'start_date',
        'end_date',
        'status',
        'payment_method',
        'admin_notes',
        'approved_by',
        'approved_at',
        'is_enabled'
    ];

    protected $casts = [
        'price_paid' => 'float',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'approved_at' => 'datetime',
        'is_enabled' => 'boolean'
    ];

    /**
     * Get the user that owns the subscription
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tariff for this subscription
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /**
     * Get the category for this subscription
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the location for this subscription
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the admin who approved this subscription
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the history records for this subscription
     */
    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class, 'subscription_id');
    }

    /**
     * Check if the subscription is pending approval
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the subscription is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && 
               $this->start_date && 
               $this->end_date && 
               Carbon::now()->isBetween($this->start_date, $this->end_date);
    }

    /**
     * Check if the subscription is active and enabled
     */
    public function isActiveAndEnabled(): bool
    {
        return $this->isActive() && $this->is_enabled;
    }

    /**
     * Check if the subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->end_date && Carbon::now()->isAfter($this->end_date);
    }

    /**
     * Mark the subscription as expired
     */
    public function markAsExpired(): bool
    {
        if ($this->status !== 'expired') {
            $this->status = 'expired';
            $result = $this->save();
            
            // Log to history
            if ($result) {
                SubscriptionHistory::create([
                    'user_id' => $this->user_id,
                    'subscription_id' => $this->id,
                    'action' => 'expired',
                    'tariff_name' => $this->tariff->name,
                    'category_name' => $this->category->name,
                    'location_name' => $this->location->getFullName(),
                    'price_paid' => $this->price_paid,
                    'action_date' => Carbon::now(),
                    'notes' => 'Подписка истекла автоматически'
                ]);
            }
            
            return $result;
        }
        
        return false;
    }

    /**
     * Cancel the subscription
     */
    public function cancel(string $reason = null): bool
    {
        if ($this->status === 'active' || $this->status === 'pending') {
            $this->status = 'cancelled';
            $result = $this->save();
            
            // Log to history
            if ($result) {
                SubscriptionHistory::create([
                    'user_id' => $this->user_id,
                    'subscription_id' => $this->id,
                    'action' => 'cancelled',
                    'tariff_name' => $this->tariff->name,
                    'category_name' => $this->category->name,
                    'location_name' => $this->location->getFullName(),
                    'price_paid' => $this->price_paid,
                    'action_date' => Carbon::now(),
                    'notes' => $reason ?? 'Подписка отменена пользователем или администратором'
                ]);
            }
            
            return $result;
        }
        
        return false;
    }

    /**
     * Активация подписки администратором
     * 
     * @param int $adminId ID администратора, подтверждающего подписку
     * @param string $paymentMethod Метод оплаты
     * @param string|null $notes Примечания администратора
     * @param int|null $durationHours Продолжительность подписки в часах (если null, берется из тарифа)
     * @return bool Результат операции
     */
    public function activate(int $adminId, string $paymentMethod, string $notes = null, int $durationHours = null): bool
    {
        if ($this->status !== 'pending' && $this->status !== 'expired') {
            return false;
        }

        $tariff = $this->tariff;
        $durationHours = $durationHours ?? $tariff->duration_hours;
        
        $this->status = 'active';
        $this->is_enabled = true;
        $this->start_date = Carbon::now();
        $this->end_date = Carbon::now()->addHours($durationHours);
        $this->payment_method = $paymentMethod;
        $this->admin_notes = $notes;
        $this->approved_by = $adminId;
        $this->approved_at = Carbon::now();
        
        $result = $this->save();
        
        // Добавляем информацию в историю
        if ($result) {
            SubscriptionHistory::create([
                'user_id' => $this->user_id,
                'subscription_id' => $this->id,
                'action' => 'activated',
                'tariff_name' => $this->tariff->name,
                'category_name' => $this->category->name,
                'location_name' => $this->location->getFullName(),
                'price_paid' => $this->price_paid,
                'action_date' => Carbon::now(),
                'notes' => "Подписка активирована администратором. " . ($notes ? "Примечание: $notes" : "")
            ]);
        }
        
        return $result;
    }

    /**
     * Продлевает существующую подписку, добавляя дополнительный срок действия
     * 
     * @param int $adminId ID администратора, продлевающего подписку
     * @param float|null $newPrice Новая цена подписки (если null, используется прежняя цена)
     * @param string $paymentMethod Метод оплаты
     * @param string|null $notes Примечания администратора
     * @param int|null $durationHours Продолжительность продления в часах (если null, берется из тарифа)
     * @return bool Результат операции
     */
    public function extendByAdmin(
        int $adminId, 
        float $newPrice = null, 
        string $paymentMethod, 
        string $notes = null,
        int $durationHours = null
    ): bool {
        $tariff = $this->tariff;
        $newPrice = $newPrice ?? $this->price_paid;
        $durationHours = $durationHours ?? $tariff->duration_hours;
        
        // Если подписка уже истекла, начинаем с текущей даты
        $startPoint = $this->isExpired() || !$this->end_date ? Carbon::now() : $this->end_date;
        
        // Рассчитываем новую дату окончания
        $this->status = 'active';
        $this->start_date = $this->start_date ?? Carbon::now(); // Если это была pending подписка
        $this->end_date = (clone $startPoint)->addHours($durationHours);
        $this->price_paid = $newPrice;
        $this->payment_method = $paymentMethod;
        $this->admin_notes = $notes ? ($this->admin_notes ? $this->admin_notes . "; " . $notes : $notes) : $this->admin_notes;
        $this->approved_by = $adminId;
        $this->approved_at = Carbon::now();
        
        $result = $this->save();
        
        // Логируем операцию в историю
        if ($result) {
            SubscriptionHistory::create([
                'user_id' => $this->user_id,
                'subscription_id' => $this->id,
                'action' => 'extended',
                'tariff_name' => $this->tariff->name,
                'category_name' => $this->category->name,
                'location_name' => $this->location->getFullName(),
                'price_paid' => $newPrice,
                'action_date' => Carbon::now(),
                'notes' => "Подписка продлена администратором. " . ($notes ? "Примечание: $notes" : "")
            ]);
        }
        
        return $result;
    }

    /**
     * Временно включает или отключает подписку
     * 
     * @param bool $enabled Статус включения (true - включить, false - отключить)
     * @return bool Результат операции
     */
    public function toggleEnabled(bool $enabled): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        $previousState = $this->is_enabled;
        $this->is_enabled = $enabled;
        $result = $this->save();
        
        // Логируем изменение в историю
        if ($result && $previousState !== $enabled) {
            $action = $enabled ? 'enabled' : 'disabled';
            SubscriptionHistory::create([
                'user_id' => $this->user_id,
                'subscription_id' => $this->id,
                'action' => $action,
                'tariff_name' => $this->tariff->name,
                'category_name' => $this->category->name,
                'location_name' => $this->location->getFullName(),
                'price_paid' => $this->price_paid,
                'action_date' => Carbon::now(),
                'notes' => $enabled ? 'Подписка включена пользователем' : 'Подписка временно отключена пользователем'
            ]);
        }
        
        return $result;
    }

    /**
     * Обновляет тариф подписки с сохранением категории и локации
     * Подходит для перехода между тарифами разной длительности
     * 
     * @param int $newTariffId ID нового тарифа
     * @param int $adminId ID администратора, выполняющего операцию
     * @param float|null $newPrice Новая цена (если null, берется из тарифа или TariffPrice)
     * @param string|null $paymentMethod Метод оплаты
     * @param string|null $notes Примечания
     * @return bool Результат операции
     */
    public function updateTariff(int $newTariffId, int $adminId, float $newPrice = null, string $paymentMethod = null, string $notes = null): bool
    {
        // Проверяем существование тарифа
        $newTariff = Tariff::findOrFail($newTariffId);
        
        // Получаем стандартную цену для нового тарифа
        $tariffPrice = TariffPrice::where('tariff_id', $newTariffId)
                              ->where('location_id', $this->location_id)
                              ->first();
        $price = $newPrice ?? ($tariffPrice ? $tariffPrice->price : $newTariff->price);
        
        // Обновляем тариф и другие поля
        $this->tariff_id = $newTariffId;
        $this->price_paid = $price;
        $this->payment_method = $paymentMethod ?? $this->payment_method;
        $this->admin_notes = $notes ? ($this->admin_notes ? $this->admin_notes . "; " . $notes : $notes) : $this->admin_notes;
        $this->approved_by = $adminId;
        $this->approved_at = Carbon::now();
        
        // Для активной подписки обновляем дату окончания
        if ($this->status === 'active') {
            // Если подписка уже активна, рассчитываем оставшееся время и переносим на новый тариф
            $now = Carbon::now();
            
            if ($this->end_date && $this->end_date->isAfter($now)) {
                // Остаток времени от старого тарифа в часах
                $remainingHours = $now->diffInHours($this->end_date);
                
                // Если остаток времени меньше часа, считаем как 1 час
                if ($remainingHours < 1) {
                    $remainingHours = 1;
                }
                
                // Устанавливаем новую дату окончания
                $this->end_date = $now->copy()->addHours($newTariff->duration_hours + $remainingHours);
            } else {
                // Если подписка уже истекла, просто устанавливаем новую длительность
                $this->end_date = $now->copy()->addHours($newTariff->duration_hours);
            }
        } else if ($this->status === 'pending' || $this->status === 'expired') {
            // Для новой или истекшей подписки активируем её
            $this->status = 'active';
            $this->start_date = Carbon::now();
            $this->end_date = Carbon::now()->addHours($newTariff->duration_hours);
            $this->is_enabled = true;
        }
        
        $result = $this->save();
        
        // Логируем операцию в историю
        if ($result) {
            SubscriptionHistory::create([
                'user_id' => $this->user_id,
                'subscription_id' => $this->id,
                'action' => 'tariff_changed',
                'tariff_name' => $newTariff->name,
                'category_name' => $this->category->name,
                'location_name' => $this->location->getFullName(),
                'price_paid' => $price,
                'action_date' => Carbon::now(),
                'notes' => "Тариф изменен админинистратором на {$newTariff->name}. " . ($notes ? "Примечание: $notes" : "")
            ]);
        }
        
        return $result;
    }
} 