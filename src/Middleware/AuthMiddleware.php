<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use App\Traits\ResponseTrait;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

class AuthMiddleware implements MiddlewareInterface
{
    use ResponseTrait;

    private JwtService $jwtService;
    private ResponseFactory $responseFactory;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->jwtService = $container->get(JwtService::class);
        $this->responseFactory = new ResponseFactory();
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $token = $this->getTokenFromHeader($request);

        if (!$token) {
            return $this->respondWithError(
                $this->responseFactory->createResponse(),
                'Access token not found',
                'token_not_found',
                401
            );
        }

        $decoded = $this->jwtService->verifyAccessToken($token);

        if (!$decoded) {
            return $this->respondWithError(
                $this->responseFactory->createResponse(),
                'Access token expired',
                'token_expired',
                401
            );
        }

        // Добавляем данные о пользователе в запрос
        $userId = $decoded->user_id;
        $request = $request->withAttribute('userId', $userId);
        
        // Добавляем роль пользователя, если она есть в токене
        if (isset($decoded->role)) {
            $request = $request->withAttribute('userRole', $decoded->role);
        }

        return $handler->handle($request);
    }

    private function getTokenFromHeader(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (!$authHeader) {
            return null;
        }
        
        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }
        
        return null;
    }
} 