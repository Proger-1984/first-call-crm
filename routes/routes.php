<?php

use App\Controllers\AdminSubscriptionController;
use App\Controllers\AuthController;
use App\Controllers\LocationPolygonController;
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

        /** Маршруты для аутентификации
         * Авторизация/Регистрация через Telegram - authenticate
         * Перепривязка Telegram - rebindTelegram
         * Авторизация через приложение - login
         * Обновление токена access_token - refresh
         * Выход из системы - logout
         * Выход со всех устройств - logoutAll
         */
        $group->group('/auth', function (RouteCollectorProxy $group) use ($container) {
            $group->post('/telegram', [TelegramAuthController::class, 'authenticate']);
            $group->post('/telegram/rebind', [TelegramAuthController::class, 'rebindTelegram'])
                ->add(new AuthMiddleware($container));

            $group->post('/login', [AuthController::class, 'login']);
            $group->get('/refresh', [AuthController::class, 'refresh']);
            $group->get('/logout', [AuthController::class, 'logout'])
                ->add(new AuthMiddleware($container));
            $group->get('/logout-all', [AuthController::class, 'logoutAll'])
                ->add(new AuthMiddleware($container));
        });

        /** Маршруты конфигурации */
        $group->group('/config', function (RouteCollectorProxy $group) {
            $group->get('/telegram-bot-username', [TelegramAuthController::class, 'getBotUsername']);
        });

        /** Защищенные маршруты пользователя (требуют аутентификации)
         * Получение настроек пользователя - getSettings
         * Обновление настроек пользователя - updateSettings
         * Обновление статуса телефона пользователя - updatePhoneStatus
         * Получение полной информации о пользователе - getUserInfo
         * Получение статуса телефона и автозвонка - getUserStatus
         * Обновление статуса автозвонка - updateAutoCall
         * Получение логина для приложения - getAppLogin
         * Генерация нового пароля для приложения - generatePassword
         */
        $group->group('/me', function (RouteCollectorProxy $group) {
            $group->get('/settings', [UserController::class, 'getSettings']);
            $group->put('/settings', [UserController::class, 'updateSettings']);
            $group->put('/phone-status', [UserController::class, 'updatePhoneStatus']);
            $group->get('/info', [UserController::class, 'getUserInfo']);
            $group->get('/status', [UserController::class, 'getUserStatus']);
            $group->put('/auto-call', [UserController::class, 'updateAutoCall']);
            $group->put('/auto-call-raised', [UserController::class, 'updateAutoCallRaised']);
            $group->get('/app-login', [UserController::class, 'getAppLogin']);
            $group->post('/generate-password', [UserController::class, 'generatePassword']);
        })->add(new AuthMiddleware($container));

        /** Маршруты для работы с подписками
         * Создание заявки на подписку - requestSubscription
         * Получение списка активных подписок пользователя - getUserSubscriptions
         */
        $group->group('/subscriptions', function (RouteCollectorProxy $group) {
            $group->post('', [SubscriptionController::class, 'requestSubscription']);
            $group->get('', [SubscriptionController::class, 'getUserSubscriptions']);
                
            // Получение истории подписок пользователя
            $group->get('/history', [SubscriptionController::class, 'getSubscriptionHistory']);
                
            // Получение конкретной подписки
            $group->get('/{id:[0-9]+}', [SubscriptionController::class, 'getSubscription']);
                
            // Отмена подписки
            $group->delete('/{id:[0-9]+}', [SubscriptionController::class, 'cancelSubscription']);
        })->add(new AuthMiddleware($container));

        /** Маршруты для работы с пользовательскими локациями
         * Получение локаций пользователя по ID подписки - getLocationPolygonsBySubscription
         * Создание новой локации - createLocationPolygon
         * Обновление существующей локации - updateLocationPolygon
         * Удаление локации - deleteLocationPolygon
         */
        $group->group('/location-polygons', function (RouteCollectorProxy $group) {
            $group->get('/subscription/{subscription_id:[0-9]+}', [LocationPolygonController::class, 'getLocationPolygonsBySubscription']);
            $group->post('', [LocationPolygonController::class, 'createLocationPolygon']);
            $group->put('/{id:[0-9]+}', [LocationPolygonController::class, 'updateLocationPolygon']);
            $group->delete('/{id:[0-9]+}', [LocationPolygonController::class, 'deleteLocationPolygon']);
        })->add(new AuthMiddleware($container));
            
        // Маршруты каталога (требуют авторизации, но доступны всем пользователям)
        $group->group('/catalog', function (RouteCollectorProxy $group) {
            // Получение доступных тарифов
           $group->get('/tariffs', [SubscriptionController::class, 'getAvailableTariffs']);
                
            // Получение доступных категорий
           $group->get('/categories', [SubscriptionController::class, 'getCategories']);
                
            // Получение доступных локаций
           $group->get('/locations', [SubscriptionController::class, 'getLocations']);
                
            // Получение цены тарифа для локации
           $group->get('/tariff-price/{tariffId:[0-9]+}/{locationId:[0-9]+}', [SubscriptionController::class, 'getTariffPrice']);
        })->add(new AuthMiddleware($container));

        /** Административное API для управления подписками (проверка роли admin в контроллере)
         * Активация подписок, принимает массив ID в теле запроса - activateSubscriptions
         */
        $group->group('/admin/subscriptions', function (RouteCollectorProxy $group) use ($container) {
            $group->post('/activate', [AdminSubscriptionController::class, 'activateSubscriptions']);


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