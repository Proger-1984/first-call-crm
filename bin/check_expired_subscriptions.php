<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Commands\CheckExpiredSubscriptionsCommand;

// Запускаем команду проверки истекших подписок
$command = new CheckExpiredSubscriptionsCommand();
$result = $command->execute();

// Выводим результат
echo $result['message'] . PHP_EOL;

// Код возврата для скриптов автоматизации
exit($result['success'] ? 0 : 1); 