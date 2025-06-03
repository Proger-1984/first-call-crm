<?php

namespace App\Services;

use Psr\Log\LoggerInterface;

class LogService
{
    private LoggerInterface $logger;
    private string $logPath;

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
     * Логирование ошибки
     */
    public function error(string $message, array $context = [], ?string $filename = null): void
    {
        $this->log('error', $message, $context, $filename);
    }

    /**
     * Логирование предупреждения
     */
    public function warning(string $message, array $context = [], ?string $filename = null): void
    {
        $this->log('warning', $message, $context, $filename);
    }

    /**
     * Логирование информационного сообщения
     */
    public function info(string $message, array $context = [], ?string $filename = null): void
    {
        $this->log('info', $message, $context, $filename);
    }

    /**
     * Логирование отладочной информации
     */
    public function debug(string $message, array $context = [], ?string $filename = null): void
    {
        $this->log('debug', $message, $context, $filename);
    }

    /**
     * Основной метод логирования
     */
    private function log(string $level, string $message, array $context = [], ?string $filename = null): void
    {
        // Форматируем сообщение
        $logMessage = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message
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
        $this->logger->log($level, $message, $context);
    }
} 