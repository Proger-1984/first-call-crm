<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\UserSubscription;
use Carbon\Carbon;

/**
 * Команда для проверки и обновления статусов истекших подписок
 * Рекомендуется запускать по расписанию (например, раз в час или раз в день)
 */
class CheckExpiredSubscriptionsCommand
{
    /**
     * Выполнение команды
     * 
     * @return array Результат выполнения [success: bool, count: int, message: string]
     */
    public function execute(): array
    {
        // Находим все активные подписки с истекшей датой окончания
        $expiredSubscriptions = UserSubscription::where('status', 'active')
            ->where('end_date', '<', Carbon::now())
            ->get();
            
        $count = 0;
        foreach ($expiredSubscriptions as $subscription) {
            if ($subscription->markAsExpired()) {
                $count++;
            }
        }
        
        if ($count > 0) {
            return [
                'success' => true,
                'count' => $count,
                'message' => "Обработано $count истекших подписок"
            ];
        } else {
            return [
                'success' => true,
                'count' => 0,
                'message' => "Не найдено истекших подписок для обработки"
            ];
        }
    }
} 