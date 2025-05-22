<?php

namespace App\Commands;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\LogService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Capsule\Manager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Container\ContainerInterface;

class NotifySubscriptionExpiringCommand extends Command
{
    private LogService $logger;
    private TelegramService $telegramService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);
        $this->telegramService = $container->get(TelegramService::class);

        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->setName('notify-subscription-expiring')
            ->setDescription('Уведомляет пользователей о скором окончании подписки');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info("Запуск уведомления пользователей о скором окончании подписки",
            ['date' => date('Y-m-d H:i:s')], 'subscription-expiring');
        
        try {
            // Найдем подписки, которые истекают через 3 дня и 1 день (обычные тарифы)
            $this->notifySubscriptionsExpiringInDays(3);
            $this->notifySubscriptionsExpiringInDays(1);
            
            // Найдем демо-подписки, которые истекают через 1 час и 15 минут
            $this->notifyDemoSubscriptionsExpiringInMinutes(60); // 1 час
            $this->notifyDemoSubscriptionsExpiringInMinutes(15); // 15 минут

            $this->logger->info("Уведомление о скором окончании подписок завершено", [], 'subscription-expiring');
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error("Ошибка при выполнении скрипта", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'subscription-expiring');
            return Command::FAILURE;
        }
    }
    
    /**
     * Уведомляет пользователей о подписках, истекающих через указанное количество дней
     */
    private function notifySubscriptionsExpiringInDays(int $days): void
    {
        // Рассчитываем интервал для проверки (от текущего дня + $days до следующего дня)
        $startDate = Carbon::now()->addDays($days)->startOfDay();
        $endDate = Carbon::now()->addDays($days)->endOfDay();
        
        // Находим активные подписки, которые истекают в этом интервале
        $expiringSubscriptions = UserSubscription::where('status', 'active')
            ->whereBetween('end_date', [$startDate, $endDate])
            ->get();
        
        $this->logger->info("Найдено подписок, истекающих через $days дней: " . count($expiringSubscriptions), [], 'subscription-expiring');
        
        foreach ($expiringSubscriptions as $subscription) {
            try {
                $user = User::find($subscription->user_id);
                
                if (!$user || empty($user->telegram_id)) {
                    $this->logger->warning("Пользователь не найден или отсутствует Telegram ID", 
                        ['subscription_id' => $subscription->id, 'user_id' => $subscription->user_id], 
                        'subscription-expiring');
                    continue;
                }
                
                // Подгружаем связанные данные
                $subscription->load(['category', 'location', 'tariff']);
                
                // Формируем текст уведомления
                $endDate = $subscription->end_date->format('d.m.Y H:i');
                $daysText = $this->getDaysText($days);
                
                $message = "⚠️ <b>Скоро закончится срок действия подписки</b>\n\n" .
                    "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
                    "для локации <b>{$subscription->location->getFullName()}</b> истекает через $daysText.\n\n" .
                    "⏱ Дата окончания: <b>$endDate</b>\n\n" .
                    "Для продления подписки перейдите в раздел «Подписки» в приложении или обратитесь в " .
                    "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";
                
                $result = $this->telegramService->sendMessage($user->telegram_id, $message);
                
                if ($result) {
                    $this->logger->info("Уведомление отправлено пользователю", 
                        ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'days' => $days], 
                        'subscription-expiring');
                } else {
                    $this->logger->warning("Не удалось отправить уведомление пользователю", 
                        ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'days' => $days], 
                        'subscription-expiring');
                }
            } catch (Exception $e) {
                $this->logger->error("Ошибка при отправке уведомления", [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ], 'subscription-expiring');
            }
        }
    }
    
    /**
     * Возвращает текстовое представление дней
     */
    private function getDaysText(int $days): string
    {
        if ($days === 1) {
            return '1 день';
        } elseif ($days > 1 && $days < 5) {
            return "$days дня";
        } else {
            return "$days дней";
        }
    }
    
    /**
     * Возвращает текстовое представление минут
     */
    private function getMinutesText(int $minutes): string
    {
        if ($minutes == 60) {
            return '1 час';
        } elseif ($minutes == 30) {
            return '30 минут';
        } elseif ($minutes == 15) {
            return '15 минут';
        } elseif ($minutes % 10 == 1 && $minutes % 100 != 11) {
            return "$minutes минуту";
        } elseif (($minutes % 10 >= 2 && $minutes % 10 <= 4) && 
                 !($minutes % 100 >= 12 && $minutes % 100 <= 14)) {
            return "$minutes минуты";
        } else {
            return "$minutes минут";
        }
    }
    
    /**
     * Уведомляет пользователей о демо-подписках, истекающих через указанное количество минут
     */
    private function notifyDemoSubscriptionsExpiringInMinutes(int $minutes): void
    {
        // Рассчитываем интервал для проверки
        $now = Carbon::now();
        $targetStartTime = $now->copy()->addMinutes($minutes)->subMinutes(2); // -2 минуты погрешность
        $targetEndTime = $now->copy()->addMinutes($minutes)->addMinutes(2);   // +2 минуты погрешность
        
        // Находим активные демо-подписки, которые истекают в этом интервале
        // Демо тариф имеет код 'demo'
        $expiringSubscriptions = UserSubscription::where('status', 'active')
            ->whereBetween('end_date', [$targetStartTime, $targetEndTime])
            ->whereHas('tariff', function($query) {
                $query->where('code', 'demo');
            })
            ->with(['tariff', 'category', 'location'])
            ->get();
        
        $this->logger->info("Найдено демо-подписок, истекающих через $minutes минут: " . 
            count($expiringSubscriptions), [], 'subscription-expiring');
        
        foreach ($expiringSubscriptions as $subscription) {
            try {
                $user = User::find($subscription->user_id);
                
                if (!$user || empty($user->telegram_id)) {
                    $this->logger->warning("Пользователь не найден или отсутствует Telegram ID", 
                        ['subscription_id' => $subscription->id, 'user_id' => $subscription->user_id], 
                        'subscription-expiring');
                    continue;
                }
                
                // Формируем текст уведомления для демо-подписки
                $endDate = $subscription->end_date->format('d.m.Y H:i');
                $minutesText = $this->getMinutesText($minutes);
                
                $message = "⏳ <b>Скоро закончится срок действия демо-подписки</b>\n\n" .
                    "Ваша демо-подписка на категорию <b>{$subscription->category->name}</b> " .
                    "для локации <b>{$subscription->location->getFullName()}</b> истекает через $minutesText.\n\n" .
                    "⏱ Дата окончания: <b>$endDate</b>\n\n" .
                    "Для получения полного доступа оформите платную подписку в разделе «Подписки» приложения или обратитесь в " .
                    "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";
                
                $result = $this->telegramService->sendMessage($user->telegram_id, $message);
                
                if ($result) {
                    $this->logger->info("Уведомление о скором окончании демо-подписки отправлено пользователю", 
                        ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'minutes' => $minutes], 
                        'subscription-expiring');
                } else {
                    $this->logger->warning("Не удалось отправить уведомление о скором окончании демо-подписки", 
                        ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'minutes' => $minutes], 
                        'subscription-expiring');
                }
            } catch (Exception $e) {
                $this->logger->error("Ошибка при отправке уведомления о скором окончании демо-подписки", [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ], 'subscription-expiring');
            }
        }
    }
} 