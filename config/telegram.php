<?php

return [
    /** Токен бота, полученный от @BotFather в Telegram */
    'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'],
    
    /** Имя пользователя бота (без символа @) */
    'bot_username' => $_ENV['TELEGRAM_BOT_USERNAME'],

    /** Админский чат для уведомлений */
    'admin_chat_id' => $_ENV['TELEGRAM_CHAT_ID'],

    /** URL-адрес вебхука (если используется) */
    'webhook_url' => $_ENV['TELEGRAM_WEBHOOK_URL'] ?? '',
];