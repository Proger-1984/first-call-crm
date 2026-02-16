<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Сервис логирования с записью в файл и проксированием в PSR-3 логгер
 *
 * Реализует LoggerInterface — можно использовать везде,
 * где ожидается стандартный PSR-3 логгер.
 * Дополнительный параметр $filename позволяет писать в отдельный файл.
 */
class LogService implements LoggerInterface
{
    private LoggerInterface $logger;
    private string $logPath;

    /**
     * @param LoggerInterface $logger PSR-3 логгер (Monolog)
     * @param string|null $logPath Путь к директории логов
     */
    public function __construct(LoggerInterface $logger, ?string $logPath = null)
    {
        $this->logger = $logger;
        $this->logPath = $logPath ?? dirname(__DIR__, 2) . '/logs';

        // Создаем директорию для логов, если она не существует
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }
    }

    /**
     * Критическая ошибка, система неработоспособна
     */
    public function emergency(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('emergency', $message, $context, $filename);
    }

    /**
     * Требуется немедленное действие
     */
    public function alert(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('alert', $message, $context, $filename);
    }

    /**
     * Критические условия
     */
    public function critical(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('critical', $message, $context, $filename);
    }

    /**
     * Ошибка, не требующая немедленного действия
     */
    public function error(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('error', $message, $context, $filename);
    }

    /**
     * Предупреждение о нештатной ситуации
     */
    public function warning(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('warning', $message, $context, $filename);
    }

    /**
     * Нормальное, но значимое событие
     */
    public function notice(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('notice', $message, $context, $filename);
    }

    /**
     * Информационное сообщение
     */
    public function info(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('info', $message, $context, $filename);
    }

    /**
     * Отладочная информация
     */
    public function debug(string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        $this->writeLog('debug', $message, $context, $filename);
    }

    /**
     * Логирование с произвольным уровнем (PSR-3)
     *
     * @param mixed $level Уровень логирования
     * @param string|Stringable $message Сообщение
     * @param array $context Контекст
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->writeLog((string) $level, $message, $context);
    }

    /**
     * Основной метод записи лога в файл и PSR-3 логгер
     *
     * @param string $level Уровень логирования
     * @param string|Stringable $message Сообщение
     * @param array $context Контекст
     * @param string|null $filename Имя файла лога (без расширения)
     */
    private function writeLog(string $level, string|Stringable $message, array $context = [], ?string $filename = null): void
    {
        // Форматируем сообщение
        $logMessage = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            (string) $message
        );

        // Добавляем контекст, если он есть
        if (!empty($context)) {
            $logMessage .= "Context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }

        // Определяем имя файла для лога
        $logFile = $filename
            ? $this->logPath . '/' . $filename . '.log'
            : $this->logPath . '/' . date('Y-m-d') . '.log';

        // Записываем в файл
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        // Также логируем через PSR Logger
        $this->logger->log($level, (string) $message, $context);
    }
}
