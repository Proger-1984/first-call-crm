<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class TelegramService
{
    private string $botToken;
    private string $apiUrl = 'https://api.telegram.org/bot';

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $config = $container->get('config');
        $this->botToken = $config['telegram']['bot_token'] ?? '';
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * 
     * @param string $chatId ID чата пользователя
     * @param string $message Текст сообщения
     * @return bool Успешность отправки
     */
    public function sendMessage(string $chatId, string $message): bool
    {
        if (empty($this->botToken)) {
            return false;
        }

        $url = $this->apiUrl . $this->botToken . '/sendMessage';
        
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        return $result !== false;
    }

    /**
     * Отправляет уведомление о регистрации с логином и паролем
     * 
     * @param string $chatId ID чата пользователя
     * @param string $username Имя пользователя
     * @param string $password Сгенерированный пароль
     * @return bool Успешность отправки
     */
    public function sendRegistrationNotification(string $chatId, string $login, string $username, string $password): bool
    {
        $message = "🎉 <b>Добро пожаловать в First Call!</b>\n\n" .
                  "Ваша регистрация успешно завершена.\n\n" .
                  "Для входа в систему используйте следующие данные:\n" .
                  "👤 <b>Логин:</b> $login\n" .
                  "🔑 <b>Пароль:</b> $password\n\n" .
                  "⚠️ <i>Сохраните эти данные в надежном месте. Пароль больше не будет доступен.</i>";

        return $this->sendMessage($chatId, $message);
    }
} 