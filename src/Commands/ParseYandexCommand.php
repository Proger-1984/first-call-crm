<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\LogService;
use App\Services\YandexParserService;
use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Консольная команда парсинга Яндекс.Недвижимости
 *
 * Запускает многопоточный парсер: по одному дочернему процессу
 * на каждую пару location+category из конфига.
 * Родительский процесс мониторит детей и перезапускает при падении.
 *
 * Критические особенности:
 * - Переинициализация Eloquent в каждом дочернем процессе (после pcntl_fork)
 * - Graceful stop через SIGTERM → shouldStop → передача по ссылке в runLoop
 */
class ParseYandexCommand extends Command
{
    private const COMMAND_NAME = 'parse-yandex';

    private LogService $logger;
    private array $config;

    /** @var array Конфигурация подключения к БД для передачи дочерним процессам */
    private array $dbConfig;

    /** @var array<int, array{pid: int, location_id: int, category_id: int}> Дочерние процессы */
    private array $children = [];

    /** @var bool Флаг остановки */
    private bool $shouldStop = false;

    /**
     * @param ContainerInterface $container DI контейнер
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);
        $appConfig = $container->get('config');
        $this->config = $appConfig['yandex'] ?? [];
        $this->dbConfig = $appConfig['database'] ?? [];

        parent::__construct();
    }

    /**
     * Настройка команды
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Многопоточный парсер Яндекс.Недвижимости');
    }

    /**
     * Выполнение команды
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int Код завершения
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        date_default_timezone_set('Europe/Moscow');

        $this->logger->info('Запуск парсера Яндекс.Недвижимости', [], self::COMMAND_NAME);

        // Проверяем наличие токена авторизации
        if (empty($this->config['auth_token'])) {
            $this->logger->error(
                'Не задан YANDEX_REALTY_AUTH_TOKEN в .env',
                [],
                self::COMMAND_NAME
            );
            return Command::FAILURE;
        }

        // Проверяем наличие расширения pcntl
        if (!function_exists('pcntl_fork')) {
            $this->logger->error(
                'Расширение pcntl не установлено — многопоточность невозможна',
                [],
                self::COMMAND_NAME
            );
            return Command::FAILURE;
        }

        // Устанавливаем обработчики сигналов
        $this->setupSignalHandlers();

        // Собираем все пары location+category из конфига
        $workers = $this->collectWorkers();

        if (empty($workers)) {
            $this->logger->warning(
                'Нет пар location+category для парсинга',
                [],
                self::COMMAND_NAME
            );
            return Command::SUCCESS;
        }

        $this->logger->info(
            'Количество воркеров для запуска: ' . count($workers),
            [],
            self::COMMAND_NAME
        );

        // Запускаем дочерние процессы
        foreach ($workers as $worker) {
            $this->forkWorker($worker);
        }

        // Родительский процесс: мониторинг дочерних процессов
        $this->monitorChildren($workers);

        $this->logger->info('Парсер Яндекс.Недвижимости остановлен', [], self::COMMAND_NAME);

        return Command::SUCCESS;
    }

    /**
     * Собирает все пары location+category из конфигурации
     *
     * @return array<int, array{location_id: int, location_config: array, category_id: int, category_config: array}>
     */
    private function collectWorkers(): array
    {
        $workers = [];

        foreach ($this->config['locations'] as $locationId => $locationConfig) {
            foreach ($locationConfig['categories'] as $categoryId => $categoryConfig) {
                $workers[] = [
                    'location_id' => $locationId,
                    'location_config' => $locationConfig,
                    'category_id' => $categoryId,
                    'category_config' => $categoryConfig,
                ];
            }
        }

        return $workers;
    }

