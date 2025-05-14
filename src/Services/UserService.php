<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class UserService
{
    /**
     * Получить пользователя по ID
     */
    public function getUserById(int $userId): ?User
    {
        return User::find($userId);
    }

    /**
     * Обновить пароль пользователя
     */
    public function updateUserPassword(int $userId, string $newPassword): void
    {
        $user = $this->getUserById($userId);
        $user->password_hash = $newPassword;
        $user->save();
    }
} 