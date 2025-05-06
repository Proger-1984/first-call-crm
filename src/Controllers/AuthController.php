<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    use ResponseTrait;

    private ContainerInterface $container;
    private AuthService $authService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->authService = $container->get(AuthService::class);
    }

    /**
     * Авторизация пользователя через приложение по логин|пароль
     *
     * @param Request $request
     * @param Response $response
     * @param string $deviceType
     * @return Response
     * @throws Exception
     */
    public function login(Request $request, Response $response, string $deviceType = 'mobile'): Response
    {
        $data = $request->getParsedBody();

        if (!isset($data['login']) || !isset($data['password'])) {
            return $this->respondWithError($response, null, 'validation_error', 422);
        }

        /** Проверка наличия пользователя */
        $result = $this->authService->authenticateUser(
            $data['login'], $data['password'], $deviceType
        );
        
        if (!$result) {
            return $this->respondWithError(
                $response, null, 'invalid_credentials', 401
            );
        }

        /** Проверяем статус подписки
         * 1 - Demo, 2 - Premium, 3 - Close (Доступ закрыт)
         */
        if ($result['user']['tariff'] == 3) {
            return $this->respondWithError(
                $response,
                'Доступ запрещен: подписка истекла.',
                'subscription_expired',
                403
            );
        }

        /** Возвращаем токены */
        return $this->respondWithData($response, [
            'code' => 200,
            'status' => 'success',
            'data' => $result
        ], 200);
    }
    
    /**
     * Обновление токенов доступа
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function refresh(Request $request, Response $response): Response
    {
        $refreshToken = $this->getTokenFromHeader($request);

        if (!$refreshToken) {
            return $this->respondWithError(
                $response,
                'Токен не предоставлен.',
                'Refresh token not found',
                401
            );
        }

        // Обновляем токены
        $result = $this->authService->refreshToken($refreshToken, 'mobile');
        
        if (!$result) {
            return $this->respondWithError($response, 'Недействительный refresh token.', 'Refresh token expired', 401);
        }

        return $this->respondWithData($response, [
            'code' => 200,
            'status' => 'success',
            'data' => $result
        ], 200);

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

    /**
     * Выход из системы
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function logout(Request $request, Response $response): Response
    {
        // Получаем ID пользователя из токена
        $accessToken = $this->getTokenFromHeader($request);
        
        if (!$accessToken) {
            return $this->respondWithError(
                $response,
                'Токен не предоставлен.',
                'Access token not found',
                401
            );
        }

        // Получаем ID пользователя из токена без проверки валидности
        $userId = $this->authService->getUserIdFromToken($accessToken);
        
        // Выход из системы для мобильного устройства
        $result = $this->authService->logoutByDeviceType($userId, 'mobile');
        
        // Возвращаем успешный ответ
        return $this->respondWithData($response, [
            'code' => 200,
            'status' => 'success',
            'message' => 'Успешный выход из системы.'
        ], 200);
    }
    
    /**
     * Logout from all devices
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function logoutAll(Request $request, Response $response): Response
    {
        // Получаем ID пользователя из токена с помощью middleware
        $userId = $request->getAttribute('user_id');
        
        if (!$userId) {
            return $this->respondWithError($response, 'Unauthorized', 401);
        }
        
        // Logout from all devices
        $result = $this->authService->logoutFromAllDevices($userId);
        
        return $this->respondWithData($response, [
            'status' => 'success',
            'data' => [
                'logged_out' => $result
            ]
        ]);
    }
} 