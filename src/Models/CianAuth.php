<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;
use JetBrains\PhpStorm\ArrayShape;

class CianAuth extends Model
{
    protected $table = 'cian_auth';
    
    protected $fillable = [
        'login',
        'password', 
        'auth_token',
        'is_active',
        'last_used_at',
        'comment'
    ];

    protected array $dates = [
        'last_used_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime'
    ];

    /**
     * Получает активный токен авторизации для Циан
     * @param int $categoryId
     * @param int $locationId
     * @return string|null
     */
    public static function getActiveAuthToken(int $categoryId, int $locationId): ?string
    {
        $auth = self::where('is_active', true)
            ->where('category_id', $categoryId)
            ->where('location_id', $locationId)
            ->orderBy('last_used_at', 'desc')
            ->first();
            
        if ($auth) {
            // Обновляем время последнего использования
            $auth->last_used_at = Carbon::now();
            $auth->save();
            
            return $auth->auth_token;
        }
        
        return null;
    }

    /**
     * Получает случайный активный токен авторизации
     * @return string|null
     */
    public static function getRandomActiveAuthToken(): ?string
    {
        $auths = self::where('is_active', true)->get();

        if ($auths->isEmpty()) {
            return null;
        }

        $auth = $auths->random();

        // Обновляем время последнего использования
        $auth->last_used_at = Carbon::now();
        $auth->save();

        return $auth->auth_token;
    }

    /**
     * Получает все активные токены
     * @return array
     */
    public static function getAllActiveTokens(): array
    {
        return self::where('is_active', true)
            ->pluck('auth_token')
            ->toArray();
    }

    /**
     * Добавляет новый токен авторизации
     * @param string $login
     * @param string|null $password
     * @param string $authToken
     * @param string|null $comment
     * @return self
     */
    public static function addAuthToken(string $login, ?string $password, string $authToken, ?string $comment = null): self
    {
        return self::create([
            'login' => $login,
            'password' => $password,
            'auth_token' => $authToken,
            'is_active' => true,
            'comment' => $comment
        ]);
    }

    /**
     * Деактивирует токен
     * @param int $id
     * @return bool
     */
    public static function deactivateToken(int $id): bool
    {
        return self::where('id', $id)->update(['is_active' => false]) > 0;
    }

    /**
     * Получает статистику использования токенов
     * @return array
     */
    #[ArrayShape(['total' => "mixed", 'active' => "mixed", 'inactive' => "mixed", 'recently_used' => "mixed"])]
    public static function getUsageStats(): array
    {
        return [
            'total' => self::count(),
            'active' => self::where('is_active', true)->count(),
            'inactive' => self::where('is_active', false)->count(),
            'recently_used' => self::where('last_used_at', '>=', Carbon::now()->subHours(24))->count()
        ];
    }
} 