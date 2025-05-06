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
use stdClass;

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
        $accessToken = $this->createAccessToken($userId);
        $refreshToken = $this->createRefreshToken($userId);
        
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
    public function createAccessToken(int $userId): string
    {
        $issuedAt = new DateTime();
        $expire = (clone $issuedAt)->modify("+{$this->accessExpiration} seconds");

        $payload = [
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expire->getTimestamp(),
            'user_id' => $userId
        ];

        return JWT::encode($payload, $this->accessSecret, $this->algorithm);
    }

    /**
     * Создает refresh токен для пользователя
     * @throws Exception
     */
    public function createRefreshToken(int $userId): string
    {
        $issuedAt = new DateTime();
        $expire = (clone $issuedAt)->modify("+{$this->refreshExpiration} seconds");

        $payload = [
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expire->getTimestamp(),
            'user_id' => $userId,
        ];

        return JWT::encode($payload, $this->refreshSecret, $this->algorithm);
    }

    /**
     * Проверяет access токен
     */
    public function verifyAccessToken(string $token): ?stdClass
    {
        try {
            return JWT::decode($token, new Key($this->accessSecret, $this->algorithm));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Проверяет refresh токен
     */
    public function verifyRefreshToken(string $token): ?stdClass
    {
        try {
            /** Проверяем валидность JWT */
            $decoded = JWT::decode($token, new Key($this->refreshSecret, $this->algorithm));

            /** Проверяем, существует ли токен в базе данных */
            $refreshToken = RefreshToken::findValidToken($token);
            if (!$refreshToken) {
                return null;
            }
            
            return $decoded;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Обновляет пару токенов с помощью refresh токена
     * @throws Exception
     */
    public function refreshTokens(string $refreshToken, string $deviceType = 'web'): ?array
    {
        $decoded = $this->verifyRefreshToken($refreshToken);
        
        if (!$decoded) {
            return null;
        }

        /** Находим токен в базе данных */
        $tokenRecord = RefreshToken::findValidToken($refreshToken);
        if (!$tokenRecord) {
            return null;
        }

        /** Удаляем старый токен */
        RefreshToken::removeToken($refreshToken);

        /** Создаем новые токены */
        return $this->createTokens($decoded->user_id, $tokenRecord->device_type);
    }
    
    /**
     * Получает ID пользователя из access токена без проверки валидности
     */
    public function getUserIdFromToken(string $token): ?int
    {
        try {
            $decoded = JWT::decode($token, new Key($this->accessSecret, $this->algorithm));
            return isset($decoded->user_id) ? (int) $decoded->user_id : null;
        } catch (Exception) {
            return null;
        }
    }
    
    /**
     * Удаляет refresh токен (при выходе из системы)
     */
    public function removeRefreshToken(string $refreshToken): bool
    {
        return RefreshToken::removeToken($refreshToken);
    }
} 