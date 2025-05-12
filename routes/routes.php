<?php

use App\Controllers\AdminSubscriptionController;
use App\Controllers\AuthController;
use App\Controllers\SubscriptionController;
use App\Controllers\TelegramAuthController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

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

        /** Защищенные маршруты (требуют аутентификации) */
        // Маршруты пользователя
        $group->group('/me', function (RouteCollectorProxy $group) {
            $group->get('/settings', [UserController::class, 'getSettings']);
            $group->put('/settings', [UserController::class, 'updateSettings']);
            $group->put('/phone-status', [UserController::class, 'updatePhoneStatus']);
        })->add(new AuthMiddleware($container));
            
        // Маршруты для работы с подписками
        $group->group('/subscriptions', function (RouteCollectorProxy $group) {
            // Создание заявки на подписку
            $group->post('', [SubscriptionController::class, 'requestSubscription']);

            // Получение списка активных подписок пользователя
            $group->get('', [SubscriptionController::class, 'getUserSubscriptions']);
                
            // Получение истории подписок пользователя
            $group->get('/history', [SubscriptionController::class, 'getSubscriptionHistory']);
                
            // Получение конкретной подписки
            $group->get('/{id:[0-9]+}', [SubscriptionController::class, 'getSubscription']);
                
            // Отмена подписки
            $group->delete('/{id:[0-9]+}', [SubscriptionController::class, 'cancelSubscription']);
        })->add(new AuthMiddleware($container));
            
        // Маршруты каталога (требуют авторизации, но доступны всем пользователям)
        $group->group('/catalog', function (RouteCollectorProxy $group) {
            // Получение доступных тарифов
           // $group->get('/tariffs', [SubscriptionController::class, 'getAvailableTariffs']);
                
            // Получение доступных категорий
          //  $group->get('/categories', [SubscriptionController::class, 'getCategories']);
                
            // Получение доступных локаций
           // $group->get('/locations', [SubscriptionController::class, 'getLocations']);
                
            // Получение цены тарифа для локации
          //  $group->get('/tariff-price/{tariffId:[0-9]+}/{locationId:[0-9]+}', [SubscriptionController::class, 'getTariffPrice']);
        })->add(new AuthMiddleware($container));
            
        // Административное API для управления подписками (проверка роли admin в контроллере)
        $group->group('/admin/subscriptions', function (RouteCollectorProxy $group) use ($container) {
            // Получение всех подписок
          //  $group->get('', [AdminSubscriptionController::class, 'getAllSubscriptions']);
                
            // Получение всех ожидающих подтверждения подписок
          //  $group->get('/pending', [AdminSubscriptionController::class, 'getPendingSubscriptions']);
                
            // Получение всей истории подписок
          //  $group->get('/history', [AdminSubscriptionController::class, 'getAllSubscriptionHistory']);
                
            // Получение подписок конкретного пользователя
           // $group->get('/user/{userId:[0-9]+}', [AdminSubscriptionController::class, 'getUserSubscriptions']);
                
            // Создание подписки для пользователя
          //  $group->post('', [AdminSubscriptionController::class, 'createSubscription']);
                
            // Активация подписок - теперь принимает массив ID в теле запроса
            $group->post('/activate', [AdminSubscriptionController::class, 'activateSubscriptions']);
                
            // Продление подписки
          //  $group->post('/{id:[0-9]+}/extend', [AdminSubscriptionController::class, 'extendSubscription']);
                
            // Отмена подписки
           // $group->delete('/{id:[0-9]+}', [AdminSubscriptionController::class, 'cancelSubscription']);
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