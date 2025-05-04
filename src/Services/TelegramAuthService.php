<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSettings;
use App\Utils\PasswordGenerator;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class TelegramAuthService
{
    private string $botToken;
    private JwtService $jwtService;
    private ContainerInterface $container;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $config = $container->get('config');
        $this->botToken = $config['telegram']['bot_token'] ?? '';
        $this->jwtService = $container->get(JwtService::class);
        $this->container = $container;
    }

    /**
     * Проверяет данные авторизации из Telegram
     */
    public function checkTelegramAuthorization(array $auth_data): bool
    {
        // Проверяем обязательные поля
        $check_fields = ['id', 'auth_date', 'hash'];
        foreach ($check_fields as $field) {
            if (!isset($auth_data[$field])) {
                return false;
            }
        }

        // В режиме разработки пропускаем проверку хеша для тестирования
        $environment = $this->container->get('config')['app']['env'] ?? 'production';
        if ($environment === 'local' && $auth_data['hash'] === 'test_hash') {
            return true;
        }

        // Проверяем, что авторизация не устарела (не старше 1 дня)
        if ((time() - $auth_data['auth_date']) > 86400) {
            return false;
        }
        
        // Проверка токена бота
        if (empty($this->botToken)) {
            // Отсутствует токен бота - ошибка настройки
            return false;
        }

        // Отделяем хеш от остальных данных
        $data_check_arr = $auth_data;
        $hash = $data_check_arr['hash'];
        unset($data_check_arr['hash']);

        // Сортируем массив по ключам
        ksort($data_check_arr);

        // Формируем строку данных
        $data_check_string = '';
        foreach ($data_check_arr as $key => $value) {
            $data_check_string .= $key . '=' . $value . "\n";
        }
        $data_check_string = trim($data_check_string);

        // Создаем секретный ключ на основе токена бота
        $secret_key = hash('sha256', $this->botToken, true);

        // Вычисляем хеш для проверки
        $hash_check = hash_hmac('sha256', $data_check_string, $secret_key);

        // Сравниваем хеши
        return hash_equals($hash, $hash_check);
    }

    /**
     * Авторизует пользователя через Telegram
     * Создает или обновляет пользователя в базе данных и возвращает JWT токены
     * @throws Exception
     */
    public function authenticateUserByTelegram(array $auth_data, string $deviceType = 'web'): ?array
    {
        /** Проверяем данные телеграм авторизации */
        if (!$this->checkTelegramAuthorization($auth_data)) {
            return null;
        }

        /** Находим или создаем пользователя */
        $user = User::query()->firstOrNew(['telegram_id' => $auth_data['id']]);
        $isNewUser = !$user->exists;

        /** Заполняем данные пользователя
         * @var User $user
         */
        if ($isNewUser) {
            /** Новый пользователь */
            $password = PasswordGenerator::generate(12);
            $user->name = $auth_data['first_name'] . (isset($auth_data['last_name']) ? ' ' . $auth_data['last_name'] : '');
            $user->password = $password;
        }

        /** Обновляем данные Telegram */
        $user->telegram_id = $auth_data['id'];
        $user->telegram_username = $auth_data['username'] ?? null;
        $user->telegram_photo_url = $auth_data['photo_url'] ?? null;
        $user->telegram_auth_date = $auth_data['auth_date'];
        $user->telegram_hash = $auth_data['hash'];
        $user->save();

        /** Создаем настройки для нового пользователя
         * @var UserSettings $settings
         */
        if ($isNewUser) {
            $settings = new UserSettings();
            $settings->user_id = $user->id;
            $settings->log_events = false;
            $settings->auto_call = false;
            $settings->telegram_notifications = false;
            $settings->save();
        }

        /** Генерируем JWT токены */
        $tokens = $this->jwtService->createTokens($user->id, $deviceType);
        
        // Возвращаем токены и данные пользователя
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
} 