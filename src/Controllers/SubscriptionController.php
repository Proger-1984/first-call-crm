<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Location;
use App\Models\SubscriptionHistory;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\SubscriptionService;
use App\Services\TelegramService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Carbon\Carbon;

class SubscriptionController
{
    use ResponseTrait;
    private TelegramService $telegramService;

    private SubscriptionService $subscriptionService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->subscriptionService = $container->get(SubscriptionService::class);
        $this->telegramService = $container->get(TelegramService::class);
    }
    
    /**
     * Получение списка активных подписок пользователя
     */
    public function getUserSubscriptions(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            $subscriptions = UserSubscription::with(['category', 'location'])
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->get()
                ->map(function ($subscription) {
                    return [
                        'id' => $subscription->id,
                        'location' => [
                            'id' => $subscription->location->id,
                            'name' => $subscription->location->getFullName() . ' | ' . $subscription->category->name,
                            'center_lat' => $subscription->location->center_lat,
                            'center_lng' => $subscription->location->center_lng,
                            'bounds' => $subscription->location->bounds
                        ]
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'subscriptions' => $subscriptions,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Валидация данных для создания подписки
     */
    private function validateSubscriptionRequest(array $data): string|null
    {
        if (!is_array($data)) {
            return 'Данные должны быть переданы в формате JSON';
        }

        if (!isset($data['tariff_id']) || !is_numeric($data['tariff_id'])) {
            return 'Отсутствует или неверный формат tariff_id';
        }
        
        if (!isset($data['category_id']) || !is_numeric($data['category_id'])) {
            return 'Отсутствует или неверный формат category_id';
        }
        
        if (!isset($data['location_id']) || !is_numeric($data['location_id'])) {
            return 'Отсутствует или неверный формат location_id';
        }
        
        return null;
    }
    
    /**
     * Создание заявки на подписку
     */
    public function requestSubscription(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            $validationError = $this->validateSubscriptionRequest($data);
            if ($validationError !== null) {
                $message = 'Неверный формат запроса. ' . $validationError;
                return $this->respondWithError($response, $message, 'validation_error', 400);
            }

            // Проверка существования тарифа и локации
            $tariff = Tariff::where('id', (int)$data['tariff_id'])->first();
            if (!$tariff) {
                return $this->respondWithError($response, 'Указанный тариф не найден', 'not_found', 404);
            }

            // Проверка существования локации
            $location = Location::where('id', (int)$data['location_id'])->first();
            if (!$location) {
                return $this->respondWithError($response, 'Указанная локация не найдена', 'not_found', 404);
            }
            
            // Проверка существования категории
            $categoryId = (int)$data['category_id'];
            $category = Category::find($categoryId);
            if (!$category) {
                return $this->respondWithError($response, 'Указанная категория не найдена', 'not_found', 404);
            }
            
            $user = User::findOrFail($userId);
            
            // Обработка демо-тарифа
            $isDemoTariff = $tariff->isDemo();
            if ($isDemoTariff && $user->hasUsedTrial()) {
                return $this->respondWithError($response, 'Вы уже использовали демо-тариф ранее', 'validation_error', 400);
            }
            
            // Проверяем особый случай: платный тариф и существующая демо-подписка
            $isDemoTariffUpgrade = false;
            if (!$isDemoTariff) { // Если это не демо-тариф (а платный)
                $existingDemo = UserSubscription::where('user_id', $userId)
                    ->where('status', 'active')
                    ->whereHas('tariff', function($query) {
                        $query->where('code', 'demo');
                    })
                    ->first();
                
                if ($existingDemo) {
                    // Нашли активную демо-подписку - отменяем ее перед созданием платной
                    $existingDemo->cancel('Автоматическая отмена при переходе на платный тариф');
                    $isDemoTariffUpgrade = true;
                }
            }

            // Стандартная проверка наличия активной подписки (пропускаем для случая апгрейда с демо)
            if (!$isDemoTariffUpgrade && $user->hasActiveSubscription($categoryId, (int)$data['location_id'])) {
                return $this->respondWithError(
                    $response,
                    'У вас уже есть активная подписка для этой категории и локации. Вы можете ее продлить через раздел Биллинг, или написав в поддержку.',
                    'subscription_exists',
                    400
                );
            }
            
            // Проверяем, есть ли у пользователя подписка с таким же статусом "pending"
            if ($user->hasPendingSubscription($categoryId, (int)$data['location_id'])) {
                return $this->respondWithError(
                    $response,
                    'У вас уже есть ожидающая подтверждения заявка на подписку для этой категории и локации. Напишите в поддержку для ее активации.',
                    'pending_subscription_exists',
                    400
                );
            }
            
            // Создаем подписку
            $subscription = $user->requestSubscription(
                (int)$data['tariff_id'], 
                $categoryId, 
                (int)$data['location_id']
            );

            if ($isDemoTariff) {
                $this->telegramService->notifyDemoSubscriptionCreated($user, $subscription);
                $user->is_trial_used = true;
                $user->save();
            } else {
                $this->telegramService->notifyPremiumSubscriptionRequested($user, $subscription);
                $this->telegramService->notifyAdminNewSubscriptionRequest($subscription);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Заявка на подписку успешно создана',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение всей информации о тарифах для страницы тарифов
     * Возвращает категории, локации, тарифы и их цены
     */
    public function getAllTariffInfo(Response $response): Response
    {
        try {
            // 1. Получаем все категории
            $categories = Category::all(['id', 'name']);
            
            // 2. Получаем все локации и сортируем по имени
            $locations = Location::all(['id', 'city', 'region'])
                ->map(function ($location) {
                    return [
                        'id' => $location->id,
                        'name' => $location->getFullName()
                    ];
                })
                ->sortBy('name')
                ->values();
                
            // 3. Получаем все активные тарифы с описанием и сортируем по имени
            $tariffs = $this->subscriptionService->getActiveTariffs()
                ->map(function ($tariff) {
                    return [
                        'id' => $tariff->id,
                        'name' => $tariff->name,
                        'description' => $tariff->description,
                    ];
                })
                ->sortBy('id')
                ->values();
            
            // 4. Получаем связку локация-тариф-цена для всех тарифов и локаций
            $tariffPrices = [];
            foreach ($locations as $location) {
                foreach ($tariffs as $tariff) {
                    $price = $this->subscriptionService->getTariffPrice($tariff['id'], $location['id']);
                    $tariffPrices[] = [
                        'tariff_id' => $tariff['id'],
                        'location_id' => $location['id'],
                        'price' => $price
                    ];
                }
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'categories' => $categories,
                    'locations' => $locations,
                    'tariffs' => $tariffs,
                    'tariff_prices' => $tariffPrices
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Создание заявки на продление подписки
     */
    public function createExtendRequest(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            // Проверяем наличие обязательных полей
            if (!isset($data['subscription_id']) || !is_numeric($data['subscription_id'])) {
                return $this->respondWithError($response, 'Необходимо указать ID подписки для продления', 'validation_error', 400);
            }
            
            if (!isset($data['tariff_id']) || !is_numeric($data['tariff_id'])) {
                return $this->respondWithError($response, 'Необходимо указать тариф для продления', 'validation_error', 400);
            }
            
            $subscriptionId = (int)$data['subscription_id'];
            
            // Проверяем наличие подписки и право пользователя на неё
            $subscription = UserSubscription::where('id', $subscriptionId)
                ->where('user_id', $userId)
                ->first();
                
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404);
            }

            if ($subscription->status != 'active1') {
                return $this->respondWithError($response, 'Продлить можно только активную подписку', 'validation_error', 400);
            }
            
            // Проверяем существование тарифа
            $tariff = Tariff::find($data['tariff_id']);
            if (!$tariff || !$tariff->is_active) {
                return $this->respondWithError($response, 'Указанный тариф не найден или неактивен', 'validation_error', 400);
            }
            
            // Отправка уведомления администратору через Telegram
            $user = User::find($userId);
            $this->telegramService->notifyAdminsAboutExtendRequest(
                $user, 
                $subscription, 
                $tariff, 
                $data['notes'] ?? null
            );

            // Отправка уведомления пользователю через Telegram
            $this->telegramService->notifyExtendSubscriptionRequested(
                $user,
                $subscription,
                $tariff
            );
            
            // Добавляем запись в историю подписок - опционально, для отслеживания
            SubscriptionHistory::create([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'action' => 'extend_requested',
                'tariff_name' => $tariff->name,
                'category_name' => $subscription->category->name,
                'location_name' => $subscription->location->getFullName(),
                'price_paid' => 0,
                'action_date' => Carbon::now(),
                'notes' => $data['notes'] ?? 'Запрос на продление подписки'
            ]);
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Заявка на продление подписки успешно отправлена администратору',
                'data' => [
                    'subscription_id' => $subscriptionId
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'internal_error', 500);
        }
    }
} 