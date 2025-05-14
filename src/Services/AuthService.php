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
     * Обновление токенов
     *
     * @param string $refreshToken Refresh token
     * @return array|null Массив с новыми токенами или null если обновление не удалось
     * @throws Exception
     */
    public function refreshToken(string $refreshToken): ?array
    {
        $tokenData = $this->jwtService->decodeRefreshToken($refreshToken);
        if (!$tokenData) {
            return null;
        }

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
     * Выход из системы по access токену
     * 
     * @param string $accessToken Access token
     * @return bool Результат операции
     */
    public function logoutByAccessToken(string $accessToken): bool
    {
        $tokenData = $this->jwtService->decodeAccessToken($accessToken);
        if (!$tokenData) {
            return false;
        }

        $user = User::find($tokenData['user_id']);
        if (!$user) {
            return false;
        }

        // Удаляем все refresh токены для указанного типа устройства
        return (bool) $user->refreshTokens()
            ->where('device_type', $tokenData['device_type'])
            ->delete();
    }

    /**
     * Выход из всех устройств
     * 
     * @param int $userId ID пользователя
     * @return bool Результат операции
     */
    public function logoutFromAllDevices(int $userId): bool
    {
        return parent::logoutFromAllDevices($userId);
    }

} 