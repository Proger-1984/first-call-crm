<?php

return [
    /** Общие настройки */
    'algorithm' => $_ENV['JWT_ALGO'] ?? 'HS256',

    /** Настройки access токена */
    'access_secret' => $_ENV['JWT_SECRET'],
    'access_expiration' => (int)$_ENV['JWT_ACCESS_EXPIRATION'],

    /** Настройки refresh токена */
    'refresh_secret' => $_ENV['JWT_REFRESH_SECRET'],
    'refresh_expiration' => (int)$_ENV['JWT_REFRESH_EXPIRATION'],
];
