<?php

namespace App\Commands;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\LogService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
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

    /**
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cmd = '/usr/bin/supervisorctl stop notify-subscription-expiring';
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

            sleep(3);
            exec($cmd);
            return 0;

        } catch (Exception $e) {
            $this->logger->error("Ошибка при выполнении скрипта", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'subscription-expiring');

            sleep(3);
            exec($cmd);
            return 0;
        }
    }

    /**
     * Уведомляет пользователей о подписках, истекающих через указанное количество дней
     * @throws GuzzleException
     */
    private function notifySubscriptionsExpiringInDays(int $days): void
    {
        // Рассчитываем интервал для проверки (от текущего дня + $days до следующего дня)
        $startDate = Carbon::now()->addDays($days)->startOfDay();
        $endDate = Carbon::now()->addDays($days)->endOfDay();
        
        // Определяем поле для проверки отправки уведомления
        $notifiedField = $days === 3 ? 'notified_expiring_3d_at' : 'notified_expiring_1d_at';
        
        // Находим активные подписки, которые истекают в этом интервале
        // Исключаем пользователей, у которых заблокирован бот
        // Исключаем подписки, для которых уже отправлено уведомление
        $expiringSubscriptions = UserSubscription::where('status', 'active')
            ->whereBetween('end_date', [$startDate, $endDate])
            ->whereNull($notifiedField)
            ->whereHas('user', function($query) {
                $query->where('telegram_bot_blocked', false);
            })
            ->get();
        
        $this->logger->info("Найдено подписок, истекающих через $days дней: " . count($expiringSubscriptions), [], 'subscription-expiring');
        
        foreach ($expiringSubscriptions as $subscription) {
            try {
                // Атомарно помечаем подписку как "в процессе отправки" чтобы избежать дубликатов
                // Используем whereNull для защиты от race condition
                $updated = UserSubscription::where('id', $subscription->id)
                    ->whereNull($notifiedField)
                    ->update([$notifiedField => Carbon::now()]);
                
                // Если не удалось обновить — значит другой процесс уже обработал эту подписку
                if ($updated === 0) {
                    $this->logger->info("Подписка уже обрабатывается другим процессом", 
                        ['subscription_id' => $subscription->id], 'subscription-expiring');
                    continue;
                }
                
                $user = User::find($subscription->user_id);
                
                if (!$user || empty($user->telegram_id)) {
                    $this->logger->warning("Пользователь не найден или отсутствует Telegram ID", 
                        ['subscription_id' => $subscription->id, 'user_id' => $subscription->user_id], 
                        'subscription-expiring');
                    continue;
                }
                
                // Подгружаем связанные данные
                $subscription->load(['category', 'location', 'tariff']);
                
                // Отправляем уведомление о скором окончании подписки
                $result = $this->telegramService->notifySubscriptionExpiring($user, $subscription, $days);
                
                if ($result) {
                    $this->logger->info("Уведомление отправлено пользователю", 
                        ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'days' => $days], 
                        'subscription-expiring');
                } else {
                    // Проверяем, заблокирован ли бот после попытки отправки
                    if ($user->telegram_bot_blocked) {
                        $this->logger->warning("Не удалось отправить уведомление пользователю - бот заблокирован", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'days' => $days], 
                            'subscription-expiring');
                    } else {
                        $this->logger->warning("Не удалось отправить уведомление пользователю", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'days' => $days], 
                            'subscription-expiring');
                    }
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
     * Уведомляет пользователей о демо-подписках, истекающих через указанное количество минут
     * @throws GuzzleException
     */
    private function notifyDemoSubscriptionsExpiringInMinutes(int $minutes): void
    {
        // Рассчитываем интервал для проверки
        $now = Carbon::now();
        $targetStartTime = $now->copy()->addMinutes($minutes)->subMinutes(2); // -2 минуты погрешность
        $targetEndTime = $now->copy()->addMinutes($minutes)->addMinutes(2);   // +2 минуты погрешность
        
        // Определяем поле для проверки отправки уведомления
        $notifiedField = $minutes === 60 ? 'notified_expiring_1h_at' : 'notified_expiring_15m_at';
        
        // Находим активные демо-подписки, которые истекают в этом интервале
        // Демо тариф имеет код 'demo'
        // Исключаем пользователей, у которых заблокирован бот
        // Исключаем подписки, для которых уже отправлено уведомление
        $expiringSubscriptions = UserSubscription::where('status', 'active')
            ->whereBetween('end_date', [$targetStartTime, $targetEndTime])
            ->whereNull($notifiedField)
            ->whereHas('tariff', function($query) {
                $query->where('code', 'demo');
            })
            ->whereHas('user', function($query) {
                $query->where('telegram_bot_blocked', false);
            })
            ->with(['tariff', 'category', 'location'])
            ->get();
        
        $this->logger->info("Найдено демо-подписок, истекающих через $minutes минут: " . 
            count($expiringSubscriptions), [], 'subscription-expiring');
        
        foreach ($expiringSubscriptions as $subscription) {
            try {
                // Атомарно помечаем подписку как "в процессе отправки" чтобы избежать дубликатов
                $updated = UserSubscription::where('id', $subscription->id)
                    ->whereNull($notifiedField)
                    ->update([$notifiedField => Carbon::now()]);
                
                // Если не удалось обновить — значит другой процесс уже обработал эту подписку
                if ($updated === 0) {
                    $this->logger->info("Подписка уже обрабатывается другим процессом", 
                        ['subscription_id' => $subscription->id], 'subscription-expiring');
                    continue;
                }
                
                $user = User::find($subscription->user_id);
                
                if (!$user || empty($user->telegram_id)) {
                    $this->logger->warning("Пользователь не найден или отсутствует Telegram ID", 
                        ['subscription_id' => $subscription->id, 'user_id' => $subscription->user_id], 
                        'subscription-expiring');
                    continue;
                }
                
                // Отправляем уведомление о скором окончании демо-подписки
                $result = $this->telegramService->notifyDemoSubscriptionExpiring($user, $subscription, $minutes);
                
                if ($result) {
                    $this->logger->info("Уведомление о скором окончании демо-подписки отправлено пользователю", 
                        ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'minutes' => $minutes], 
                        'subscription-expiring');
                } else {
                    // Проверяем, заблокирован ли бот после попытки отправки
                    if ($user->telegram_bot_blocked) {
                        $this->logger->warning("Не удалось отправить уведомление о скором окончании демо-подписки - бот заблокирован", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'minutes' => $minutes], 
                            'subscription-expiring');
                    } else {
                        $this->logger->warning("Не удалось отправить уведомление о скором окончании демо-подписки", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'minutes' => $minutes], 
                            'subscription-expiring');
                    }
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