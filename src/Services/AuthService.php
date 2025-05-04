<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\JwtService;
use App\Services\UserSettingsService;
use Psr\Container\ContainerInterface;

class AuthService
{
    private ContainerInterface $container;
    private JwtService $jwtService;
    private UserSettingsService $userSettingsService;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->jwtService = $container->get(JwtService::class);
        $this->userSettingsService = $container->get(UserSettingsService::class);
    }

    /**
     * Authenticate user by telegram_id and password
     * 
     * @param string $telegramId Telegram ID
     * @param string $password Password
     * @param string $deviceType Type of device ('web' or 'mobile')
     * @return array|null Array with tokens or null if authentication failed
     */
    public function authenticateUser(string $telegramId, string $password, string $deviceType = 'web'): ?array
    {
        // Find user by telegram_id
        $user = User::where('telegram_id', $telegramId)->first();
        
        // Check if user exists and password is correct
        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }
        
        // Generate tokens
        $tokens = $this->jwtService->createTokens($user->id, $deviceType);
        
        // Return user data and tokens
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'telegram_id' => $user->telegram_id,
                'telegram_username' => $user->telegram_username,
                'telegram_photo_url' => $user->telegram_photo_url
            ]
        ];
    }
    
    /**
     * Refresh tokens
     * 
     * @param string $refreshToken Refresh token
     * @param string $deviceType Type of device ('web' or 'mobile')
     * @return array|null Array with new tokens or null if refresh failed
     */
    public function refreshToken(string $refreshToken, string $deviceType = 'web'): ?array
    {
        $newTokens = $this->jwtService->refreshTokens($refreshToken, $deviceType);
        
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
     * Register a new user with Telegram ID and password
     * 
     * @param array $userData User data
     * @param string $deviceType Type of device ('web' or 'mobile')
     * @return array|null Array with tokens or null if registration failed
     */
    public function registerUser(array $userData, string $deviceType = 'web'): ?array
    {
        // Check if telegram_id is already taken
        if (User::where('telegram_id', $userData['telegram_id'])->exists()) {
            return null;
        }
        
        // Create user
        $user = new User();
        $user->name = $userData['name'];
        $user->password = $userData['password']; // will be hashed by the model
        $user->telegram_id = $userData['telegram_id'];
        $user->telegram_username = $userData['telegram_username'] ?? null;
        $user->save();
        
        // Create default settings for user
        $this->userSettingsService->createDefaultSettings($user->id);
        
        // Generate tokens
        $tokens = $this->jwtService->createTokens($user->id, $deviceType);
        
        // Return user data and tokens
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'telegram_id' => $user->telegram_id,
                'telegram_username' => $user->telegram_username
            ]
        ];
    }
    
    /**
     * Get user ID from access token
     * 
     * @param string $accessToken Access token
     * @return int|null User ID or null if token is invalid
     */
    public function getUserIdFromToken(string $accessToken): ?int
    {
        return $this->jwtService->getUserIdFromAccessToken($accessToken);
    }
    
    /**
     * Logout user
     * 
     * @param string $refreshToken Refresh token
     * @return bool True if logout successful
     */
    public function logout(string $refreshToken): bool
    {
        return $this->jwtService->removeRefreshToken($refreshToken);
    }
    
    /**
     * Logout user from all devices
     * 
     * @param int $userId User ID
     * @return bool True if logout successful
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