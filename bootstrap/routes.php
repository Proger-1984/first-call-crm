<?php

declare(strict_types=1);

use Slim\App;

return function (App $app) {
    /** Подключаем единый файл маршрутов из директории routes */
    $routesFunction = require __DIR__ . '/../routes/routes.php';
    return $routesFunction($app);
}; 