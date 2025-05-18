<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserSubscription;
use App\Models\Tariff;
use App\Models\TariffPrice;
use Carbon\Carbon;
use Psr\Container\ContainerInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionService
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
    
    /**
     * Получает оставшееся время действия подписки
     * 
     * @param UserSubscription $subscription Подписка
     * @return int|null Оставшееся время в секундах или null, если подписка не активна
     */
    public function getRemainingTime(UserSubscription $subscription): ?int
    {
        if (!$subscription->isActive() || !$subscription->end_date) {
            return null;
        }
        
        $now = Carbon::now();
        if ($now->isAfter($subscription->end_date)) {
            return 0;
        }
        
        return $now->diffInSeconds($subscription->end_date);
    }
    
    /**
     * Проверяет и обрабатывает истекшие подписки
     * 
     * @return int Количество обработанных подписок
     */
    public function checkExpiredSubscriptions(): int
    {
        $expiredSubscriptions = UserSubscription::where('status', 'active')
            ->where('end_date', '<', Carbon::now())
            ->get();
        
        $count = 0;
        foreach ($expiredSubscriptions as $subscription) {
            $subscription->markAsExpired();
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Получает список подписок, ожидающих подтверждения администратором
     * 
     * @return Collection Список подписок
     */
    public function getPendingSubscriptions(): Collection
    {
        return UserSubscription::with(['user', 'tariff', 'category', 'location'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();
    }
    
    /**
     * Получает список подписок, срок действия которых скоро истечет
     * 
     * @param int $daysThreshold Порог в днях
     * @return Collection Список подписок
     */
    public function getSoonExpiringSubscriptions(int $daysThreshold = 3): Collection
    {
        $thresholdDate = Carbon::now()->addDays($daysThreshold);
        
        return UserSubscription::with(['user', 'tariff', 'category', 'location'])
            ->where('status', 'active')
            ->where('end_date', '>', Carbon::now())
            ->where('end_date', '<', $thresholdDate)
            ->orderBy('end_date')
            ->get();
    }
    
    /**
     * Получает все активные тарифы
     * 
     * @return Collection Список тарифов
     */
    public function getActiveTariffs(): Collection
    {
        return Tariff::where('is_active', true)->get();
    }
    
    /**
     * Получает цену тарифа для указанной локации
     * 
     * @param int $tariffId ID тарифа
     * @param int $locationId ID локации
     * @return float|null Цена или null, если не найдена
     */
    public function getTariffPrice(int $tariffId, int $locationId): ?float
    {
        $priceRecord = TariffPrice::where('tariff_id', $tariffId)
            ->where('location_id', $locationId)
            ->first();
        
        if ($priceRecord) {
            return (float)$priceRecord->price;
        }
        
        // Если цена для локации не найдена, берем стандартную цену из тарифа
        $tariff = Tariff::find($tariffId);
        return $tariff ? (float)$tariff->price : null;
    }

    /**
     * Получает рекомендуемый тариф для апгрейда текущей подписки
     * 
     * @param UserSubscription $subscription Текущая подписка
     * @return Tariff|null Рекомендуемый тариф или null, если нет подходящих рекомендаций
     */
    public function getRecommendedUpgrade(UserSubscription $subscription): ?Tariff
    {
        $currentTariff = $subscription->tariff;
        
        // Для демо-тарифа рекомендуем Премиум 1
        if ($currentTariff->isDemo()) {
            return Tariff::where('code', 'premium_1')
                    ->where('is_active', true)
                    ->first();
        }
        
        // Для премиум-тарифа рекомендуем следующий уровень
        if ($currentTariff->isPremium()) {
            $currentDuration = $currentTariff->getPremiumDuration();
            $nextLevels = [
                1 => 7,
                7 => 30
            ];
            
            if (isset($nextLevels[$currentDuration])) {
                return Tariff::where('code', 'premium_' . $nextLevels[$currentDuration])
                        ->where('is_active', true)
                        ->first();
            }
        }
        
        return null;
    }
    
    /**
     * Получает список доступных апгрейдов для подписки
     * 
     * @param UserSubscription $subscription Текущая подписка
     * @return Collection Коллекция доступных тарифов для апгрейда
     */
    public function getAvailableUpgrades(UserSubscription $subscription): Collection
    {
        $currentTariff = $subscription->tariff;
        
        // Для демо-тарифа предлагаем все премиум-тарифы
        if ($currentTariff->isDemo()) {
            return Tariff::where('is_active', true)
                    ->where('code', 'like', 'premium_%')
                    ->orderBy('duration_hours')
                    ->get();
        }
        
        // Для премиум-тарифа предлагаем тарифы с большей длительностью
        if ($currentTariff->isPremium()) {
            $currentDuration = $currentTariff->getPremiumDuration();
            
            return Tariff::where('is_active', true)
                    ->where('code', 'like', 'premium_%')
                    ->get()
                    ->filter(function ($tariff) use ($currentDuration) {
                        return $tariff->getPremiumDuration() > $currentDuration;
                    })
                    ->values();
        }
        
        return collect();
    }
} 