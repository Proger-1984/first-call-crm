<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use DateTime;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class JwtService
{
    private string $accessSecret;
    private string $refreshSecret;
    private int $accessExpiration;
    private int $refreshExpiration;
    private string $algorithm;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $config = $container->get('config')['jwt'];
        $this->accessSecret = $config['access_secret'];
        $this->refreshSecret = $config['refresh_secret'];
        $this->accessExpiration = $config['access_expiration'];
        $this->refreshExpiration = $config['refresh_expiration'];
        $this->algorithm = $config['algorithm'];
    }

    /**
     * Создает пару токенов (access и refresh) для пользователя
     * @throws Exception
     */
    #[ArrayShape(['access_token' => "string", 'refresh_token' => "string", 'expires_in' => "int"])]
    public function createTokens(int $userId, string $deviceType = 'web'): array
    {
        $accessToken = $this->createAccessToken($userId, $deviceType);
        $refreshToken = $this->createRefreshToken($userId, $deviceType);
        
        /** Сохраняем refresh token в базу данных */
        User::find($userId)?->createOrUpdateRefreshToken($refreshToken, $deviceType, $this->refreshExpiration);
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => time() + $this->accessExpiration,
        ];
    }

    /**
     * Создает access токен для пользователя
     */
    public function createAccessToken(int $userId, string $deviceType): string
    {
        $issuedAt = new DateTime();
        $expire = (clone $issuedAt)->modify("+$this->accessExpiration seconds");
        
        // Получаем роль пользователя
        $user = User::find($userId);
        $role = $user ? $user->role : 'user';

        $payload = [
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expire->getTimestamp(),
            'user_id' => $userId,
            'role' => $role,
            'device_type' => $deviceType
        ];

        return JWT::encode($payload, $this->accessSecret, $this->algorithm);
    }

    /**
     * Создает refresh токен для пользователя
     * @throws Exception
     */
    public function createRefreshToken(int $userId, string $deviceType): string
    {
        $issuedAt = new DateTime();
        $expire = (clone $issuedAt)->modify("+$this->refreshExpiration seconds");
        
        // Получаем роль пользователя
        $user = User::find($userId);
        $role = $user ? $user->role : 'user';

        $payload = [
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expire->getTimestamp(),
            'user_id' => $userId,
            'role' => $role,
            'device_type' => $deviceType
        ];

        return JWT::encode($payload, $this->refreshSecret, $this->algorithm);
    }

    /**
     * Проверяет access токен и возвращает данные из него
     * 
     * @param string $token Access token
     * @return array|null Массив с данными токена или null если токен невалиден
     */
    public function decodeAccessToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->accessSecret, $this->algorithm));
            return [
                'user_id' => $decoded->user_id,
                'device_type' => $decoded->device_type,
                'expires_at' => $decoded->exp
            ];
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Проверяет refresh токен и возвращает данные из него
     * 
     * @param string $token Refresh token
     * @return array|null Массив с данными токена или null если токен невалиден
     */
    public function decodeRefreshToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->refreshSecret, $this->algorithm));
            
            /** Проверяем, существует ли токен в базе данных */
            $refreshToken = RefreshToken::where('token', $token)
                ->where('expires_at', '>', new DateTime())
                ->first();
                
            if (!$refreshToken) {
                return null;
            }
            
            return [
                'user_id' => $decoded->user_id,
                'device_type' => $decoded->device_type,
                'expires_at' => $decoded->exp
            ];
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Обновляет пару токенов с помощью refresh токена
     * @throws Exception
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        $tokenData = $this->decodeRefreshToken($refreshToken);
        
        if (!$tokenData) {
            return null;
        }

        /** Находим токен в базе данных */
        $tokenRecord = RefreshToken::where('token', $refreshToken)
            ->where('expires_at', '>', new DateTime())
            ->first();
            
        if (!$tokenRecord) {
            return null;
        }

        /** Удаляем старый токен */
        $tokenRecord->delete();

        /** Создаем новые токены */
        return $this->createTokens($tokenData['user_id'], $tokenData['device_type']);
    }
    
    /**
     * Удаляет refresh токен (при выходе из системы)
     */
    public function removeRefreshToken(string $refreshToken): bool
    {
        try {
            $token = RefreshToken::where('token', $refreshToken)->first();
            
            if ($token) {
                return (bool) $token->delete();
            }
            
            return false;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Получает секретный ключ для access токенов
     */
    public function getAccessSecret(): string
    {
        return $this->accessSecret;
    }

    /**
     * Получает алгоритм шифрования
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }
} 