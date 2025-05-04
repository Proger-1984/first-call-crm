<?php

return [
    'name' => $_ENV['APP_NAME'] ?? 'FIRST CALL REST API',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8080',
    'timezone' => 'Europe/Moscow',
    'locale' => 'ru',
]; 