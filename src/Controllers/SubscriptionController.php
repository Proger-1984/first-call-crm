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
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SubscriptionController
{
    use ResponseTrait;

    private SubscriptionService $subscriptionService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->subscriptionService = $container->get(SubscriptionService::class);
    }
    
    /**
     * Получение списка активных подписок пользователя
     */
    public function getUserSubscriptions(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            $subscriptions = UserSubscription::with(['tariff', 'category', 'location'])
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->get()
                ->map(function ($subscription) {
                    $remainingTime = $this->subscriptionService->getRemainingTime($subscription);
                    return [
                        'id' => $subscription->id,
                        'tariff' => [
                            'id' => $subscription->tariff->id,
                            'name' => $subscription->tariff->name,
                            'code' => $subscription->tariff->code,
                        ],
                        'category' => [
                            'id' => $subscription->category->id,
                            'name' => $subscription->category->name,
                        ],
                        'location' => [
                            'id' => $subscription->location->id,
                            'name' => $subscription->location->getFullName(),
                        ],
                        'price_paid' => $subscription->price_paid,
                        'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                        'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                        'status' => $subscription->status,
                        'remaining_seconds' => $remainingTime,
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
     * Получение истории подписок пользователя
     */
    public function getSubscriptionHistory(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            $history = SubscriptionHistory::where('user_id', $userId)
                ->orderBy('action_date', 'desc')
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'subscription_id' => $record->subscription_id,
                        'action' => $record->action,
                        'tariff_name' => $record->tariff_name,
                        'category_name' => $record->category_name,
                        'location_name' => $record->location_name,
                        'price_paid' => $record->price_paid,
                        'action_date' => $record->action_date->format('Y-m-d H:i:s'),
                        'notes' => $record->notes,
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'history' => $history,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение доступных тарифов
     */
    public function getAvailableTariffs(Request $request, Response $response): Response
    {
        try {
            $tariffs = $this->subscriptionService->getActiveTariffs()
                ->map(function ($tariff) {
                    return [
                        'id' => $tariff->id,
                        'name' => $tariff->name,
                        'code' => $tariff->code,
                        'duration_hours' => $tariff->duration_hours,
                        'price' => $tariff->price,
                        'description' => $tariff->description,
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'tariffs' => $tariffs,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение списка доступных категорий
     */
    public function getCategories(Request $request, Response $response): Response
    {
        try {
            $categories = Category::all(['id', 'name']);
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'categories' => $categories,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение списка доступных локаций
     */
    public function getLocations(Request $request, Response $response): Response
    {
        try {
            $locations = Location::all()
                ->map(function ($location) {
                    return [
                        'id' => $location->id,
                        'city' => $location->city,
                        'region' => $location->region,
                        'full_name' => $location->getFullName(),
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'locations' => $locations,
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
        
        if (!isset($data['category_ids']) || !is_array($data['category_ids']) || empty($data['category_ids'])) {
            return 'Отсутствует или неверный формат category_ids. Должен быть массив с идентификаторами категорий';
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
            
            // Проверка существования всех категорий
            $categoryIds = array_map('intval', $data['category_ids']);
            $existingCategories = Category::whereIn('id', $categoryIds)->pluck('id')->toArray();
            
            // Проверяем, что все запрошенные ID категорий действительно найдены в базе
            $missingCategoryIds = array_diff($categoryIds, $existingCategories);
            if (!empty($missingCategoryIds)) {
                $message = 'Неверный формат запроса. Следующие категории не существуют: ' . implode(', ', $missingCategoryIds);
                return $this->respondWithError($response, $message, 'validation_error', 400);
            }
            
            $user = User::findOrFail($userId);
            $subscriptions = [];
            
            // Обработка демо-тарифа
            $isDemoTariff = $tariff->isDemo();
            if ($isDemoTariff && $user->hasUsedTrial()) {
                return $this->respondWithError($response, 'Вы уже использовали демо-тариф ранее', 'validation_error', 400);
            }
            
            // Если это демо-тариф, ограничиваем до одной категории
            if ($isDemoTariff && count($categoryIds) > 1) {
                return $this->respondWithError($response, 'Для демо-тарифа доступна только одна категория', 'validation_error', 400);
            }
            
            // Создаем подписки для всех выбранных категорий
            foreach ($categoryIds as $categoryId) {
                // Проверяем особый случай: платный тариф и существующая демо-подписка
                $isDemoTariffUpgrade = false;
                if (!$isDemoTariff) { // Если это не демо-тариф (а платный)
                    $existingDemo = UserSubscription::where('user_id', $userId)
                        ->where('category_id', $categoryId)
                        ->where('location_id', (int)$data['location_id'])
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
                    continue; // Пропускаем, если уже есть активная подписка и это не апгрейд с демо
                }
                
                // Проверяем, есть ли у пользователя подписка с таким же статусом "pending"
                if ($user->hasPendingSubscription($categoryId, (int)$data['location_id'])) {
                    continue; // Пропускаем, если уже есть ожидающая подписка
                }
                
                $subscription = $user->requestSubscription(
                    (int)$data['tariff_id'], 
                    $categoryId, 
                    (int)$data['location_id']
                );
                
                $subscriptions[] = $subscription;
            }
            
            // Если не создано ни одной подписки
            if (empty($subscriptions)) {
                return $this->respondWithError($response, 'Не удалось создать подписки. Возможно, они уже существуют', 'operation_failed', 400);
            }
            
            // Устанавливаем флаг использования демо, если это демо-тариф
            if ($isDemoTariff) {
                $user->is_trial_used = true;
                $user->save();
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Заявки на подписку успешно созданы',
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение конкретной подписки
     */
    public function getSubscription(Request $request, Response $response, array $args = []): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $subscriptionId = isset($args['id']) ? (int)$args['id'] : null;
            
            if (!$subscriptionId) {
                return $this->respondWithError($response, 'Не указан ID подписки', 'validation_error', 400);
            }
            
            $subscription = UserSubscription::with(['tariff', 'category', 'location'])
                ->where('id', $subscriptionId)
                ->where('user_id', $userId)
                ->first();
            
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404);
            }
            
            $remainingTime = $this->subscriptionService->getRemainingTime($subscription);
            
            $subscriptionData = [
                'id' => $subscription->id,
                'tariff' => [
                    'id' => $subscription->tariff->id,
                    'name' => $subscription->tariff->name,
                    'code' => $subscription->tariff->code,
                ],
                'category' => [
                    'id' => $subscription->category->id,
                    'name' => $subscription->category->name,
                ],
                'location' => [
                    'id' => $subscription->location->id,
                    'name' => $subscription->location->getFullName(),
                ],
                'price_paid' => $subscription->price_paid,
                'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                'status' => $subscription->status,
                'payment_method' => $subscription->payment_method,
                'remaining_seconds' => $remainingTime,
            ];
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'subscription' => $subscriptionData,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение цены тарифа для локации
     */
    public function getTariffPrice(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tariffId = isset($args['tariffId']) ? (int)$args['tariffId'] : null;
            $locationId = isset($args['locationId']) ? (int)$args['locationId'] : null;
            
            if (!$tariffId || !$locationId) {
                return $this->respondWithError($response, 'Не указаны необходимые параметры', 'validation_error', 400);
            }
            
            $price = $this->subscriptionService->getTariffPrice($tariffId, $locationId);
            
            if ($price === null) {
                return $this->respondWithError($response, 'Цена не найдена', 'not_found', 404);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'price' => $price,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Отмена подписки
     */
    public function cancelSubscription(Request $request, Response $response, array $args = []): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $subscriptionId = isset($args['id']) ? (int)$args['id'] : null;
            
            if (!$subscriptionId) {
                return $this->respondWithError($response, 'Не указан ID подписки', 'validation_error', 400);
            }
            
            $subscription = UserSubscription::where('id', $subscriptionId)
                ->where('user_id', $userId)
                ->first();
            
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404);
            }
            
            $data = $request->getParsedBody();
            $reason = $data['reason'] ?? 'Отменено пользователем';
            
            $result = $subscription->cancel($reason);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось отменить подписку', 'operation_failed', 400);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Подписка успешно отменена',
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Временное включение/отключение подписки пользователем
     */
    public function toggleSubscription(Request $request, Response $response, array $args = []): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $subscriptionId = isset($args['id']) ? (int)$args['id'] : null;
            
            if (!$subscriptionId) {
                return $this->respondWithError($response, 'Не указан ID подписки', 'validation_error', 400);
            }
            
            $subscription = UserSubscription::where('id', $subscriptionId)
                ->where('user_id', $userId)
                ->first();
            
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404);
            }
            
            if ($subscription->status !== 'active') {
                return $this->respondWithError($response, 'Только активные подписки могут быть включены/отключены', 'invalid_status', 400);
            }
            
            $data = $request->getParsedBody();
            $isEnabled = isset($data['is_enabled']) ? (bool)$data['is_enabled'] : !$subscription->is_enabled;
            
            $result = $subscription->toggleEnabled($isEnabled);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось изменить статус подписки', 'operation_failed', 400);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => $isEnabled ? 'Подписка успешно включена' : 'Подписка успешно отключена',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'is_enabled' => $isEnabled
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
} 