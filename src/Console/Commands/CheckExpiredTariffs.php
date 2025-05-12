<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class CheckExpiredTariffs extends Command
{
    protected $signature = 'subscriptions:check-expired';
    protected $description = 'Check and mark expired subscriptions';

    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        parent::__construct();
        $this->subscriptionService = $subscriptionService;
    }

    public function handle()
    {
        $count = $this->subscriptionService->checkExpiredSubscriptions();
        $this->info("Processed {$count} expired subscriptions");
        
        // Проверяем подписки, которые скоро истекут (через 3 дня или меньше)
        $soonExpiring = $this->subscriptionService->getSoonExpiringSubscriptions(3);
        $this->info("Found {$soonExpiring->count()} subscriptions expiring soon");
        
        foreach ($soonExpiring as $subscription) {
            $this->line(
                "Subscription ID: {$subscription->id}, " .
                "User: {$subscription->user->name}, " .
                "Category: {$subscription->category->name}, " .
                "Location: {$subscription->location->getFullName()}, " .
                "Expires: {$subscription->end_date->format('Y-m-d H:i:s')}"
            );
            // Здесь можно добавить логику отправки уведомлений пользователям
        }
    }
} 