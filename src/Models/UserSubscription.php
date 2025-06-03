<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Exception;
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
     * Получить пользователя, которому принадлежит подписка
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить тариф для этой подписки
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /**
     * Получить категорию для этой подписки
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Получить локацию для этой подписки
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Получить администратора, который подтвердил эту подписку
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Получить записи истории для этой подписки
     */
    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class, 'subscription_id');
    }

    /**
     * Проверить, ожидает ли подписка подтверждения
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Проверить, активна ли подписка
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && 
               $this->start_date && 
               $this->end_date && 
               Carbon::now()->isBetween($this->start_date, $this->end_date);
    }

    /**
     * Проверить, истекла ли подписка
     */
    public function isExpired(): bool
    {
        return $this->end_date && Carbon::now()->isAfter($this->end_date);
    }

    /**
     * Отменить подписку
     */
    public function cancel(string $reason = null): bool
    {
        if ($this->status === 'active' || $this->status === 'pending') {
            // Сначала создаем запись в истории перед изменением статуса
            $history = SubscriptionHistory::create([
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
            
            // Затем меняем статус и сохраняем
            $this->status = 'cancelled';
            $result = $this->save();
            
            return $result && $history !== null;
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
        string $paymentMethod,
        float $newPrice = null,
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
     * Получает уникальные комбинации локаций и категорий из активных подписок пользователей
     * @return array Массив уникальных комбинаций локаций и категорий
     */
    public static function getUniqueLocationCategoryPairs(): array
    {
        try {
            // Запрос для получения уникальных комбинаций локаций и категорий из активных подписок
            $subscriptions = self::select('location_id', 'category_id')
                ->where('status', 'active')
                ->distinct()
                ->with(['location', 'category'])
                ->get();
            
            // Преобразуем результат в нужный формат
            $pairs = [];
            foreach ($subscriptions as $subscription) {
                $pairs[] = [
                    'location_id' => $subscription->location->id,
                    'location_name' => $subscription->location->city,
                    'category_id' => $subscription->category->id,
                    'category_name' => $subscription->category->name,
                ];
            }
            
            return $pairs;
            
        } catch (Exception) {
            return [];
        }
    }
} 