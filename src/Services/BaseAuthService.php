<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Container\ContainerInterface;

abstract class BaseAuthService
{
    protected ContainerInterface $container;
    protected JwtService $jwtService;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->jwtService = $container->get(JwtService::class);
    }

    /**
     * Создает ответ с токенами и данными пользователя
     */
    #[ArrayShape(['access_token' => "mixed", 'refresh_token' => "mixed", 'expires_in' => "mixed", 'user' => "array"])]
    protected function createAuthResponse(User $user, array $tokens): array
    {
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'has_subscription' => $user->hasAnyActiveSubscription()
            ]
        ];
    }

    /**
     * Обновляет токены
     * @throws Exception
     */
    public function refreshToken(string $refreshToken): ?array
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
     * Выход из системы
     */
    public function logout(string $refreshToken): bool
    {
        return $this->jwtService->removeRefreshToken($refreshToken);
    }

    /**
     * Выход из всех устройств
     */
    public function logoutFromAllDevices(int $userId): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        
        return $user->removeAllRefreshTokens();
    }
} 