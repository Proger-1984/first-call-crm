<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge;

require __DIR__ . '/../vendor/autoload.php';

/** Инициализируем приложение и получаем конфигурацию */
$config = require __DIR__ . '/../bootstrap/app.php';

/** Получаем экземпляр контейнера */
$container = (require __DIR__ . '/../bootstrap/container.php')($config);

/** Создаем приложение с использованием контейнера */
$app = Bridge::create($container);

/** Регистрируем middleware */
(require __DIR__ . '/../bootstrap/middleware.php')($app, $config);

/** Регистрируем routes */
(require __DIR__ . '/../bootstrap/routes.php')($app);

/** Запускаем приложение */
$app->run(); 