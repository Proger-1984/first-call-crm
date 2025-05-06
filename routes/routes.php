<?php

use App\Controllers\AuthController;
use App\Controllers\TelegramAuthController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Получаем контейнер для передачи в AuthMiddleware
    $container = $app->getContainer();
    
    // API Routes v1
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container) {
        // Маршруты для аутентификации
        $group->group('/auth', function (RouteCollectorProxy $group) use ($container) {
            // Авторизация через Telegram
            $group->post('/telegram', [TelegramAuthController::class, 'authenticate']);

            // Авторизация через приложение
            $group->post('/login', [AuthController::class, 'login']);
            $group->get('/refresh', [AuthController::class, 'refresh']);
            $group->get('/logout', [AuthController::class, 'logout']);
        });

        // Маршруты конфигурации
        $group->group('/config', function (RouteCollectorProxy $group) {
            $group->get('/telegram-bot-username', [TelegramAuthController::class, 'getBotUsername']);
        });

        /** Защищенные маршруты (требуют аутентификации)
         * Маршруты пользователя
         */
        $group->group('/me', function (RouteCollectorProxy $group) {
            $group->get('/settings', [UserController::class, 'getSettings']);
            $group->put('/settings', [UserController::class, 'updateSettings']);
            $group->put('/phone-status', [UserController::class, 'updatePhoneStatus']);
        })->add(new AuthMiddleware($container));

    });
    
    // Тест API
    $app->get('/', function ($request, $response) {
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'API is working!'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });
}; 