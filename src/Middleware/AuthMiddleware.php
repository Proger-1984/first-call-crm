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

        return $handler->handle($request);
    }

    private function getTokenFromHeader(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (!$header) {
            return null;
        }

        $parts = explode(' ', $header);

        if (count($parts) !== 2 || $parts[0] !== 'Bearer') {
            return null;
        }

        return $parts[1];
    }
} 