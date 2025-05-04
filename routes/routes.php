<?php

use App\Controllers\AuthController;
use App\Controllers\TelegramAuthController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    /** Получаем контейнер для передачи в AuthMiddleware */
    $container = $app->getContainer();
    
    /** API Routes v1 */
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container) {
    /** Маршруты для аутентификации */
        $group->group('/auth', function (RouteCollectorProxy $group) use ($container) {
            /** Telegram Auth */
            $group->post('/telegram', [TelegramAuthController::class, 'authenticate']);

            /** App Auth */
            $group->post('/login', [AuthController::class, 'login']);
            $group->post('/refresh', [AuthController::class, 'refresh']);
            $group->post('/logout', [AuthController::class, 'logout']);
            $group->post('/logout-all', [AuthController::class, 'logoutAll'])->add(new AuthMiddleware($container));
        });

        /** Маршруты конфигурации */
        $group->group('/config', function (RouteCollectorProxy $group) {
            $group->get('/telegram-bot-username', [TelegramAuthController::class, 'getBotUsername']);
        });

        /** Маршруты словарей */
        $group->group('/dictionary', function (RouteCollectorProxy $group) {
            $group->get('/sources', [UserController::class, 'getSources']);
            $group->get('/categories', [UserController::class, 'getCategories']);
        });

        /** Защищенные маршруты (требуют аутентификации) */
        $group->group('', function (RouteCollectorProxy $group) {
            /** Маршруты пользователя */
            $group->group('/users', function (RouteCollectorProxy $group) {
                $group->get('/settings', [UserController::class, 'getSettings']);
                $group->put('/settings', [UserController::class, 'updateSettings']);
            });
        })->add(new AuthMiddleware($container));
    });

    /** Маршруты для веб-интерфейса авторизации через Telegram */
    $app->get('/telegram-login', function ($request, $response) {
        return $response->withHeader('Location', '/telegram-login.html')->withStatus(302);
    });
    
    /** Главная страница */
    $app->get('/', function ($request, $response) {
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'API is working!'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });
}; 