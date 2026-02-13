<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Exception;
use App\Models\Tariff;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

class TelegramService
{
    private string $botToken;
    private string $apiUrl = 'https://api.telegram.org/bot';
    private string $adminChatId;
    private SubscriptionService $subscriptionService;
    private QrCodeService $qrCodeService;
    private LoggerInterface $logger;
    private Client $httpClient;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $config = $container->get('config');
        $this->botToken = $config['telegram']['bot_token'] ?? '';
        $this->adminChatId = $config['telegram']['admin_chat_id'] ?? '';
        $this->subscriptionService = $container->get(SubscriptionService::class);
        $this->qrCodeService = $container->get(QrCodeService::class);
        $this->logger = $container->get(LoggerInterface::class);
        $this->httpClient = new Client(['timeout' => 10.0]);
    }

    /**
     * Отправляет запрос к методу sendMessage Telegram API
     * 
     * @param array $params Параметры запроса
     * @return array Результат запроса и флаги успеха/блокировки
     * @throws GuzzleException
     */
    private function callSendMessageApi(array $params): array 
    {
        if (empty($this->botToken)) {
            return [
                'success' => false, 
                'error' => 'Bot token is empty',
                'blocked' => false,
                'data' => null
            ];
        }

        $url = $this->apiUrl . $this->botToken . '/sendMessage';
        
        try {
            $response = $this->httpClient->post($url, [
                'form_params' => $params,
                'http_errors' => false
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);
            
            // Проверяем на блокировку бота (403 Forbidden)
            $isBlocked = $statusCode === 403;
            
            if ($statusCode !== 200 || !isset($responseBody['ok']) || $responseBody['ok'] !== true) {
                $error = $responseBody['description'] ?? "HTTP Error: $statusCode";
                
                return [
                    'success' => false,
                    'error' => $error,
                    'blocked' => $isBlocked,
                    'data' => $responseBody
                ];
            }
            
            return [
                'success' => true,
                'error' => null,
                'blocked' => false,
                'data' => $responseBody['result'] ?? null
            ];
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $isBlocked = $response && $response->getStatusCode() === 403;
            $errorMessage = $e->getMessage();
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'blocked' => $isBlocked,
                'data' => null
            ];
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'blocked' => false,
                'data' => null
            ];
        }
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * 
     * @param string $chatId ID чата пользователя
     * @param string $message Текст сообщения
     * @return array Результат отправки: ['success' => bool, 'error' => ?string, 'blocked' => bool]
     * @throws GuzzleException
     */
    #[ArrayShape(['success' => "mixed", 'error' => "mixed", 'blocked' => "mixed"])]
    public function sendMessage(string $chatId, string $message): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        
        $result = $this->callSendMessageApi($params);
        
        return [
            'success' => $result['success'],
            'error' => $result['error'],
            'blocked' => $result['blocked']
        ];
    }

    /**
     * Отправляет фото с подписью через Telegram
     * 
     * @param string $chatId ID чата пользователя
     * @param string $photoBase64 Base64-encoded изображение
     * @param string $caption Подпись к фото
     * @return array Результат отправки
     * @throws GuzzleException
     */
    public function sendPhoto(string $chatId, string $photoBase64, string $caption): array
    {
        if (empty($this->botToken)) {
            return ['success' => false, 'error' => 'Bot token is empty', 'blocked' => false];
        }

        $url = $this->apiUrl . $this->botToken . '/sendPhoto';
        
        try {
            $response = $this->httpClient->post($url, [
                'multipart' => [
                    [
                        'name' => 'chat_id',
                        'contents' => $chatId
                    ],
                    [
                        'name' => 'photo',
                        'contents' => base64_decode($photoBase64),
                        'filename' => 'qr_payment.png'
                    ],
                    [
                        'name' => 'caption',
                        'contents' => $caption
                    ],
                    [
                        'name' => 'parse_mode',
                        'contents' => 'HTML'
                    ]
                ],
                'http_errors' => false
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);
            
            $isBlocked = $statusCode === 403;
            
            if ($statusCode !== 200 || !isset($responseBody['ok']) || $responseBody['ok'] !== true) {
                return [
                    'success' => false,
                    'error' => $responseBody['description'] ?? "HTTP Error: $statusCode",
                    'blocked' => $isBlocked
                ];
            }
            
            return ['success' => true, 'error' => null, 'blocked' => false];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'blocked' => false];
        }
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление о активации демо-подписки
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyDemoSubscriptionCreated(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $message = "🎯 <b>Демо-подписка активирована!</b>\n\n" .
            "Ваша демо-подписка на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> успешно активирована.\n\n" .
            "⏱ Доступ открыт до: <b>$endDate</b>\n\n" .
            "После окончания демо-периода вы сможете оформить премиум-подписку для продолжения работы.\n\n" .
            "Благодарим за выбор нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление о активации премиум-подписки
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyPremiumSubscriptionActivated(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $message = "🚀 <b>Подписка успешно активирована!</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> успешно активирована.\n\n" .
            "⏱ Доступ открыт до: <b>$endDate</b>\n\n" .
            "Благодарим за выбор нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление о создании заявки на платную подписку
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyPremiumSubscriptionRequested(User $user, UserSubscription $subscription): bool
    {
        $price = (float) $subscription->price_paid;
        $subscriptionId = $subscription->id;
        $userId = $user->id;
        
        $cardNumber = $this->qrCodeService->getCardNumber();
        $cardHolder = $this->qrCodeService->getCardHolder();
        $paymentPurpose = $this->qrCodeService->buildPaymentPurpose($subscriptionId, $userId);
        
        $message = "📝 <b>Заявка на подписку создана</b>\n\n" .
            "Подписка: <b>{$subscription->tariff->name}</b>\n" .
            "Категория: <b>{$subscription->category->name}</b>\n" .
            "Локация: <b>{$subscription->location->getFullName()}</b>\n\n" .
            "💳 <b>ДЛЯ ОПЛАТЫ ПЕРЕВЕДИТЕ НА КАРТУ:</b>\n" .
            "• Карта Сбербанк: <code>" . str_replace(' ', '', $cardNumber) . "</code>\n" .
            "• Получатель: $cardHolder\n" .
            "• Сумма: <b>" . number_format($price, 0, ',', ' ') . " ₽</b>\n\n" .
            "📋 <b>В комментарии к переводу укажите:</b>\n" .
            "<code>$paymentPurpose</code>\n\n" .
            "✅ <b>ПОСЛЕ ОПЛАТЫ:</b>\n" .
            "Отправьте скриншот чека в <a href='https://t.me/firstcall_support'>поддержку</a>\n\n" .
            "После подтверждения оплаты подписка будет активирована.";
        
        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление о создании заявки на продление подписки с QR-кодом для оплаты
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @param Tariff $tariff
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyExtendSubscriptionRequested(User $user, UserSubscription $subscription, Tariff $tariff): bool
    {
        $tariffName = $tariff->name;
        $categoryName = $subscription->category->name;
        $locationName = $subscription->location->getFullName();
        $price = (float) $this->subscriptionService->getTariffPrice($tariff->id, $subscription->location_id, $subscription->category_id);
        $subscriptionId = $subscription->id;
        $userId = $user->id;

        $cardNumber = $this->qrCodeService->getCardNumber();
        $cardHolder = $this->qrCodeService->getCardHolder();
        $paymentPurpose = $this->qrCodeService->buildPaymentPurpose($subscriptionId, $userId);
        
        $message = "🔄 <b>Заявка на продление подписки создана</b>\n\n" .
            "Подписка: <b>$tariffName</b>\n" .
            "Категория: <b>$categoryName</b>\n" .
            "Локация: <b>$locationName</b>\n\n" .
            "💳 <b>ДЛЯ ОПЛАТЫ ПЕРЕВЕДИТЕ НА КАРТУ:</b>\n" .
            "• Карта Сбербанк: <code>" . str_replace(' ', '', $cardNumber) . "</code>\n" .
            "• Получатель: $cardHolder\n" .
            "• Сумма: <b>" . number_format($price, 0, ',', ' ') . " ₽</b>\n\n" .
            "📋 <b>В комментарии к переводу укажите:</b>\n" .
            "<code>$paymentPurpose</code>\n\n" .
            "✅ <b>ПОСЛЕ ОПЛАТЫ:</b>\n" .
            "Отправьте скриншот чека в <a href='https://t.me/firstcall_support'>поддержку</a>\n\n" .
            "После подтверждения оплаты подписка будет продлена.";
        
        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление о продлении подписки
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifySubscriptionExtended(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $message = "🔄 <b>Подписка успешно продлена!</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> успешно продлена.\n\n" .
            "⏱ Доступ продлен до: <b>$endDate</b>\n\n" .
            "Благодарим за использование нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление об отмене подписки
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @param string $reason
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifySubscriptionCancelled(User $user, UserSubscription $subscription, string $reason): bool
    {
        $message = "❌ <b>Подписка отменена</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> была отменена.\n\n" .
            "Причина: <i>$reason</i>\n\n" .
            "По всем вопросам обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление администратору о новой заявке на подписку
     *
     * @param UserSubscription $subscription
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyAdminNewSubscriptionRequest(UserSubscription $subscription): bool
    {
        $userName = $subscription->user->name;
        $userId = $subscription->user->id;
        $subId = $subscription->id;
        $category = $subscription->category->name;
        $location = $subscription->location->getFullName();
        $tariff = $subscription->tariff->name;
        $price = $subscription->price_paid;
        $paymentPurpose = $this->qrCodeService->buildPaymentPurpose($subId, $userId);
        
        $message = "🆕 <b>Новая заявка на подписку #$subId</b>\n\n" .
            "👤 Пользователь: <b>$userName</b> (ID: $userId)\n" .
            "🏷 Тариф: <b>$tariff</b>\n" .
            "📋 Категория: <b>$category</b>\n" .
            "📍 Локация: <b>$location</b>\n" .
            "💰 Сумма: <b>$price руб.</b>\n\n" .
            "📋 Ожидаемое назначение платежа:\n<code>$paymentPurpose</code>\n\n" .
            "Для обработки заявки перейдите в <a href='https://realtor.first-call.ru/subscriptions/pending'>панель администратора</a>.";
            
        $result = $this->sendMessage($this->adminChatId, $message);
        return $result['success'];
    }

    /**
     * Отправляет сообщение пользователю через Telegram
     * Уведомление администратору о запросе на продление подписки
     *
     * @param User $user
     * @param UserSubscription $subscription
     * @param $tariff
     * @param string|null $notes
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyAdminsAboutExtendRequest(User $user, UserSubscription $subscription, $tariff, ?string $notes = null): bool
    {
        $userName = $user->name;
        $userId = $user->id;
        $subId = $subscription->id;
        $category = $subscription->category->name;
        $location = $subscription->location->getFullName();
        $currentTariff = $subscription->tariff->name;
        $newTariff = $tariff->name;
        $price = $this->subscriptionService->getTariffPrice($tariff->id, $subscription->location_id, $subscription->category_id);
        $paymentPurpose = $this->qrCodeService->buildPaymentPurpose($subId, $userId);
        
        $message = "🔄 <b>Запрос на продление подписки #$subId</b>\n\n" .
            "👤 Пользователь: <b>$userName</b> (ID: $userId)\n" .
            "🏷 Текущий тариф: <b>$currentTariff</b>\n" .
            "🏷 Новый тариф: <b>$newTariff</b>\n" .
            "📋 Категория: <b>$category</b>\n" .
            "📍 Локация: <b>$location</b>\n" .
            "💰 Сумма: <b>$price руб.</b>\n";
            
        if ($notes) {
            $message .= "📝 Комментарий: <i>$notes</i>\n";
        }
        
        $message .= "\n📋 Ожидаемое назначение платежа:\n<code>$paymentPurpose</code>\n";
        $message .= "\nДля обработки заявки перейдите в <a href='https://realtor.first-call.ru/subscriptions/pending'>панель администратора</a>.";
            
        $result = $this->sendMessage($this->adminChatId, $message);
        return $result['success'];
    }

    /**
     * Отправляет уведомление о регистрации через Telegram
     * с логином и паролем от приложения
     *
     * @param string $chatId ID чата пользователя
     * @param string $login Логин пользователя
     * @param string $username Имя пользователя
     * @param string $password Сгенерированный пароль
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function sendRegistrationNotification(string $chatId, string $login, string $username, string $password): bool
    {
        try {
            $message = "🎉 <b>$username, добро пожаловать в First Call!</b>\n\n" .
                "Ваша регистрация успешно завершена.\n\n" .
                "📱 Данные для входа через приложение:\n" .
                "👤 <b>Логин:</b> <code>$login</code>\n" .
                "🔑 <b>Пароль:</b> <code>$password</code>\n\n" .
                "🚀 <b>Следующие шаги:</b>\n" .
                "• Выберите тариф (демо на 3 часа или премиум)\n" .
                "• Настройте локации\n\n" .
                "🔗 <b>Полезные ссылки:</b>\n" .
                "• <a href=\"https://realtor.first-call.ru\">Личный кабинет</a>\n" .
                "• <a href=\"https://t.me/firstcall_support\">Написать в поддержку</a>\n" .
                "• <a href=\"https://t.me/callfirst\">Телеграм канал</a>\n" .
                "• <a href=\"https://realtor.first-call.ru\">Инструкции по работа с сервисом</a>\n\n" .
                "⚠️<i>Сохраните данные для входа через приложение в надежном месте. В случае утери пароля вы можете сгенерировать новый в личном кабинете.</i>";

            $result = $this->sendMessage($chatId, $message);
            
            // Проверка на блокировку не нужна, т.к. пользователь только регистрируется
            
            return $result['success'];
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Отправляет сообщение с новым паролем для приложения
     * 
     * @param User $user Пользователь
     * @param string $newPassword Новый пароль
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function sendPasswordNotification(User $user, string $newPassword): bool
    {
        try {
            $message = "🔐 <b>Новый пароль для приложения First Call</b>\n\n" .
                "Ваш новый пароль был успешно сгенерирован:\n\n" .
                "👤 <b>Логин:</b> <code>" . $user->id . "</code>\n" .
                "🔑 <b>Пароль:</b> <code>" . $newPassword . "</code>\n\n" .
                "📱 <b>Как использовать:</b>\n" .
                "• Используйте эти данные для входа в мобильное приложение\n" .
                "• Храните пароль в надежном месте\n" .
                "• Никому не сообщайте эти данные\n\n" .
                "❓ Если вы не запрашивали новый пароль или у вас возникли вопросы, обратитесь в " .
                "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";

            $result = $this->sendMessage($user->telegram_id, $message);
            
            // Обновляем статус блокировки бота
            if ($result['success'] && $user->telegram_bot_blocked) {
                $this->updateBotBlockedStatus($user, false);
            } else if ($result['blocked']) {
                $this->updateBotBlockedStatus($user, true);
            }
            
            return $result['success'];
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Отправляет уведомление о перепривязке Telegram аккаунта
     * 
     * @param string $telegramId Новый Telegram ID пользователя
     * @param string $userId ID пользователя в системе
     * @param string $userName Имя пользователя
     * @param string|null $oldTelegramId Старый Telegram ID пользователя
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function sendRebindNotification(string $telegramId, string $userId, string $userName, ?string $oldTelegramId): bool
    {
        try {
            $message = "🔄 <b>Перепривязка Telegram аккаунта</b>\n\n" .
                "Ваш Telegram аккаунт успешно привязан к учетной записи First Call!\n\n" .
                "👤 <b>Пользователь:</b> " . htmlspecialchars($userName) . "\n" .
                "🆔 <b>ID в системе:</b> <code>" . htmlspecialchars($userId) . "</code>\n" .
                "📱 <b>Новый Telegram ID:</b> <code>" . htmlspecialchars($telegramId) . "</code>\n";
            
            if ($oldTelegramId) {
                $message .= "📱 <b>Старый Telegram ID:</b> <code>" . htmlspecialchars($oldTelegramId) . "</code>\n";
            }
            
            $message .= "\n⏰ <b>Время:</b> " . date('Y-m-d H:i:s') . "\n\n" .
                "✅ <b>Что это значит:</b>\n" .
                "• Теперь вы будете получать уведомления на этот аккаунт\n" .
                "• Предыдущий аккаунт Telegram больше не привязан к системе\n" .
                "• Доступ к функциям First Call через этот аккаунт подтвержден\n\n" .
                "❓ Если вы не выполняли перепривязку или у вас возникли вопросы, обратитесь в " .
                "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";

            $result = $this->sendMessage($telegramId, $message);
            
            // Проверка на блокировку не нужна, т.к. это новый привязанный телеграм
            
            return $result['success'];
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Обновляет статус блокировки бота пользователем
     * 
     * @param User $user Пользователь
     * @param bool $isBlocked Статус блокировки
     * @return void
     */
    private function updateBotBlockedStatus(User $user, bool $isBlocked): void
    {
        // Обновляем статус только если он изменился
        if ($user->telegram_bot_blocked !== $isBlocked) {
            $user->telegram_bot_blocked = $isBlocked;
            $user->save();
        }
    }

    /**
     * Отправляет уведомление о скором окончании подписки
     * 
     * @param User $user Пользователь
     * @param UserSubscription $subscription Подписка
     * @param int $days Количество дней до окончания
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifySubscriptionExpiring(User $user, UserSubscription $subscription, int $days): bool
    {
        // Формируем текст склонения дней
        $daysText = $this->getDaysText($days);
        
        // Формируем дату окончания
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        
        $message = "⚠️ <b>Скоро закончится срок действия подписки</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> истекает через $daysText.\n\n" .
            "⏱ Дата окончания: <b>$endDate</b>\n\n" .
            "Для продления подписки перейдите в раздел «Подписки» в приложении или обратитесь в " .
            "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";
        
        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота пользователем
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }
    
    /**
     * Отправляет уведомление о скором окончании демо-подписки
     * 
     * @param User $user Пользователь
     * @param UserSubscription $subscription Демо-подписка
     * @param int $minutes Количество минут до окончания
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyDemoSubscriptionExpiring(User $user, UserSubscription $subscription, int $minutes): bool
    {
        // Формируем текст склонения минут
        $minutesText = $this->getMinutesText($minutes);
        
        // Формируем дату окончания
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        
        $message = "⏳ <b>Скоро закончится срок действия демо-подписки</b>\n\n" .
            "Ваша демо-подписка на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> истекает через $minutesText.\n\n" .
            "⏱ Дата окончания: <b>$endDate</b>\n\n" .
            "Для получения полного доступа оформите платную подписку в разделе «Подписки» приложения или обратитесь в " .
            "<a href='https://t.me/firstcall_support'>службу поддержки</a>.";
        
        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота пользователем
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }
    
    /**
     * Отправляет уведомление об истечении срока действия подписки
     * 
     * @param User $user Пользователь
     * @param UserSubscription $subscription Подписка
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifySubscriptionExpired(User $user, UserSubscription $subscription): bool
    {
        // Формируем дату окончания
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        
        $message = "❌ <b>Срок действия подписки истек</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> закончилась.\n\n" .
            "⏱ Дата окончания: <b>$endDate</b>\n\n" .
            "Оформите премиум-подписку для продолжения работы, для этого перейдите в раздел «Подписки».\n\n" .
            "Благодарим за выбор нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";
        
        $result = $this->sendMessage($user->telegram_id, $message);
        
        // Обновляем статус блокировки бота пользователем
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } else if ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }
        
        return $result['success'];
    }
    
    /**
     * Отправляет CRM-напоминание пользователю через Telegram
     *
     * Формат:
     * 🔔 Напоминание CRM
     * 📍 Объект: {address}
     * 👤 Контакт: {contact_name}, {phone}
     * 📋 Стадия: {stage_name}
     * 💬 {message}
     *
     * @param User $user Пользователь-получатель
     * @param \App\Models\Reminder $reminder Напоминание с загруженными связями
     * @return bool Успешность отправки
     * @throws GuzzleException
     */
    public function notifyCrmReminder(User $user, \App\Models\Reminder $reminder): bool
    {
        $property = $reminder->objectClient?->property;
        $contact = $reminder->objectClient?->contact;
        $stage = $reminder->objectClient?->pipelineStage;

        $address = $property?->address ?? $property?->title ?? 'Не указан';
        $contactName = $contact?->name ?? 'Не указан';
        $contactPhone = $contact?->phone ?? '';
        $stageName = $stage?->name ?? '—';

        $message = "🔔 <b>Напоминание CRM</b>\n\n" .
            "📍 <b>Объект:</b> {$address}\n" .
            "👤 <b>Контакт:</b> {$contactName}" . ($contactPhone ? ", {$contactPhone}" : '') . "\n" .
            "📋 <b>Стадия:</b> {$stageName}\n\n" .
            "💬 {$reminder->message}";

        $result = $this->sendMessage($user->telegram_id, $message);

        // Обновляем статус блокировки бота
        if ($result['success'] && $user->telegram_bot_blocked) {
            $this->updateBotBlockedStatus($user, false);
        } elseif ($result['blocked']) {
            $this->updateBotBlockedStatus($user, true);
        }

        return $result['success'];
    }

    /**
     * Возвращает текстовое представление дней
     */
    private function getDaysText(int $days): string
    {
        if ($days === 1) {
            return '1 день';
        } elseif ($days > 1 && $days < 5) {
            return "$days дня";
        } else {
            return "$days дней";
        }
    }
    
    /**
     * Возвращает текстовое представление минут
     */
    private function getMinutesText(int $minutes): string
    {
        if ($minutes == 60) {
            return '1 час';
        } elseif ($minutes == 30) {
            return '30 минут';
        } elseif ($minutes == 15) {
            return '15 минут';
        } elseif ($minutes % 10 == 1 && $minutes % 100 != 11) {
            return "$minutes минуту";
        } elseif (($minutes % 10 >= 2 && $minutes % 10 <= 4) && 
                 !($minutes % 100 >= 12 && $minutes % 100 <= 14)) {
            return "$minutes минуты";
        } else {
            return "$minutes минут";
        }
    }
} 