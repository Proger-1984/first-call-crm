<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\JsonBodyParserMiddleware;
use Slim\App;

return function (App $app, array $config) {
    /** Добавляем глобальные middleware */
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    $app->add(new CorsMiddleware());
    $app->add(new JsonBodyParserMiddleware());

    /** Добавляем обработчик ошибок */
    $app->addErrorMiddleware(
        $config['error_handler']['display_error_details'],
        $config['error_handler']['log_errors'],
        $config['error_handler']['log_error_details']
    );

    return $app;
}; 