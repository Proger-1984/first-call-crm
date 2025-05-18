<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\UserSubscription;
use App\Models\Tariff;
use App\Services\SubscriptionService;
use App\Services\TelegramService;
use Carbon\Carbon;

/**
 * Команда для отправки уведомлений о подписках, которые скоро истекут
 * Рекомендуется запускать по расписанию (например, раз в день)
 */
class NotifyExpiringSubscriptionsCommand
{
    private SubscriptionService $subscriptionService;
    private TelegramService $telegramService;
    
    public function __construct(SubscriptionService $subscriptionService, TelegramService $telegramService)
    {
        $this->subscriptionService = $subscriptionService;
        $this->telegramService = $telegramService;
    }
    
    /**
     * Выполнение команды
     * 
     * @return array Результат выполнения [success: bool, count: int, message: string]
     */
    public function execute(): array
    {
        // Получаем подписки, которые скоро истекут, с разными порогами для разных типов тарифов
        $expiringSubs = $this->getExpiringSubscriptions();
        $count = count($expiringSubs);
        $sentCount = 0;
        
        if ($count > 0) {
            // Для каждой подписки отправляем уведомление
            foreach ($expiringSubs as $subscription) {
                if ($this->telegramService->notifySubscriptionExpiringSoon($subscription->user, $subscription)) {
                    $sentCount++;
                } else {
                    // Логирование ошибки
                    error_log("Не удалось отправить уведомление пользователю {$subscription->user_id} о подписке {$subscription->id}");
                }
            }
            
            return [
                'success' => true,
                'count' => $sentCount,
                'message' => "Отправлено {$sentCount} из {$count} уведомлений об истекающих подписках"
            ];
        } else {
            return [
                'success' => true,
                'count' => 0,
                'message' => "Нет истекающих подписок для отправки уведомлений"
            ];
        }
    }
    
    /**
     * Получает подписки, которые скоро истекут
     * 
     * @return array Массив подписок
     */
    private function getExpiringSubscriptions(): array
    {
        $now = Carbon::now();
        $result = [];
        
        // Получаем все активные подписки
        $activeSubscriptions = UserSubscription::with(['user', 'tariff', 'category', 'location'])
            ->where('status', 'active')
            ->where('end_date', '>', $now)
            ->get();
        
        foreach ($activeSubscriptions as $subscription) {
            $hoursLeft = $now->diffInHours($subscription->end_date);
            $tariff = $subscription->tariff;
            
            // Разные пороги для разных типов тарифов
            if ($tariff->isDemo() && $hoursLeft <= 0.5) {
                // Для демо за 30 минут до окончания
                $result[] = $subscription;
            } elseif ($tariff->isPremiumOfDuration(1) && $hoursLeft <= 6) {
                // Для 1-дневного премиума за 6 часов до окончания
                $result[] = $subscription;
            } elseif ($tariff->isPremiumOfDuration(7) && $hoursLeft <= 24) {
                // Для 7-дневного премиума за 1 день до окончания
                $result[] = $subscription;
            } elseif ($tariff->isPremiumOfDuration(31) && $hoursLeft <= 72) {
                // Для 31-дневного премиума за 3 дня до окончания
                $result[] = $subscription;
            }
        }
        
        return $result;
    }
} 