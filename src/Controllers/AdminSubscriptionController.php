<?php

declare(strict_types=1);

namespace App\Controllers;

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

class AdminSubscriptionController
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
     * Проверка прав администратора
     */
    private function checkAdminAccess(Request $request, Response $response): ?Response
    {
        $userRole = $request->getAttribute('userRole', 'user');
        
        if ($userRole !== 'admin') {
            return $this->respondWithError(
                $response,
                'У вас нет доступа к этому ресурсу',
                'access_denied',
                403,
                null
            );
        }
        
        return null;
    }
    
    /**
     * Получение списка всех подписок
     */
    public function getAllSubscriptions(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $subscriptions = UserSubscription::with(['user', 'tariff', 'category', 'location', 'approver'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($subscription) {
                    return [
                        'id' => $subscription->id,
                        'user' => [
                            'id' => $subscription->user->id,
                            'name' => $subscription->user->name,
                            'email' => $subscription->user->email,
                            'phone' => $subscription->user->phone,
                        ],
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
                        'admin_notes' => $subscription->admin_notes,
                        'approved_by' => $subscription->approved_by 
                            ? [
                                'id' => $subscription->approver->id,
                                'name' => $subscription->approver->name,
                              ]
                            : null,
                        'approved_at' => $subscription->approved_at?->format('Y-m-d H:i:s'),
                        'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
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
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Получение списка ожидающих подтверждения подписок
     */
    public function getPendingSubscriptions(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $pendingSubscriptions = $this->subscriptionService->getPendingSubscriptions()
                ->map(function ($subscription) {
                    return [
                        'id' => $subscription->id,
                        'user' => [
                            'id' => $subscription->user->id,
                            'name' => $subscription->user->name,
                            'email' => $subscription->user->email,
                            'phone' => $subscription->user->phone,
                        ],
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
                        'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'pending_subscriptions' => $pendingSubscriptions,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Получение всех подписок конкретного пользователя
     */
    public function getUserSubscriptions(Request $request, Response $response, array $args = []): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $userId = isset($args['userId']) ? (int)$args['userId'] : null;
            
            if (!$userId) {
                return $this->respondWithError($response, 'Не указан ID пользователя', 'validation_error', 400, null);
            }
            
            $user = User::find($userId);
            if (!$user) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404, null);
            }
            
            $subscriptions = UserSubscription::with(['tariff', 'category', 'location', 'approver'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
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
                        'payment_method' => $subscription->payment_method,
                        'admin_notes' => $subscription->admin_notes,
                        'approved_by' => $subscription->approved_by 
                            ? [
                                'id' => $subscription->approver->id,
                                'name' => $subscription->approver->name,
                              ]
                            : null,
                        'approved_at' => $subscription->approved_at?->format('Y-m-d H:i:s'),
                        'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                        'remaining_seconds' => $remainingTime,
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                    ],
                    'subscriptions' => $subscriptions,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Валидация данных для активации подписки
     */
    private function validateActivationData(array $data): string|null
    {
        if (!isset($data['payment_method']) || empty($data['payment_method'])) {
            return 'Отсутствует обязательное поле payment_method';
        }
        
        if (isset($data['duration_hours']) && !is_numeric($data['duration_hours'])) {
            return 'Неверный формат duration_hours';
        }
        
        return null;
    }
    
    /**
     * Активация подписки администратором
     */
    public function activateSubscription(Request $request, Response $response, array $args = []): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $subscriptionId = isset($args['id']) ? (int)$args['id'] : null;
            
            if (!$subscriptionId) {
                return $this->respondWithError($response, 'Не указан ID подписки', 'validation_error', 400, null);
            }
            
            $subscription = UserSubscription::find($subscriptionId);
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404, null);
            }
            
            $data = $request->getParsedBody();
            
            $validationError = $this->validateActivationData($data);
            if ($validationError !== null) {
                return $this->respondWithError($response, $validationError, 'validation_error', 400, null);
            }
            
            $paymentMethod = $data['payment_method'];
            $notes = $data['notes'] ?? null;
            $durationHours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
            
            $result = $subscription->activate($adminId, $paymentMethod, $notes, $durationHours);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось активировать подписку', 'operation_failed', 400, null);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Подписка успешно активирована',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                    'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                    'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Продление подписки администратором
     */
    public function extendSubscription(Request $request, Response $response, array $args = []): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $subscriptionId = isset($args['id']) ? (int)$args['id'] : null;
            
            if (!$subscriptionId) {
                return $this->respondWithError($response, 'Не указан ID подписки', 'validation_error', 400, null);
            }
            
            $subscription = UserSubscription::find($subscriptionId);
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404, null);
            }
            
            $data = $request->getParsedBody();
            $newPrice = isset($data['price']) ? (float)$data['price'] : null;
            $paymentMethod = $data['payment_method'] ?? null;
            $notes = $data['notes'] ?? null;
            $durationHours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
            
            // Вызываем метод продления подписки
            // Предполагается что такой метод уже есть в UserSubscription
            // Если нет, нужно его реализовать
            $result = $subscription->extendByAdmin($adminId, $newPrice, $paymentMethod, $notes, $durationHours);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось продлить подписку', 'operation_failed', 400, null);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Подписка успешно продлена',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                    'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Отмена подписки администратором
     */
    public function cancelSubscription(Request $request, Response $response, array $args = []): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $subscriptionId = isset($args['id']) ? (int)$args['id'] : null;
            
            if (!$subscriptionId) {
                return $this->respondWithError($response, 'Не указан ID подписки', 'validation_error', 400, null);
            }
            
            $subscription = UserSubscription::find($subscriptionId);
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404, null);
            }
            
            $data = $request->getParsedBody();
            $reason = $data['reason'] ?? 'Отменено администратором';
            
            $result = $subscription->cancel($reason);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось отменить подписку', 'operation_failed', 400, null);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Подписка успешно отменена',
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Создание подписки администратором для пользователя
     */
    public function createSubscription(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            // Валидация данных
            if (!isset($data['user_id']) || !is_numeric($data['user_id'])) {
                return $this->respondWithError($response, 'Отсутствует или неверный формат user_id', 'validation_error', 400, null);
            }
            
            if (!isset($data['tariff_id']) || !is_numeric($data['tariff_id'])) {
                return $this->respondWithError($response, 'Отсутствует или неверный формат tariff_id', 'validation_error', 400, null);
            }
            
            if (!isset($data['category_id']) || !is_numeric($data['category_id'])) {
                return $this->respondWithError($response, 'Отсутствует или неверный формат category_id', 'validation_error', 400, null);
            }
            
            if (!isset($data['location_id']) || !is_numeric($data['location_id'])) {
                return $this->respondWithError($response, 'Отсутствует или неверный формат location_id', 'validation_error', 400, null);
            }
            
            $userId = (int)$data['user_id'];
            $tariffId = (int)$data['tariff_id'];
            $categoryId = (int)$data['category_id'];
            $locationId = (int)$data['location_id'];
            
            // Проверяем существование пользователя
            $user = User::find($userId);
            if (!$user) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404, null);
            }
            
            // Создаем подписку
            $subscription = $user->requestSubscription($tariffId, $categoryId, $locationId);
            
            // Если нужно сразу активировать
            if (isset($data['activate']) && $data['activate']) {
                $paymentMethod = $data['payment_method'] ?? 'Создано администратором';
                $notes = $data['notes'] ?? 'Создано администратором';
                $durationHours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
                
                $subscription->activate($adminId, $paymentMethod, $notes, $durationHours);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Подписка успешно создана',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'subscription_error', 400, null);
        }
    }
    
    /**
     * Получение истории подписок для всех пользователей
     */
    public function getAllSubscriptionHistory(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $history = \App\Models\SubscriptionHistory::with('user')
                ->orderBy('action_date', 'desc')
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'user' => [
                            'id' => $record->user->id,
                            'name' => $record->user->name,
                        ],
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
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Валидация данных для массовой активации подписок
     */
    private function validateBulkActivationData(array $data): string|null
    {
        if (!isset($data['subscription_ids']) || !is_array($data['subscription_ids']) || empty($data['subscription_ids'])) {
            return 'Отсутствует или неверный формат subscription_ids. Должен быть массив с идентификаторами подписок';
        }
        
        foreach ($data['subscription_ids'] as $id) {
            if (!is_numeric($id)) {
                return 'Некорректный формат ID подписки. Все ID должны быть числами';
            }
        }
        
        if (!isset($data['payment_method']) || empty($data['payment_method'])) {
            return 'Отсутствует обязательное поле payment_method';
        }
        
        if (isset($data['duration_hours']) && !is_numeric($data['duration_hours'])) {
            return 'Неверный формат duration_hours';
        }
        
        return null;
    }
    
    /**
     * Активация нескольких подписок администратором
     */
    public function activateSubscriptions(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            // Валидация входных данных
            $validationError = $this->validateBulkActivationData($data);
            if ($validationError !== null) {
                return $this->respondWithError($response, $validationError, 'validation_error', 400, null);
            }
            
            $subscriptionIds = array_map('intval', $data['subscription_ids']);
            $paymentMethod = $data['payment_method'];
            $notes = $data['notes'] ?? null;
            $durationHours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
            
            // Получение подписок для активации
            $subscriptions = UserSubscription::whereIn('id', $subscriptionIds)->get();
            
            // Проверка, что все запрошенные подписки найдены
            if (count($subscriptions) !== count($subscriptionIds)) {
                $foundIds = $subscriptions->pluck('id')->toArray();
                $missingIds = array_diff($subscriptionIds, $foundIds);
                return $this->respondWithError(
                    $response,
                    'Некоторые подписки не найдены: ' . implode(', ', $missingIds),
                    'not_found',
                    404,
                    null
                );
            }
            
            // Активация каждой подписки
            $results = [];
            $errors = [];
            
            foreach ($subscriptions as $subscription) {
                try {
                    $result = $subscription->activate($adminId, $paymentMethod, $notes, $durationHours);
                    if ($result) {
                        $results[] = [
                            'id' => $subscription->id,
                            'status' => 'success',
                            'message' => 'Подписка успешно активирована',
                            'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                            'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                        ];
                    } else {
                        $errors[] = [
                            'id' => $subscription->id,
                            'status' => 'error',
                            'message' => 'Не удалось активировать подписку'
                        ];
                    }
                } catch (Exception $e) {
                    $errors[] = [
                        'id' => $subscription->id,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            // Формирование ответа
            if (empty($errors)) {
                return $this->respondWithData($response, [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Все подписки успешно активированы',
                    'data' => [
                        'activated_subscriptions' => $results
                    ]
                ], 200);
            } else {
                // Если есть ошибки, но есть и успешные активации
                if (!empty($results)) {
                    return $this->respondWithData($response, [
                        'code' => 207,
                        'status' => 'partial_success',
                        'message' => 'Некоторые подписки активированы успешно, некоторые с ошибками',
                        'data' => [
                            'successful' => $results,
                            'failed' => $errors
                        ]
                    ], 207);
                } else {
                    // Все подписки с ошибками
                    return $this->respondWithError(
                        $response,
                        'Ни одна подписка не была активирована',
                        'operation_failed',
                        400,
                        ['errors' => $errors]
                    );
                }
            }
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
} 