<?php

use App\Controllers\AdminSubscriptionController;
use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\FavoriteController;
use App\Controllers\FavoriteStatusController;
use App\Controllers\FilterController;
use App\Controllers\ListingController;
use App\Controllers\LocationPolygonController;
use App\Controllers\PhotoTaskController;
use App\Controllers\SubscriptionController;
use App\Controllers\TelegramAuthController;
use App\Controllers\UserController;
use App\Controllers\BillingController;
use App\Middleware\AuthMiddleware;
use App\Middleware\SubscriptionMiddleware;
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

        /** Маршруты пользователя, доступные без активной подписки
         * Получение полной информации о пользователе - getUserInfo
         * Получение статуса телефона и автозвонка - getUserStatus
         * Получение информации о скачивании - getDownloadInfo
         * Скачивание Android приложения - downloadAndroidApp
         */
        $group->group('/me', function (RouteCollectorProxy $group) {
            $group->get('/info', [UserController::class, 'getUserInfo']);
            $group->get('/status', [UserController::class, 'getUserStatus']);
            $group->get('/download-info', [UserController::class, 'getDownloadInfo']);
            $group->get('/download/android', [UserController::class, 'downloadAndroidApp']);
        })->add(new AuthMiddleware($container));

        /** Маршруты пользователя, требующие активную подписку
         * Получение настроек пользователя - getSettings
         * Обновление настроек пользователя - updateSettings
         * Обновление статуса телефона пользователя - updatePhoneStatus
         * Обновление статуса автозвонка - updateAutoCall
         * Обновление статуса автозвонка с приоритетом - updateAutoCallRaised
         * Получение логина для приложения - getAppLogin
         * Генерация нового пароля для приложения - generatePassword
         */
        $group->group('/me', function (RouteCollectorProxy $group) {
            $group->get('/settings', [UserController::class, 'getSettings']);
            $group->put('/settings', [UserController::class, 'updateSettings']);
            $group->put('/phone-status', [UserController::class, 'updatePhoneStatus']);
            $group->put('/auto-call', [UserController::class, 'updateAutoCall']);
            $group->put('/auto-call-raised', [UserController::class, 'updateAutoCallRaised']);
            $group->get('/app-login', [UserController::class, 'getAppLogin']);
            $group->post('/generate-password', [UserController::class, 'generatePassword']);
        })->add(new SubscriptionMiddleware())->add(new AuthMiddleware($container));

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
         * 
         * Требует активную подписку (SubscriptionMiddleware)
         */
        $group->group('/location-polygons', function (RouteCollectorProxy $group) {
            $group->get('/subscription/{subscription_id:[0-9]+}', [LocationPolygonController::class, 'getLocationPolygonsBySubscription']);
            $group->post('', [LocationPolygonController::class, 'createLocationPolygon']);
            $group->put('/{id:[0-9]+}', [LocationPolygonController::class, 'updateLocationPolygon']);
            $group->delete('/{id:[0-9]+}', [LocationPolygonController::class, 'deleteLocationPolygon']);
        })->add(new SubscriptionMiddleware())->add(new AuthMiddleware($container));

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
            $group->post('/create', [AdminSubscriptionController::class, 'createSubscription']);
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

        /** Маршруты для аналитики (только для администраторов)
         * Получение данных для графиков - getChartsData
         * Получение сводной статистики - getSummary
         */
        $group->group('/admin/analytics', function (RouteCollectorProxy $group) {
            $group->post('/charts', [AnalyticsController::class, 'getChartsData']);
            $group->get('/summary', [AnalyticsController::class, 'getSummary']);
        })->add(new AuthMiddleware($container));

        /** Маршруты для получения данных фильтров
         * Возвращает категории, локации, метро, комнаты, источники, статусы
         * с учётом подписок пользователя
         * 
         * Требует активную подписку (SubscriptionMiddleware)
         */
        $group->group('/filters', function (RouteCollectorProxy $group) {
            $group->get('', [FilterController::class, 'getFilters']);
        })->add(new SubscriptionMiddleware())->add(new AuthMiddleware($container));

        /** Маршруты для работы с объявлениями
         * Получение списка объявлений с фильтрацией и пагинацией - getListings
         * Получение статистики по объявлениям - getStats
         * Получение одного объявления по ID - getListing
         * Обновление статуса объявления - updateStatus
         * 
         * Требует активную подписку (SubscriptionMiddleware)
         */
        $group->group('/listings', function (RouteCollectorProxy $group) {
            $group->post('', [ListingController::class, 'getListings']);
            $group->get('/stats', [ListingController::class, 'getStats']);
            $group->get('/{id:[0-9]+}', [ListingController::class, 'getListing']);
            $group->patch('/{id:[0-9]+}/status', [ListingController::class, 'updateStatus']);
        })->add(new SubscriptionMiddleware())->add(new AuthMiddleware($container));

        /**
         * Маршруты для обработки фото (удаление водяных знаков)
         * Создать задачу - create
         * Скачать архив - download
         * 
         * Требует активную подписку (SubscriptionMiddleware)
         */
        $group->group('/photo-tasks', function (RouteCollectorProxy $group) {
            $group->post('', [PhotoTaskController::class, 'create']);
            $group->get('/{id:[0-9]+}/download', [PhotoTaskController::class, 'download']);
        })->add(new SubscriptionMiddleware())->add(new AuthMiddleware($container));

        /** Маршруты для работы с избранным
         * Получение списка избранных объявлений - index
         * Добавить/удалить из избранного (toggle) - toggle
         * Проверить, в избранном ли объявление - check
         * Получить количество избранных - count
         * Обновить комментарий - updateComment
         * Обновить статус избранного - updateStatus
         * 
         * Управление пользовательскими статусами:
         * Получить все статусы - FavoriteStatusController::index
         * Создать статус - FavoriteStatusController::create
         * Обновить статус - FavoriteStatusController::update
         * Удалить статус - FavoriteStatusController::delete
         * Изменить порядок статусов - FavoriteStatusController::reorder
         * 
         * Требует активную подписку (SubscriptionMiddleware)
         */
        $group->group('/favorites', function (RouteCollectorProxy $group) {
            $group->get('', [FavoriteController::class, 'index']);
            $group->post('/toggle', [FavoriteController::class, 'toggle']);
            $group->get('/check/{id:[0-9]+}', [FavoriteController::class, 'check']);
            $group->get('/count', [FavoriteController::class, 'count']);
            $group->put('/comment', [FavoriteController::class, 'updateComment']);
            $group->put('/status', [FavoriteController::class, 'updateStatus']);
            
            // Управление статусами
            $group->get('/statuses', [FavoriteStatusController::class, 'index']);
            $group->post('/statuses', [FavoriteStatusController::class, 'create']);
            $group->put('/statuses/reorder', [FavoriteStatusController::class, 'reorder']);
            $group->put('/statuses/{id:[0-9]+}', [FavoriteStatusController::class, 'update']);
            $group->delete('/statuses/{id:[0-9]+}', [FavoriteStatusController::class, 'delete']);
        })->add(new SubscriptionMiddleware())->add(new AuthMiddleware($container));

    });
}; 