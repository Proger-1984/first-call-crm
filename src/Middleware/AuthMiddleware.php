<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AuthMiddleware implements MiddlewareInterface
{
    private JwtService $jwtService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->jwtService = $container->get(JwtService::class);
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $token = $this->getTokenFromHeader($request);

        if (!$token) {
            return $this->respondWithUnauthorized('Токен не предоставлен');
        }

        $decoded = $this->jwtService->verifyAccessToken($token);

        if (!$decoded) {
            return $this->respondWithUnauthorized('Недействительный или истекший токен');
        }

        // Добавляем данные о пользователе в запрос
        $userId = $decoded->sub;
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

    private function respondWithUnauthorized(string $message = 'Unauthorized'): Response
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => $message,
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
} 