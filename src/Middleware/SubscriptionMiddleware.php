<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\User;
use App\Models\UserSubscription;
use App\Traits\ResponseTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Middleware для проверки наличия активной подписки у пользователя.
 * 
 * Логика:
 * - Администраторы имеют доступ всегда
 * - Пользователи с активной подпиской (active или extend_pending) имеют доступ
 * - Пользователи без подписки получают ошибку 403
 * 
 * ВАЖНО: Этот middleware должен использоваться ПОСЛЕ AuthMiddleware,
 * т.к. он зависит от атрибута userId в запросе.
 */
class SubscriptionMiddleware implements MiddlewareInterface
{
    use ResponseTrait;

    private ResponseFactory $responseFactory;

    public function __construct()
    {
        $this->responseFactory = new ResponseFactory();
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $userId = $request->getAttribute('userId');
        
        if (!$userId) {
            return $this->respondWithError(
                $this->responseFactory->createResponse(),
                'Пользователь не авторизован',
                'unauthorized',
                401
            );
        }

        // Получаем пользователя
        $user = User::find($userId);
        
        if (!$user) {
            return $this->respondWithError(
                $this->responseFactory->createResponse(),
                'Пользователь не найден',
                'user_not_found',
                404
            );
        }

        // Администраторы имеют доступ всегда
        if ($user->role === 'admin') {
            return $handler->handle($request);
        }

        // Проверяем наличие активной подписки
        $hasActiveSubscription = UserSubscription::where('user_id', $userId)
            ->whereIn('status', ['active', 'extend_pending'])
            ->where('end_date', '>=', now())
            ->exists();

        if (!$hasActiveSubscription) {
            return $this->respondWithError(
                $this->responseFactory->createResponse(),
                'Для доступа к этому разделу необходима активная подписка',
                'subscription_required',
                403
            );
        }

        return $handler->handle($request);
    }
}
