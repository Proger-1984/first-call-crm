<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Utils\PasswordGenerator;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class TelegramAuthService extends BaseAuthService
{
    private string $botToken;
    private TelegramService $telegramService;
    private TariffService $tariffService;
    private UserSettingsService $userSettingsService;
    protected SourceService $sourceService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $config = $container->get('config');
        $this->botToken = $config['telegram']['bot_token'] ?? '';
        $this->telegramService = $container->get(TelegramService::class);
        $this->tariffService = $container->get(TariffService::class);
        $this->userSettingsService = $container->get(UserSettingsService::class);
        $this->sourceService = $container->get(SourceService::class);
    }

    /**
     * Проверяет данные авторизации из Telegram
     */
    public function checkTelegramAuthorization(array $auth_data): bool
    {
        /** Проверяем обязательные поля */
        $check_fields = ['id', 'auth_date', 'hash'];
        foreach ($check_fields as $field) {
            if (!isset($auth_data[$field])) {
                return false;
            }
        }

        /** Проверяем, что авторизация не устарела (не старше 1 дня) */
        if ((time() - $auth_data['auth_date']) > 86400) {
            return false;
        }

        /** Проверка токена бота */
        if (empty($this->botToken)) {
            return false;
        }

        /** Отделяем хеш от остальных данных */
        $data_check_arr = $auth_data;
        $hash = $data_check_arr['hash'];
        unset($data_check_arr['hash']);

        /** Сортируем массив по ключам */
        ksort($data_check_arr);

        /** Формируем строку данных */
        $data_check_string = '';
        foreach ($data_check_arr as $key => $value) {
            $data_check_string .= $key . '=' . $value . "\n";
        }
        $data_check_string = trim($data_check_string);

        /** Создаем секретный ключ на основе токена бота */
        $secret_key = hash('sha256', $this->botToken, true);

        /** Вычисляем хеш для проверки */
        $hash_check = hash_hmac('sha256', $data_check_string, $secret_key);

        /** Сравниваем хеши */
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
        $generatedPassword = null;
        if ($isNewUser) {
            /** Новый пользователь */
            $generatedPassword = PasswordGenerator::generate(6);
            $user->name = $auth_data['first_name'] . (isset($auth_data['last_name']) ? ' ' . $auth_data['last_name'] : '');
            $user->password_hash = $generatedPassword;
        }

        /** Обновляем данные Telegram */
        $user->telegram_id = $auth_data['id'];
        $user->telegram_username = $auth_data['username'] ?? null;
        $user->telegram_photo_url = $auth_data['photo_url'] ?? null;
        $user->telegram_auth_date = $auth_data['auth_date'];
        $user->telegram_hash = $auth_data['hash'];
        $user->save();

        /** Создаем настройки для нового пользователя */
        if ($isNewUser) {

            /** Настройки по умолчанию для нового пользователя */
            $this->userSettingsService->createDefaultSettings($user->id);

            /** Назначаем демо-тариф для нового пользователя */
            $this->tariffService->assignDemoTariff($user);

            /** Устанавливаем все источники для нового пользователя */
            $allSources = $this->sourceService->getAllSources();
            $sourceIds = [];
            foreach ($allSources as $source) {
                if (isset($source->id)) {
                    $sourceIds[] = (int)$source->id;
                }
            }

            if (!empty($sourceIds)) {
                $this->sourceService->setUserSources($user, $sourceIds);
            }

            /** Отправляем уведомление о регистрации */
            $this->telegramService->sendRegistrationNotification(
                (string)$auth_data['id'],
                (string)$user->id,
                (string)$user->name,
                (string)$generatedPassword
            );
        }

        /** Генерируем JWT токены */
        $tokens = $this->jwtService->createTokens($user->id, $deviceType);
        
        /** Возвращаем токены и данные пользователя */
        return $this->createAuthResponse($user, $tokens);
    }
} 