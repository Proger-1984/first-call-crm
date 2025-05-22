<?php

namespace App\Commands;

use App\Models\SubscriptionHistory;
use App\Models\UserSubscription;
use App\Services\LogService;
use Carbon\Carbon;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Container\ContainerInterface;

class UpdateExpiredSubscriptionsCommand extends Command
{
    private LogService $logger;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);

        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->setName('update-expired-subscriptions')
            ->setDescription('Обновляет статус подписок, срок действия которых истек');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info("Запуск обновления статуса просроченных подписок",
            ['date' => date('Y-m-d H:i:s')], 'subscription-update');
        
        try {
            $now = Carbon::now();
            
            // Находим активные подписки, срок действия которых уже истек
            $expiredSubscriptions = UserSubscription::where('status', 'active')
                ->where('end_date', '<', $now)
                ->get();
            
            $this->logger->info("Найдено просроченных подписок: " . count($expiredSubscriptions), [], 'subscription-update');
            
            $updatedCount = 0;
            
            foreach ($expiredSubscriptions as $subscription) {
                try {
                    // Подгружаем связанные данные для логирования в историю
                    $subscription->load(['category', 'location', 'tariff']);
                    
                    // Меняем статус подписки на expired
                    $subscription->status = 'expired';
                    $result = $subscription->save();
                    
                    if ($result) {
                        $updatedCount++;
                        
                        // Добавляем запись в историю подписок
                        SubscriptionHistory::create([
                            'user_id' => $subscription->user_id,
                            'subscription_id' => $subscription->id,
                            'action' => 'expired',
                            'tariff_name' => $subscription->tariff->name,
                            'category_name' => $subscription->category->name,
                            'location_name' => $subscription->location->getFullName(),
                            'price_paid' => $subscription->price_paid,
                            'action_date' => $now,
                            'notes' => 'Срок действия подписки истек'
                        ]);
                        
                        $this->logger->info("Обновлен статус подписки", [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                            'end_date' => $subscription->end_date->format('Y-m-d H:i:s')
                        ], 'subscription-update');
                    } else {
                        $this->logger->warning("Не удалось обновить статус подписки", [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id
                        ], 'subscription-update');
                    }
                } catch (Exception $e) {
                    $this->logger->error("Ошибка при обновлении статуса подписки", [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ], 'subscription-update');
                }
            }
            
            $this->logger->info("Обновление статуса просроченных подписок завершено", [
                'updated_count' => $updatedCount,
                'total_found' => count($expiredSubscriptions)
            ], 'subscription-update');
            
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error("Ошибка при выполнении скрипта обновления статуса подписок", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'subscription-update');
            return Command::FAILURE;
        }
    }
} 