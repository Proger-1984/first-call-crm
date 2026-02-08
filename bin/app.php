#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Commands\MetroStationsParserCommand;
use App\Commands\NotifySubscriptionExpiringCommand;
use App\Commands\NotifySubscriptionExpiredCommand;
use App\Commands\ParseCianMultiThreadCommand;
use App\Commands\PhotoTasksCommand;
use App\Commands\TransferListingsCommand;
use App\Commands\UpdateExpiredSubscriptionsCommand;
use Symfony\Component\Console\Application;

try {
    // Загружаем конфигурацию приложения
    $config = require __DIR__ . '/../bootstrap/app.php';
    
    // Загружаем контейнер зависимостей
    $containerLoader = require __DIR__ . '/../bootstrap/container.php';
    $container = $containerLoader($config);
    
    // Создаем приложение консоли
    $cli = new Application('Console');
    
    // Добавляем команды
    $cli->add(new MetroStationsParserCommand($container));
    $cli->add(new NotifySubscriptionExpiringCommand($container));
    $cli->add(new NotifySubscriptionExpiredCommand($container));
    $cli->add(new UpdateExpiredSubscriptionsCommand($container));
    $cli->add(new ParseCianMultiThreadCommand($container));
    $cli->add(new TransferListingsCommand($container));
    $cli->add(new PhotoTasksCommand(
        $container->get(\App\Services\PhotoTaskService::class),
        $container->get(\Psr\Log\LoggerInterface::class)
    ));
    
    $cli->run();

} catch (Throwable $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
} 