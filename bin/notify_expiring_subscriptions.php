<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Commands\NotifyExpiringSubscriptionsCommand;
use App\Services\SubscriptionService;
use App\Services\TelegramService;

// Получаем контейнер зависимостей
$container = require __DIR__ . '/../bootstrap/container.php';

// Получаем необходимые сервисы из контейнера
$subscriptionService = $container->get(SubscriptionService::class);
$telegramService = $container->get(TelegramService::class);

// Создаем и запускаем команду
$command = new NotifyExpiringSubscriptionsCommand($subscriptionService, $telegramService);
$result = $command->execute();

// Выводим результат
echo $result['message'] . PHP_EOL;

// Код возврата для скриптов автоматизации
exit($result['success'] ? 0 : 1); 