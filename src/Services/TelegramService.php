<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Exception;
use App\Models\Tariff;

class TelegramService
{
    private string $botToken;
    private string $apiUrl = 'https://api.telegram.org/bot';
    private string $adminChatId;
    private SubscriptionService $subscriptionService;

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

    // 1. Уведомление о активации демо-подписки
    public function notifyDemoSubscriptionCreated(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $message = "🎯 <b>Демо-подписка активирована!</b>\n\n" .
            "Ваша демо-подписка на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> успешно активирована.\n\n" .
            "⏱ Доступ открыт до: <b>{$endDate}</b>\n\n" .
            "После окончания демо-периода вы сможете оформить премиум-подписку для продолжения работы.\n\n" .
            "Благодарим за выбор нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 2. Уведомление о активации премиум-подписки
    public function notifyPremiumSubscriptionActivated(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $message = "🚀 <b>Подписка успешно активирована!</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> успешно активирована.\n\n" .
            "⏱ Доступ открыт до: <b>{$endDate}</b>\n\n" .
            "Благодарим за выбор нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 3. Уведомление о создании заявки на платную подписку
    public function notifyPremiumSubscriptionRequested(User $user, UserSubscription $subscription): bool
    {
        $message = "📝 <b>Заявка на подписку создана</b>\n\n" .
            "Ваша заявка на подписку <b>{$subscription->tariff->name}</b> для категории <b>{$subscription->category->name}</b> " .
            "и локации <b>{$subscription->location->getFullName()}</b> успешно создана и ожидает подтверждения.\n\n" .
            "💳 <b>ДЛЯ АКТИВАЦИИ НЕОБХОДИМО:</b>\n" .
            "1️⃣ Оплатить по реквизитам:\n" .
            "• Карта Сбербанк: <code>2202203203273984</code>\n" .
            "• Получатель: Александр А.\n" .
            "• Сумма к оплате: <b>{$subscription->price_paid} ₽</b>\n\n" .
            "2️⃣ Прислать скриншот чека в <a href='https://t.me/firstcall_support'>службу поддержки</a>\n" .
            "3️⃣ Обязательно укажите ID заявки: <code>{$subscription->id}</code>\n\n" .

            "После подтверждения оплаты подписка будет активирована, и вы получите уведомление.\n\n" .
            "По всем вопросам обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 3. Уведомление о скором истечении подписки
    public function notifySubscriptionExpiringSoon(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $remainingTime = Carbon::now()->diffForHumans($subscription->end_date, ['parts' => 2]);
        
        $message = "⚠️ <b>Срок действия подписки истекает!</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> истекает <b>{$endDate}</b> ({$remainingTime}).\n\n" .
            "Для сохранения доступа к сервису рекомендуем своевременно продлить подписку в " .
            "<a href='https://realtor.first-call.ru'>личном кабинете</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 4. Уведомление об истечении подписки
    public function notifySubscriptionExpired(User $user, UserSubscription $subscription): bool
    {
        $message = "🔒 <b>Подписка закончилась</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> закончилась.\n\n" .
            "Для возобновления доступа продлите подписку в " .
            "<a href='https://realtor.first-call.ru'>личном кабинете</a> или свяжитесь с " .
            "<a href='https://t.me/firstcall_support'>службой поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 6. Уведомление об отмене подписки
    public function notifySubscriptionCancelled(User $user, UserSubscription $subscription, string $reason): bool
    {
        $message = "❌ <b>Подписка отменена</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> была отменена.\n\n" .
            "Причина: <i>{$reason}</i>\n\n" .
            "По всем вопросам обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 7. Уведомление о продлении подписки
    public function notifySubscriptionExtended(User $user, UserSubscription $subscription): bool
    {
        $endDate = $subscription->end_date->format('d.m.Y H:i');
        $message = "🔄 <b>Подписка успешно продлена!</b>\n\n" .
            "Ваша подписка <b>{$subscription->tariff->name}</b> на категорию <b>{$subscription->category->name}</b> " .
            "для локации <b>{$subscription->location->getFullName()}</b> успешно продлена.\n\n" .
            "⏱ Доступ продлен до: <b>{$endDate}</b>\n\n" .
            "Благодарим за использование нашего сервиса! Если у вас возникнут вопросы, " .
            "обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }

    // 8. Уведомление администратору о новой заявке на подписку
    public function notifyAdminNewSubscriptionRequest(UserSubscription $subscription): bool
    {
        $userName = $subscription->user->name;
        $userId = $subscription->user->id;
        $subId = $subscription->id;
        $category = $subscription->category->name;
        $location = $subscription->location->getFullName();
        $tariff = $subscription->tariff->name;
        $price = $subscription->price_paid;
        
        $message = "🆕 <b>Новая заявка на подписку #{$subId}</b>\n\n" .
            "👤 Пользователь: <b>{$userName}</b> (ID: {$userId})\n" .
            "🏷 Тариф: <b>{$tariff}</b>\n" .
            "📋 Категория: <b>{$category}</b>\n" .
            "📍 Локация: <b>{$location}</b>\n" .
            "💰 Сумма: <b>{$price} руб.</b>\n\n" .
            "Для обработки заявки перейдите в <a href='https://realtor.first-call.ru/subscriptions/pending'>панель администратора</a>.";
            
        return $this->sendMessage($this->adminChatId, $message);
    }

    // 9. Уведомление администратору о запросе на продление подписки
    public function notifyAdminsAboutExtendRequest(User $user, UserSubscription $subscription, $tariff, ?string $notes = null): bool
    {
        $userName = $user->name;
        $userId = $user->id;
        $subId = $subscription->id;
        $category = $subscription->category->name;
        $location = $subscription->location->getFullName();
        $currentTariff = $subscription->tariff->name;
        $newTariff = $tariff->name;
        $price = $this->subscriptionService->getTariffPrice($tariff->id, $subscription->location_id);
        
        $message = "🔄 <b>Запрос на продление подписки #{$subId}</b>\n\n" .
            "👤 Пользователь: <b>{$userName}</b> (ID: {$userId})\n" .
            "🏷 Текущий тариф: <b>{$currentTariff}</b>\n" .
            "🏷 Новый тариф: <b>{$newTariff}</b>\n" .
            "📋 Категория: <b>{$category}</b>\n" .
            "📍 Локация: <b>{$location}</b>\n" .
            "💰 Сумма: <b>{$price} руб.</b>\n";
            
        if ($notes) {
            $message .= "📝 Комментарий: <i>{$notes}</i>\n";
        }
        
        $message .= "\nДля обработки заявки перейдите в <a href='https://realtor.first-call.ru/subscriptions/pending'>панель администратора</a>.";
            
        return $this->sendMessage($this->adminChatId, $message);
    }

    /**
     * Отправляет уведомление о регистрации с логином и паролем от приложения
     * 
     * @param string $chatId ID чата пользователя
     * @param string $username Имя пользователя
     * @param string $password Сгенерированный пароль
     * @return bool Успешность отправки
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

            return $this->sendMessage($chatId, $message);
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
     */
    public function sendRebindNotification(string $telegramId, string $userId, string $userName, ?string $oldTelegramId): bool
    {
        try {
            $message = "🔄 *Перепривязка Telegram аккаунта*\n\n";
            $message .= "👤 Пользователь: `$userName`\n";
            $message .= "🆔 ID в системе: `$userId`\n";
            $message .= "📱 Новый Telegram ID: `$telegramId`\n";
            
            if ($oldTelegramId) {
                $message .= "📱 Старый Telegram ID: `$oldTelegramId`\n";
            }
            
            $message .= "\n⏰ Время: " . date('Y-m-d H:i:s');

            return $this->sendMessage($telegramId, $message);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Отправляет уведомление с новым паролем для приложения
     */
    public function sendPasswordNotification(string $telegramId, string $userId, string $newPassword): bool
    {
        try {
            $message = "🔑 *Новый пароль для приложения*\n\n";
            $message .= "Ваш логин: `$userId`\n";
            $message .= "Новый пароль: `$newPassword`\n\n";
            $message .= "Используйте эти данные для входа в мобильное приложение.";

            return $this->sendMessage($telegramId, $message);
        } catch (Exception) {
            return false;
        }
    }

    // Уведомление о создании заявки на продление подписки
    public function notifyExtendSubscriptionRequested(User $user, UserSubscription $subscription, Tariff $tariff, ?string $notes = null): bool
    {
        $tariffName = $tariff->name;
        $categoryName = $subscription->category->name;
        $locationName = $subscription->location->getFullName();
        $price = $this->subscriptionService->getTariffPrice($tariff->id, $subscription->location_id);

        $message = "📝 <b>Заявка на продление подписки создана</b>\n\n" .
            "Ваша заявка на продление подписки <b>{$tariffName}</b> для категории <b>{$categoryName}</b> " .
            "и локации <b>{$locationName}</b> успешно создана и ожидает подтверждения.\n\n" .
            "💳 <b>ДЛЯ АКТИВАЦИИ НЕОБХОДИМО:</b>\n" .
            "1️⃣ Оплатить по реквизитам:\n" .
            "• Карта Сбербанк: <code>2202203203273984</code>\n" .
            "• Получатель: Александр А.\n" .
            "• Сумма к оплате: <b>{$price} ₽</b>\n\n" .
            "2️⃣ Прислать скриншот чека в <a href='https://t.me/firstcall_support'>службу поддержки</a>\n" .
            "3️⃣ Обязательно укажите ID подписки: <code>{$subscription->id}</code>\n\n" .
            "После подтверждения оплаты подписка будет продлена, и вы получите уведомление.\n\n" .
            "По всем вопросам обращайтесь в <a href='https://t.me/firstcall_support'>службу поддержки</a>.";

        return $this->sendMessage($user->telegram_id, $message);
    }
} 