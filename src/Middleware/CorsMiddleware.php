<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        // Получаем origin из запроса
        $origin = $request->getHeaderLine('Origin');
        
        // Список разрешённых origins
        $allowedOrigins = [
            'https://local.firstcall.com',
            'https://realtor.first-call.ru',
            'http://localhost:5173',
            'http://localhost:3000',
        ];
        
        // Если origin в списке разрешённых, используем его, иначе первый из списка
        $allowOrigin = in_array($origin, $allowedOrigins, true) ? $origin : $allowedOrigins[0];
        
        // Обрабатываем preflight OPTIONS запросы отдельно
        // Они НЕ должны проходить через AuthMiddleware
        if ($request->getMethod() === 'OPTIONS') {
            $responseFactory = new ResponseFactory();
            $response = $responseFactory->createResponse(200);
            
            return $response
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Max-Age', '86400'); // Кешировать preflight на 24 часа
        }

        // Обычный запрос - передаём дальше
        $response = $handler->handle($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Expose-Headers', 'Set-Cookie');
    }
} 