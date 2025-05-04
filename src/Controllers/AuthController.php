<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Traits\ResponseTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    use ResponseTrait;

    private ContainerInterface $container;
    private AuthService $authService;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->authService = $container->get(AuthService::class);
    }

    /**
     * Login with telegram_id and password
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Validate input
        if (!isset($data['telegram_id']) || !isset($data['password'])) {
            return $this->respondWithError($response, 'Telegram ID and password are required', 400);
        }
        
        // Определение типа устройства
        $deviceType = $data['device_type'] ?? 'web';
        if (!in_array($deviceType, ['web', 'mobile'])) {
            $deviceType = 'web'; // Значение по умолчанию для неверных значений
        }
        
        // Authenticate user
        $result = $this->authService->authenticateUser(
            $data['telegram_id'], 
            $data['password'],
            $deviceType
        );
        
        if (!$result) {
            return $this->respondWithError($response, 'Invalid Telegram ID or password', 401);
        }
        
        // Return tokens
        return $this->respondWithData($response, [
            'status' => 'success',
            'data' => $result
        ]);
    }
    
    /**
     * Refresh tokens
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function refresh(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Validate input
        if (!isset($data['refresh_token'])) {
            return $this->respondWithError($response, 'Refresh token is required', 400);
        }
        
        // Определение типа устройства
        $deviceType = $data['device_type'] ?? 'web';
        if (!in_array($deviceType, ['web', 'mobile'])) {
            $deviceType = 'web'; // Значение по умолчанию для неверных значений
        }
        
        // Refresh tokens
        $result = $this->authService->refreshToken($data['refresh_token'], $deviceType);
        
        if (!$result) {
            return $this->respondWithError($response, 'Invalid refresh token', 401);
        }
        
        // Return new tokens
        return $this->respondWithData($response, [
            'status' => 'success',
            'data' => $result
        ]);
    }

    /**
     * Logout user
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function logout(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Validate input
        if (!isset($data['refresh_token'])) {
            return $this->respondWithError($response, 'Refresh token is required', 400);
        }
        
        // Logout user
        $result = $this->authService->logout($data['refresh_token']);
        
        // Return success response even if token was invalid
        return $this->respondWithData($response, [
            'status' => 'success',
            'data' => [
                'logged_out' => $result
            ]
        ]);
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