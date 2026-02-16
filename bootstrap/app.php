<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

/** Загружаем переменные окружения */
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

/** Загружаем конфигурации */
$config = [
    'app' => require __DIR__ . '/../config/app.php',
    'database' => require __DIR__ . '/../config/database.php',
    'jwt' => require __DIR__ . '/../config/jwt.php',
    'error_handler' => require __DIR__ . '/../config/error_handler.php',
    'telegram' => require __DIR__ . '/../config/telegram.php',
    'yandex' => require __DIR__ . '/../config/yandex.php',
];

/** Инициализируем Eloquent ORM */
$capsule = new Capsule;
$capsule->addConnection($config['database']);

/** Делаем Capsule глобально доступным */
$capsule->setAsGlobal();

/** Запускаем Eloquent ORM */
$capsule->bootEloquent();

/** Устанавливаем часовой пояс по умолчанию */
date_default_timezone_set($config['app']['timezone']);

/** Устанавливаем обработчик ошибок */
error_reporting(E_ALL);

if ($config['app']['env'] === 'development' || $config['app']['env'] === 'local') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

/** Возвращаем конфигурацию для использования в других частях приложения */
return $config; 