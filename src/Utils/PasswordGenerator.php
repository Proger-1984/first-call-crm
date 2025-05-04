<?php

namespace App\Utils;

use Exception;

class PasswordGenerator
{
    /**
     * Генерирует случайный пароль заданной длины
     *
     * @param int $length Длина пароля (по умолчанию 8 символов)
     * @return string Сгенерированный пароль
     * @throws Exception
     */
    public static function generate(int $length = 8): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
} 