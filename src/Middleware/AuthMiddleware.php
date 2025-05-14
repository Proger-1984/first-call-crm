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
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

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
                'Token not found',
                'token_not_found',
                401
            );
        }

        // Проверяем, что это access токен, а не refresh токен
        try {
            $decoded = JWT::decode($token, new Key($this->jwtService->getAccessSecret(), $this->jwtService->getAlgorithm()));
            
            // Проверяем, что это именно access токен
            if (!isset($decoded->device_type)) {
                return $this->respondWithError(
                    $this->responseFactory->createResponse(),
                    'Invalid token type',
                    'invalid_token_type',
                    401
                );
            }

            // Добавляем ID пользователя в атрибуты запроса
            $request = $request->withAttribute('userId', $decoded->user_id);
            
            return $handler->handle($request);
        } catch (Exception) {
            return $this->respondWithError(
                $this->responseFactory->createResponse(),
                'Invalid token',
                'invalid_token',
                401
            );
        }
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