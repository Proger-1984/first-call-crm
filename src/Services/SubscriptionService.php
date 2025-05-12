<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Tariff;
use App\Models\Category;
use App\Models\Location;
use App\Models\TariffPrice;
use Carbon\Carbon;
use Psr\Container\ContainerInterface;
use Illuminate\Database\Capsule\Manager;
use RuntimeException;

class SubscriptionService
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Создает демо-подписку для нового пользователя
     * 
     * @param User $user Пользователь
     * @param int|null $categoryId ID категории (если null, будет использована первая доступная)
     * @param int|null $locationId ID локации (если null, будет использована первая доступная)
     * @return UserSubscription Созданная подписка
     * @throws RuntimeException Если не найден демо-тариф, категория или локация
     */
    public function createDemoSubscription(User $user, ?int $categoryId = null, ?int $locationId = null): UserSubscription
    {
        // Получаем демо-тариф
        $demoTariff = Tariff::where('code', 'demo')->where('is_active', true)->first();
        if (!$demoTariff) {
            throw new RuntimeException('Демо-тариф не найден');
        }
        
        // Если категория не указана, берем первую доступную
        if ($categoryId === null) {
            $category = Category::first();
            if (!$category) {
                throw new RuntimeException('Категории не найдены');
            }
            $categoryId = $category->id;
        }
        
        // Если локация не указана, берем первую доступную
        if ($locationId === null) {
            $location = Location::first();
            if (!$location) {
                throw new RuntimeException('Локации не найдены');
            }
            $locationId = $location->id;
        }
        
        // Проверяем, что пользователь еще не использовал демо-тариф
        if ($user->hasUsedTrial()) {
            throw new RuntimeException('Пользователь уже использовал демо-тариф');
        }
        
        // Создаем демо-подписку
        return $user->requestSubscription($demoTariff->id, $categoryId, $locationId);
    }
    
    /**
     * Проверяет, есть ли у пользователя активная подписка для указанной категории и локации
     * 
     * @param User $user Пользователь
     * @param int $categoryId ID категории
     * @param int $locationId ID локации
     * @return bool Результат проверки
     */
    public function checkAccess(User $user, int $categoryId, int $locationId): bool
    {
        return $user->hasActiveSubscription($categoryId, $locationId);
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
     * @return \Illuminate\Database\Eloquent\Collection Список подписок
     */
    public function getPendingSubscriptions()
    {
        return UserSubscription::with(['user', 'tariff', 'category', 'location'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();
    }
    
    /**
     * Получает список подписок, срок действия которых скоро истечет
     * 
     * @param int $daysThreshold Порог в днях
     * @return \Illuminate\Database\Eloquent\Collection Список подписок
     */
    public function getSoonExpiringSubscriptions(int $daysThreshold = 3)
    {
        $thresholdDate = Carbon::now()->addDays($daysThreshold);
        
        return UserSubscription::with(['user', 'tariff', 'category', 'location'])
            ->where('status', 'active')
            ->where('end_date', '>', Carbon::now())
            ->where('end_date', '<', $thresholdDate)
            ->orderBy('end_date', 'asc')
            ->get();
    }
    
    /**
     * Получает все активные тарифы
     * 
     * @return \Illuminate\Database\Eloquent\Collection Список тарифов
     */
    public function getActiveTariffs()
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
} 