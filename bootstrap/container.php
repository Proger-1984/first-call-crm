<?php

declare(strict_types=1);

use App\Services\JwtService;
use App\Services\AuthService;
use App\Services\TelegramAuthService;
use App\Services\TelegramService;
use App\Services\UserSettingsService;
use App\Services\SubscriptionService;
use App\Services\LogService;
use App\Services\UserService;
use App\Services\ListingFilterService;
use App\Services\PhotoTaskService;
use App\Services\QrCodeService;
use App\Services\ClientService;
use App\Services\SourceAuthService;
use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\PipelineStageController;
use App\Controllers\TelegramAuthController;
use App\Controllers\UserController;
use App\Controllers\ListingController;
use App\Controllers\FavoriteController;
use App\Controllers\PhotoTaskController;
use App\Controllers\SourceAuthController;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use function DI\factory;

return function (array $config) {
    $containerBuilder = new ContainerBuilder();

    /** Определяем зависимости */
    $containerBuilder->addDefinitions([
        /** Сервисы */
        LoggerInterface::class => factory(function (ContainerInterface $c) use ($config) {
            $logger = new Logger($config['app']['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler(dirname(__DIR__) . '/logs/app.log', Logger::DEBUG);
            $logger->pushHandler($handler);

            return $logger;
        }),
        
        JwtService::class => factory(function (ContainerInterface $c) {
            return new JwtService($c);
        }),
        
        TelegramService::class => factory(function (ContainerInterface $c) {
            return new TelegramService($c);
        }),
        
        TelegramAuthService::class => factory(function (ContainerInterface $c) {
            return new TelegramAuthService($c);
        }),
        
        UserSettingsService::class => factory(function (ContainerInterface $c) {
            return new UserSettingsService($c);
        }),
        
        AuthService::class => factory(function (ContainerInterface $c) {
            return new AuthService($c);
        }),
        
        SubscriptionService::class => factory(function (ContainerInterface $c) {
            return new SubscriptionService($c);
        }),
        
        LogService::class => factory(function (ContainerInterface $c) {
            return new LogService($c->get(LoggerInterface::class));
        }),
        
        UserService::class => factory(function (ContainerInterface $c) {
            return new UserService();
        }),
        
        ListingFilterService::class => factory(function (ContainerInterface $c) {
            return new ListingFilterService();
        }),
        
        PhotoTaskService::class => factory(function (ContainerInterface $c) {
            return new PhotoTaskService($c->get(LoggerInterface::class));
        }),
        
        QrCodeService::class => factory(function (ContainerInterface $c) {
            return new QrCodeService($c->get(LoggerInterface::class));
        }),
        
        SourceAuthService::class => factory(function (ContainerInterface $c) {
            return new SourceAuthService($c->get(LoggerInterface::class));
        }),

        ClientService::class => factory(function (ContainerInterface $c) {
            return new ClientService();
        }),

        /** Контроллеры */
        AuthController::class => factory(function (ContainerInterface $c) {
            return new AuthController($c);
        }),
        
        TelegramAuthController::class => factory(function (ContainerInterface $c) {
            return new TelegramAuthController($c);
        }),
        
        UserController::class => factory(function (ContainerInterface $c) {
            return new UserController($c);
        }),
        
        ListingController::class => factory(function (ContainerInterface $c) {
            return new ListingController($c);
        }),
        
        FavoriteController::class => factory(function (ContainerInterface $c) {
            return new FavoriteController(
                $c->get(ListingFilterService::class)
            );
        }),
        
        PhotoTaskController::class => factory(function (ContainerInterface $c) {
            return new PhotoTaskController(
                $c->get(PhotoTaskService::class)
            );
        }),
        
        AnalyticsController::class => factory(function (ContainerInterface $c) {
            return new AnalyticsController($c);
        }),
        
        SourceAuthController::class => factory(function (ContainerInterface $c) {
            return new SourceAuthController(
                $c->get(SourceAuthService::class),
                $c->get(LoggerInterface::class)
            );
        }),

        ClientController::class => factory(function (ContainerInterface $c) {
            return new ClientController(
                $c->get(ClientService::class)
            );
        }),

        PipelineStageController::class => factory(function (ContainerInterface $c) {
            return new PipelineStageController();
        }),

        /** Конфигурация */
        'config' => factory(function () use ($config) {
            return $config;
        }),
    ]);

    /** Компилируем контейнер */
    return $containerBuilder->build();
}; 