    /**
     * Форкает дочерний процесс для воркера
     *
     * @param array $worker Данные воркера
     */
    private function forkWorker(array $worker): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->logger->error('Не удалось создать дочерний процесс', [
                'location_id' => $worker['location_id'],
                'category_id' => $worker['category_id'],
            ], self::COMMAND_NAME);
            return;
        }

        if ($pid === 0) {
            // Дочерний процесс
            $this->runChildProcess($worker);
            exit(0);
        }

        // Родительский процесс — запоминаем PID
        $this->children[$pid] = [
            'pid' => $pid,
            'location_id' => $worker['location_id'],
            'category_id' => $worker['category_id'],
        ];

        $this->logger->info("Запущен дочерний процесс PID=$pid", [
            'location_id' => $worker['location_id'],
            'category_id' => $worker['category_id'],
            'location_name' => $worker['location_config']['name'],
            'type' => $worker['category_config']['api_params']['type'] ?? 'unknown',
        ], self::COMMAND_NAME);
    }

    /**
     * Запускает парсер в дочернем процессе
     *
     * КРИТИЧНО: после pcntl_fork() дочерний процесс наследует соединение с БД
     * от родителя. Это соединение НЕЛЬЗЯ использовать — необходимо создать новое.
     *
     * @param array $worker Данные воркера
     */
    private function runChildProcess(array $worker): void
    {
        try {
            // КРИТИЧНО: переинициализация БД в дочернем процессе
            // После fork() дочерний процесс наследует file descriptor сокета родителя.
            // Два процесса НЕ могут безопасно использовать один и тот же сокет.
            $capsule = new Capsule();
            $capsule->addConnection($this->dbConfig);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            // Устанавливаем обработчик SIGTERM для дочернего процесса
            pcntl_signal(SIGTERM, function () {
                $this->shouldStop = true;
            });

            // Создаём отдельный логгер для дочернего процесса (stdout для supervisor)
            $childLogger = new Logger('yandex_worker');
            $childLogger->pushHandler(new StreamHandler('php://stdout'));

            // Создаём сервис парсера
            $parserService = new YandexParserService($childLogger, $this->config);

            // Запускаем daemon-цикл с передачей ссылки на shouldStop
            $parserService->runLoop(
                $worker['location_id'],
                $worker['location_config'],
                $worker['category_id'],
                $worker['category_config'],
                $this->shouldStop
            );
        } catch (Exception $exception) {
            $this->logger->error('Дочерний процесс завершился с ошибкой', [
                'location_id' => $worker['location_id'],
                'category_id' => $worker['category_id'],
                'error' => $exception->getMessage(),
            ], self::COMMAND_NAME);
        }
    }

    /**
     * Мониторинг дочерних процессов с автоматическим перезапуском
     *
     * @param array $workers Все конфигурации воркеров
     */
    private function monitorChildren(array $workers): void
    {
        while (!$this->shouldStop && !empty($this->children)) {
            // Обрабатываем сигналы
            pcntl_signal_dispatch();

            // Проверяем завершившиеся дочерние процессы
            $exitedPid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($exitedPid > 0) {
                $childInfo = $this->children[$exitedPid] ?? null;

                if ($childInfo !== null) {
                    // Определяем причину завершения для корректного логирования
                    if (pcntl_wifexited($status)) {
                        $exitCode = pcntl_wexitstatus($status);
                        $this->logger->warning("Дочерний процесс PID=$exitedPid завершился", [
                            'exit_code' => $exitCode,
                            'location_id' => $childInfo['location_id'],
                            'category_id' => $childInfo['category_id'],
                        ], self::COMMAND_NAME);
                    } elseif (pcntl_wifsignaled($status)) {
                        $signal = pcntl_wtermsig($status);
                        $this->logger->error("Дочерний процесс PID=$exitedPid убит сигналом", [
                            'signal' => $signal,
                            'location_id' => $childInfo['location_id'],
                            'category_id' => $childInfo['category_id'],
                        ], self::COMMAND_NAME);
                    } else {
                        $this->logger->warning("Дочерний процесс PID=$exitedPid завершился (неизвестная причина)", [
                            'raw_status' => $status,
                            'location_id' => $childInfo['location_id'],
                            'category_id' => $childInfo['category_id'],
                        ], self::COMMAND_NAME);
                    }

                    unset($this->children[$exitedPid]);

                    // Перезапускаем упавший воркер
                    // shouldStop может измениться через pcntl_signal_dispatch() выше
                    /** @noinspection PhpConditionAlreadyCheckedInspection */
                    if (!$this->shouldStop) {
                        $this->logger->info('Перезапуск воркера...', [
                            'location_id' => $childInfo['location_id'],
                            'category_id' => $childInfo['category_id'],
                        ], self::COMMAND_NAME);

                        // Ищем конфигурацию воркера
                        foreach ($workers as $worker) {
                            if (
                                $worker['location_id'] === $childInfo['location_id']
                                && $worker['category_id'] === $childInfo['category_id']
                            ) {
                                sleep(5); // Задержка перед перезапуском
                                $this->forkWorker($worker);
                                break;
                            }
                        }
                    }
                }
            }

            // Небольшая задержка чтобы не грузить CPU
            usleep(500000); // 0.5 сек
        }

        // Если нужно остановиться — убиваем детей
        if ($this->shouldStop) {
            $this->terminateChildren();
        }
    }

    /**
     * Установка обработчиков системных сигналов
     */
    private function setupSignalHandlers(): void
    {
        pcntl_signal(SIGTERM, function () {
            $this->logger->info('Получен SIGTERM, останавливаемся...', [], self::COMMAND_NAME);
            $this->shouldStop = true;
        });

        pcntl_signal(SIGINT, function () {
            $this->logger->info('Получен SIGINT, останавливаемся...', [], self::COMMAND_NAME);
            $this->shouldStop = true;
        });

        pcntl_signal(SIGCHLD, function () {
            // Обрабатывается в monitorChildren через pcntl_waitpid
        });
    }

    /**
     * Завершение всех дочерних процессов
     */
    private function terminateChildren(): void
    {
        foreach ($this->children as $pid => $childInfo) {
            $this->logger->info("Завершение дочернего процесса PID=$pid", [], self::COMMAND_NAME);
            posix_kill($pid, SIGTERM);
        }

        // Ждём завершения всех дочерних процессов (максимум 10 сек)
        $waitTimeout = 10;
        $startTime = time();

        while (!empty($this->children) && (time() - $startTime) < $waitTimeout) {
            $exitedPid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($exitedPid > 0) {
                unset($this->children[$exitedPid]);
            }
            usleep(100000); // 0.1 сек
        }

        // Принудительное завершение оставшихся
        foreach ($this->children as $pid => $childInfo) {
            $this->logger->warning("Принудительное завершение PID=$pid (SIGKILL)", [], self::COMMAND_NAME);
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            unset($this->children[$pid]);
        }
    }
}
