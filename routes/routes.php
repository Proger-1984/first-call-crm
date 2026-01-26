<?php

use App\Controllers\AdminSubscriptionController;
use App\Controllers\AuthController;
use App\Controllers\LocationPolygonController;
use App\Controllers\SubscriptionController;
use App\Controllers\TelegramAuthController;
use App\Controllers\UserController;
use App\Controllers\BillingController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

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
            $group->get('/download-info', [UserController::class, 'getDownloadInfo']);
            $group->get('/download/android', [UserController::class, 'downloadAndroidApp']);
        })->add(new AuthMiddleware($container));

        /** Маршруты для работы с подписками
         * Создание заявки на подписку - requestSubscription
         * Получение списка активных подписок пользователя - getUserSubscriptions
         * Получение всех подписок пользователя (для профиля) - getAllUserSubscriptions
         * Создание заявки на продление подписки - createExtendRequest
         */
        $group->group('/subscriptions', function (RouteCollectorProxy $group) {
            $group->post('', [SubscriptionController::class, 'requestSubscription']);
            $group->get('', [SubscriptionController::class, 'getUserSubscriptions']);
            $group->get('/all', [SubscriptionController::class, 'getAllUserSubscriptions']);
            $group->post('/extend-request', [SubscriptionController::class, 'createExtendRequest']);
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

        /** Маршруты каталога (требуют авторизации, но доступны всем пользователям)
         * Получение всей информации о тарифах (категории, локации, тарифы и цены) - getAllTariffInfo
         */
        $group->group('/catalog', function (RouteCollectorProxy $group) {
            $group->get('/tariff-info', [SubscriptionController::class, 'getAllTariffInfo']);
        })->add(new AuthMiddleware($container));

        /** Административное API для управления подписками (проверка роли admin в контроллере)
         * Активация подписки администратором - activateSubscription
         * Продление подписки - extendSubscription
         * Отмена подписки - cancelSubscription
         */
        $group->group('/admin/subscriptions', function (RouteCollectorProxy $group) use ($container) {
            $group->post('/activate', [AdminSubscriptionController::class, 'activateSubscription']);
            $group->post('/extend', [AdminSubscriptionController::class, 'extendSubscription']);
            $group->post('/cancel', [AdminSubscriptionController::class, 'cancelSubscription']);
        })->add(new AuthMiddleware($container));

        /** Маршруты для биллинг-панели
         * Для администраторов получение информации по всем подпискам - getCurrentSubscriptions
         * Для пользователей получение информации о собственных подписках - getUserSubscriptions
         * Для администраторов получение истории по подпискам - getSubscriptionHistory
         */
        $group->group('/billing', function (RouteCollectorProxy $group) use ($container) {
            // Общие маршруты для всех пользователей
            $group->post('/user-subscriptions', [BillingController::class, 'getUserSubscriptions']);
            
            // Маршруты только для администраторов
            $group->group('/admin', function (RouteCollectorProxy $group) {
                $group->post('/current-subscriptions', [BillingController::class, 'getCurrentSubscriptions']);
                $group->post('/subscription-history', [BillingController::class, 'getSubscriptionHistory']);
            });
        })->add(new AuthMiddleware($container));

    });
}; 