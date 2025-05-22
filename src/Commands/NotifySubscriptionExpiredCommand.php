<?php

namespace App\Commands;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\LogService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Exception;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info("Запуск уведомления пользователей о завершении подписки",
            ['date' => date('Y-m-d H:i:s')], 'subscription-expired');
        
        try {
            // Находим подписки, которые истекли за последние 24 часа
            $now = Carbon::now();
            $startDate = $now->copy()->subDay();
            
            // Находим активные подписки, которые истекли в указанном интервале
            $expiredSubscriptions = UserSubscription::where('status', 'active')
                ->whereBetween('end_date', [$startDate, $now])
                ->get();
            
            $this->logger->info("Найдено истекших подписок: " . count($expiredSubscriptions), [], 'subscription-expired');
            
            foreach ($expiredSubscriptions as $subscription) {
                try {
                    $user = User::find($subscription->user_id);
                    
                    if (!$user || empty($user->telegram_id)) {
                        $this->logger->warning("Пользователь не найден или отсутствует Telegram ID", 
                            ['subscription_id' => $subscription->id, 'user_id' => $subscription->user_id], 
                            'subscription-expired');
                        continue;
                    }
                    
                    // Подгружаем связанные данные
                    $subscription->load(['category', 'location', 'tariff']);
                    
                    // Формируем текст уведомления
                    $endDate = $subscription->end_date->format('d.m.Y H:i');
                    
                    $message = "❌ <b>Срок действия подписки истек</b>\n\n" .
                        "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
                        "для локации <b>{$subscription->location->getFullName()}</b> закончилась.\n\n" .
                        "⏱ Дата окончания: <b>$endDate</b>\n\n" .
                        "Для продления доступа перейдите в раздел «Подписки» в приложении или обратитесь в " .
                        "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";
                    
                    $result = $this->telegramService->sendMessage($user->telegram_id, $message);
                    
                    if ($result) {
                        $this->logger->info("Уведомление об истечении подписки отправлено пользователю", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id], 
                            'subscription-expired');
                    } else {
                        $this->logger->warning("Не удалось отправить уведомление об истечении подписки пользователю", 
                            ['user_id' => $user->id, 'subscription_id' => $subscription->id], 
                            'subscription-expired');
                    }
                } catch (Exception $e) {
                    $this->logger->error("Ошибка при отправке уведомления об истечении подписки", [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ], 'subscription-expired');
                }
            }

            $this->logger->info("Уведомление о завершении подписок завершено", [], 'subscription-expired');
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error("Ошибка при выполнении скрипта уведомления об истечении подписок", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'subscription-expired');
            return Command::FAILURE;
        }
    }
} 