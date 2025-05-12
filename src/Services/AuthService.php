<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class AuthService extends BaseAuthService
{
    protected ContainerInterface $container;
    protected JwtService $jwtService;
    protected UserSettingsService $userSettingsService;
    protected SubscriptionService $subscriptionService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->userSettingsService = $container->get(UserSettingsService::class);
        $this->subscriptionService = $container->get(SubscriptionService::class);
    }

    /**
     * Аутентификация пользователя по логин|пароль
     *
     * @param int $userId ID пользователя
     * @param string $password Пароль пользователя
     * @param string $deviceType Тип устройства ('web' или 'mobile')
     * @return array|null Массив с токенами или null в случае неудачной аутентификации
     * @throws Exception
     */
    public function authenticateUser(int $userId, string $password, string $deviceType = 'web'): ?array
    {
        /** Находим пользователя по ID */
        $user = User::where('id', $userId)->first();

        /** Проверяем существование пользователя и правильность пароля */
        if (!$user) {
            return null;
        }

        /** @var User $user */
        if (!$user->verifyPassword($password)) {
            return null;
        }

        /** Генерируем токены */
        $tokens = $this->jwtService->createTokens($user->id, $deviceType);

        /** Возвращаем данные пользователя и токены */
        return $this->createAuthResponse($user, $tokens);
    }

    /**
     * Refresh tokens
     *
     * @param string $refreshToken Refresh token
     * @param string $deviceType Type of device ('web' or 'mobile')
     * @return array|null Array with new tokens or null if refresh failed
     * @throws Exception
     */
    public function refreshToken(string $refreshToken, string $deviceType = 'web'): ?array
    {
        $newTokens = $this->jwtService->refreshTokens($refreshToken);
        
        if (!$newTokens) {
            return null;
        }
        
        return [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'],
            'expires_in' => $newTokens['expires_in']
        ];
    }
    
    /**
     * Получает ID пользователя из токена
     */
    public function getUserIdFromToken(string $accessToken): ?int
    {
        return $this->jwtService->getUserIdFromToken($accessToken);
    }

    /**
     * Выход из системы для конкретного типа устройства
     * 
     * @param int $userId ID пользователя
     * @param string $deviceType Тип устройства ('web' или 'mobile')
     * @return bool Результат операции
     */
    public function logoutByDeviceType(int $userId, string $deviceType): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        
        // Удаляем все refresh токены для указанного типа устройства
        return (bool) $user->refreshTokens()
            ->where('device_type', $deviceType)
            ->delete();
    }
} 