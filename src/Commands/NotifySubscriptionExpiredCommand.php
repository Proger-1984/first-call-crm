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

class NotifySubscriptionExpiredCommand extends Command
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
            ->setName('notify-subscription-expired')
            ->setDescription('Уведомляет пользователей о завершении срока действия подписки');
    }

    /**
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cmd = '/usr/bin/supervisorctl stop notify-subscription-expired';
        $this->logger->info("Запуск уведомления пользователей о завершении подписки",
            ['date' => date('Y-m-d H:i:s')], 'subscription-expired');
        
        try {
            // Находим подписки, которые истекли за последние 24 часа
            $now = Carbon::now();
            $startDate = $now->copy()->subDay();
            
            // Находим активные подписки, которые истекли в указанном интервале
            // Исключаем пользователей, у которых заблокирован бот
            // Исключаем подписки, для которых уже отправлено уведомление
            $expiredSubscriptions = UserSubscription::where('status', 'active')
                ->whereBetween('end_date', [$startDate, $now])
                ->whereNull('notified_expired_at')
                ->whereHas('user', function($query) {
                    $query->where('telegram_bot_blocked', false);
                })
                ->get();
            
            $this->logger->info("Найдено истекших подписок: " . count($expiredSubscriptions),
                ['end_date_from' => $startDate, 'end_date_to' => $now],
                'subscription-expired');
            
            foreach ($expiredSubscriptions as $subscription) {
                try {
                    // Атомарно помечаем подписку как "в процессе отправки" чтобы избежать дубликатов
                    $updated = UserSubscription::where('id', $subscription->id)
                        ->whereNull('notified_expired_at')
                        ->update(['notified_expired_at' => Carbon::now()]);
                    
                    // Если не удалось обновить — значит другой процесс уже обработал эту подписку
                    if ($updated === 0) {
                        $this->logger->info("Подписка уже обрабатывается другим процессом", 
                            ['subscription_id' => $subscription->id], 'subscription-expired');
                        continue;
                    }
                    
                    $user = User::find($subscription->user_id);
                    
                    if (!$user || empty($user->telegram_id)) {
                        $this->logger->warning("Пользователь не найден или отсутствует Telegram ID", 
                            ['subscription_id' => $subscription->id, 'user_id' => $subscription->user_id], 
                            'subscription-expired');
                        continue;
                    }
                    
                    // Подгружаем связанные данные
                    $subscription->load(['category', 'location', 'tariff']);
                    
                    // Отправляем уведомление об истечении подписки
                    $result = $this->telegramService->notifySubscriptionExpired($user, $subscription);
                    
                    if ($result) {
                        $this->logger->info("Уведомление об истечении подписки отправлено пользователю", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id], 
                            'subscription-expired');
                    } else {
                        // Проверяем, заблокирован ли бот после попытки отправки
                        if ($user->telegram_bot_blocked) {
                            $this->logger->warning("Не удалось отправить уведомление об истечении подписки - бот заблокирован", 
                                ['user_id' => $user->id, 'subscription_id' => $subscription->id], 
                                'subscription-expired');
                        } else {
                            $this->logger->warning("Не удалось отправить уведомление об истечении подписки пользователю", 
                                ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'error' => $result],
                                'subscription-expired');
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error("Ошибка при отправке уведомления об истечении подписки", [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ], 'subscription-expired');
                }
            }

            $this->logger->info("Уведомление о завершении подписок завершено", [], 'subscription-expired');

            sleep(3);
            exec($cmd);
            return 0;
        } catch (Exception $e) {
            $this->logger->error("Ошибка при выполнении скрипта уведомления об истечении подписок", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'subscription-expired');

            sleep(3);
            exec($cmd);
            return 0;
        }
    }
} 