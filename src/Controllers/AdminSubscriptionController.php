<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SubscriptionHistory;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Tariff;
use App\Models\Category;
use App\Models\Location;
use App\Services\SubscriptionService;
use App\Services\TelegramService;
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
    private TelegramService $telegramService;

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
     * Валидация данных для активации подписки
     */
    private function validateActivationData(array $data): string|null
    {
        if (!isset($data['subscription_id']) || !is_numeric($data['subscription_id'])) {
            return 'Отсутствует или неверный формат subscription_id';
        }
        
        if (!isset($data['payment_method']) || empty($data['payment_method'])) {
            return 'Отсутствует обязательное поле payment_method';
        }
        
        return null;
    }
    
    /**
     * Продление подписки администратором
     */
    public function extendSubscription(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            // Проверка наличия обязательных полей
            if (!isset($data['subscription_id']) || !is_numeric($data['subscription_id'])) {
                return $this->respondWithError($response, 'Необходимо указать ID подписки для продления', 'validation_error', 400, null);
            }
            
            $subscriptionId = (int)$data['subscription_id'];
            
            $subscription = UserSubscription::find($subscriptionId);
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404, null);
            }
            
            // Проверяем, что подписка активна
            if (!$subscription->isActive()) {
                return $this->respondWithError($response, 'Продлить можно только активную подписку', 'subscription_not_active', 400, null);
            }
            
            $newPrice = isset($data['price']) ? (float)$data['price'] : null;
            
            // Проверка обязательного параметра payment_method
            if (!isset($data['payment_method']) || empty($data['payment_method'])) {
                return $this->respondWithError($response, 'Отсутствует обязательное поле payment_method', 'validation_error', 400, null);
            }
            
            $paymentMethod = $data['payment_method'];
            $notes = $data['notes'] ?? null;
            $durationHours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
            
            // Вызываем метод продления подписки
            $result = $subscription->extendByAdmin($adminId, $newPrice, $paymentMethod, $notes, $durationHours);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось продлить подписку', 'operation_failed', 400, null);
            }
            
            // Отправляем уведомление пользователю о продлении подписки
            try {
                // Загружаем подписку со всеми связями
                $subscription->load(['user', 'category', 'location', 'tariff']);
                
                // Если пользователь и необходимые связи загружены, отправляем уведомление
                if ($subscription->user && $subscription->category && $subscription->location) {
                    $this->telegramService->notifySubscriptionExtended($subscription->user, $subscription);
                }
            } catch (Exception $e) {
                // Логируем ошибку, но продолжаем выполнение
                error_log('Ошибка при отправке уведомления о продлении подписки: ' . $e->getMessage());
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Подписка успешно продлена',
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }
    
    /**
     * Отмена подписки администратором
     */
    public function cancelSubscription(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $data = $request->getParsedBody();
            
            // Проверка наличия обязательных полей
            if (!isset($data['subscription_id']) || !is_numeric($data['subscription_id'])) {
                return $this->respondWithError($response, 'Необходимо указать ID подписки для отмены', 'validation_error', 400, null);
            }
            
            $subscriptionId = (int)$data['subscription_id'];
            
            $subscription = UserSubscription::find($subscriptionId);
            if (!$subscription) {
                return $this->respondWithError($response, 'Подписка не найдена', 'not_found', 404, null);
            }
            
            $reason = $data['reason'] ?? 'Отменено администратором';
            
            // Загружаем связи для подписки перед отменой
            $subscription->load(['user', 'category', 'location', 'tariff']);
            
            $result = $subscription->cancel($reason);
            
            if (!$result) {
                return $this->respondWithError($response, 'Не удалось отменить подписку', 'operation_failed', 400, null);
            }
            
            // Отправляем уведомление пользователю об отмене подписки
            try {
                if ($subscription->user) {
                    $this->telegramService->notifySubscriptionCancelled($subscription->user, $subscription, $reason);
                }
            } catch (Exception $e) {
                // Логируем ошибку, но продолжаем выполнение
                error_log('Ошибка при отправке уведомления об отмене подписки: ' . $e->getMessage());
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
     * Активация подписки администратором
     */
    public function activateSubscription(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            // Валидация входных данных
            $validationError = $this->validateActivationData($data);
            if ($validationError !== null) {
                return $this->respondWithError($response, $validationError, 'validation_error', 400, null);
            }
            
            $subscriptionId = (int)$data['subscription_id'];
            $paymentMethod = $data['payment_method'];
            $notes = $data['notes'] ?? null;
            $durationHours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
            
            // Получение подписки для активации
            $subscription = UserSubscription::find($subscriptionId);
            
            // Загружаем связи отдельно для гарантии правильного типа объекта
            $subscription?->load(['user', 'tariff', 'category', 'location']);
            
            // Проверка, что подписка найдена
            if (!$subscription) {
                return $this->respondWithError(
                    $response,
                    'Подписка не найдена: ' . $subscriptionId,
                    'not_found',
                    404,
                    null
                );
            }
            
            // Если это премиум-подписка, устанавливаем флаг использования демо
            $userId = $subscription->user_id;
            $isPremium = $subscription->tariff->isPremium();
            
            if ($isPremium) {
                $user = $subscription->user;
                if ($user && !$user->is_trial_used) {
                    $user->is_trial_used = true;
                    $user->save();
                }
                
                // Отменяем все активные демо-подписки этого пользователя
                UserSubscription::where('user_id', $userId)
                    ->whereHas('tariff', function($query) {
                        $query->where('code', 'demo');
                    })
                    ->where('status', 'active')
                    ->get()
                    ->each(function ($demoSubscription) {
                        $demoSubscription->cancel('Автоматическая отмена при активации премиум-тарифа администратором');
                    });
            }
            
            // Активация подписки
            try {
                $result = $subscription->activate($adminId, $paymentMethod, $notes, $durationHours);
                if ($result) {
                    $responseData = [
                        'id' => $subscription->id,
                        'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                        'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                    ];

                    // Отправляем уведомление о активации подписки
                    // Получим user_id из подписки
                    $userId = $subscription->user_id;
                    $subscriptionId = $subscription->id;
                        
                    // Получаем объекты из базы с правильными типами
                    $userObject = User::find($userId);
                    $subscriptionObject = UserSubscription::find($subscriptionId);
                        
                    // Загружаем связи для подписки
                    $subscriptionObject?->load(['category', 'location', 'tariff']);
                        
                    // Отправляем уведомление если объекты найдены
                    if ($userObject && $subscriptionObject) {
                        $this->telegramService->notifyPremiumSubscriptionActivated($userObject, $subscriptionObject);
                    }

                    return $this->respondWithData($response, [
                        'code' => 200,
                        'status' => 'success',
                        'message' => 'Подписка успешно активирована',
                        'data' => [
                            'activated_subscription' => $responseData
                        ]
                    ], 200);
                } else {
                    return $this->respondWithError(
                        $response,
                        'Не удалось активировать подписку',
                        'operation_failed',
                        400,
                        null
                    );
                }
            } catch (Exception $e) {
                return $this->respondWithError(
                    $response,
                    'Ошибка при активации подписки: ' . $e->getMessage(),
                    'operation_failed',
                    400,
                    null
                );
            }
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500, null);
        }
    }

} 