<?php

/** Проверяем путь к файлам */

use Illuminate\Database\Capsule\Manager;

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
$appPath = __DIR__ . '/../../bootstrap/app.php';

if (!file_exists($autoloadPath)) {
    echo "ОШИБКА: Файл автозагрузки не найден по пути: {$autoloadPath}\n";
    exit(1);
}

if (!file_exists($appPath)) {
    echo "ОШИБКА: Файл приложения не найден по пути: {$appPath}\n";
    exit(1);
}

/** Подключаем файлы */
require $autoloadPath;
$config = require $appPath;

/** Получаем список миграций */
$migrations = array_filter(scandir(__DIR__), function ($file) {
    return pathinfo($file, PATHINFO_EXTENSION) === 'php' && $file !== 'run.php';
});

/** Сортируем миграции по имени (важно для последовательности выполнения) */
sort($migrations);

echo "Найдено " . count($migrations) . " файлов миграций.\n";

/** Выполняем миграции */
foreach ($migrations as $migration) {
    $migrationFile = __DIR__ . '/' . $migration;
    
    if (!file_exists($migrationFile)) {
        echo "ОШИБКА: Файл миграции не найден: {$migrationFile}\n";
        continue;
    }
    
    require_once $migrationFile;
    
    /** Получаем имя файла без расширения */
    $baseName = pathinfo($migration, PATHINFO_FILENAME);

    /** Удаляем префикс даты (например, 20230101000000_) если он существует */
    if (preg_match('/^\d+_(.+)$/', $baseName, $matches)) {
        $baseName = $matches[1];
    }
    
    /** Преобразуем snake_case в CamelCase */
    $className = str_replace('_', ' ', $baseName);
    $className = str_replace(' ', '', ucwords($className));
    
    echo "Выполняется миграция: {$className} из файла {$migration}... ";
    
    /** Проверяем, существует ли класс */
    if (!class_exists($className)) {
        echo "ОШИБКА: Класс {$className} не найден в файле {$migration}!\n";
        echo "Доступные классы: " . implode(", ", get_declared_classes()) . "\n";
        continue;
    }
    
    try {
        $instance = new $className();
        
        /** Проверяем существование таблицы перед выполнением миграции */
        $tableName = $instance->getTableName();

        /** Если класс реализует метод modifiesExistingTable и он возвращает true,
         * то мы не пропускаем миграцию, даже если таблица существует
         */
        $shouldSkip = Manager::schema()->hasTable($tableName) &&
                    (!method_exists($instance, 'modifiesExistingTable') || !$instance->modifiesExistingTable());
        
        if ($shouldSkip) {
            echo "ПРОПУЩЕНО: Таблица '{$tableName}' уже существует.\n";
            continue;
        }
        
        $instance->up();
        echo "Готово\n";
    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }
}

echo "Миграции выполнены!\n";