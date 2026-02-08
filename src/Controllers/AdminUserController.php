<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\JwtService;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для администрирования пользователей
 */
class AdminUserController
{
    use ResponseTrait;

    private JwtService $jwtService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->jwtService = $container->get(JwtService::class);
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
                403
            );
        }
        
        return null;
    }

    /**
     * Получение списка пользователей с фильтрацией и пагинацией
     */
    public function getUsers(Request $request, Response $response): Response
    {
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $data = $request->getParsedBody() ?? [];
            
            $page = max(1, (int)($data['page'] ?? 1));
            $perPage = min(100, max(1, (int)($data['per_page'] ?? 20)));
            $search = $data['search'] ?? null;
            $role = $data['role'] ?? null;
            $hasSubscription = $data['has_subscription'] ?? null;
            $sortField = $data['sort'] ?? 'created_at';
            $sortDirection = strtolower($data['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            
            // Разрешённые поля для сортировки
            $allowedSortFields = ['id', 'name', 'created_at', 'role'];
            if (!in_array($sortField, $allowedSortFields)) {
                $sortField = 'created_at';
            }
            
            $query = User::query();
            
            // Поиск по ID, имени или telegram username
            if ($search) {
                $query->where(function ($q) use ($search) {
                    // Если это число — ищем по ID
                    if (is_numeric($search)) {
                        $q->where('id', (int)$search);
                    }
                    $q->orWhere('name', 'ILIKE', "%{$search}%")
                      ->orWhere('telegram_username', 'ILIKE', "%{$search}%");
                });
            }
            
            // Фильтр по роли
            if ($role && in_array($role, ['user', 'admin'])) {
                $query->where('role', $role);
            }
            
            // Фильтр по наличию активной подписки
            if ($hasSubscription !== null) {
                if ($hasSubscription === true || $hasSubscription === 'true' || $hasSubscription === '1') {
                    $query->whereHas('subscriptions', function ($q) {
                        $q->whereIn('status', ['active', 'extend_pending'])
                          ->where('end_date', '>=', Carbon::now());
                    });
                } elseif ($hasSubscription === false || $hasSubscription === 'false' || $hasSubscription === '0') {
                    $query->whereDoesntHave('subscriptions', function ($q) {
                        $q->whereIn('status', ['active', 'extend_pending'])
                          ->where('end_date', '>=', Carbon::now());
                    });
                }
            }
            
            // Общее количество
            $total = $query->count();
            
            // Сортировка и пагинация
            $users = $query->orderBy($sortField, $sortDirection)
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
            
            // Форматируем данные
            $usersData = $users->map(function ($user) {
                // Получаем активные подписки
                $activeSubscriptions = UserSubscription::where('user_id', $user->id)
                    ->whereIn('status', ['active', 'extend_pending'])
                    ->where('end_date', '>=', Carbon::now())
                    ->with(['category', 'location'])
                    ->get();
                
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'telegram_username' => $user->telegram_username,
                    'telegram_id' => $user->telegram_id,
                    'role' => $user->role,
                    'created_at' => $user->created_at?->format('d.m.Y H:i'),
                    'has_active_subscription' => $activeSubscriptions->isNotEmpty(),
                    'active_subscriptions_count' => $activeSubscriptions->count(),
                    'subscriptions' => $activeSubscriptions->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'category' => $sub->category?->name,
                            'location' => $sub->location?->city,
                            'status' => $sub->status,
                            'end_date' => $sub->end_date?->format('d.m.Y'),
                        ];
                    }),
                ];
            });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'users' => $usersData,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => (int)ceil($total / $perPage),
                    ],
                ],
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка получения списка пользователей: ' . $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Имперсонация — вход под другим пользователем
     * Генерирует access_token для указанного пользователя
     */
    public function impersonate(Request $request, Response $response): Response
    {
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $adminId = $request->getAttribute('userId');
            $data = $request->getParsedBody();
            
            if (!isset($data['user_id']) || !is_numeric($data['user_id'])) {
                return $this->respondWithError($response, 'Не указан user_id', 'validation_error', 400);
            }
            
            $targetUserId = (int)$data['user_id'];
            
            // Нельзя имперсонировать самого себя
            if ($targetUserId === $adminId) {
                return $this->respondWithError($response, 'Нельзя войти под своим аккаунтом', 'validation_error', 400);
            }
            
            // Проверяем существование пользователя
            $targetUser = User::find($targetUserId);
            if (!$targetUser) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404);
            }
            
            // Генерируем access_token для целевого пользователя
            $accessToken = $this->jwtService->createAccessToken(
                $targetUserId,
                'web_impersonate'
            );
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => "Вход выполнен под пользователем: {$targetUser->name}",
                'data' => [
                    'access_token' => $accessToken,
                    'user' => [
                        'id' => $targetUser->id,
                        'name' => $targetUser->name,
                        'telegram_username' => $targetUser->telegram_username,
                        'role' => $targetUser->role,
                    ],
                    'impersonated_by' => $adminId,
                ],
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка имперсонации: ' . $e->getMessage(), 'internal_error', 500);
        }
    }
}
