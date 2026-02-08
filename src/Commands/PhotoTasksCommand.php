<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\PhotoTask;
use App\Services\PhotoTaskService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Команда для обработки задач по удалению водяных знаков с фото
 * 
 * Работает в бесконечном цикле, проверяя новые задачи каждые 3 секунды.
 * Для запуска через Supervisor.
 * 
 * Использование:
 *   php bin/app.php photo-tasks
 */
class PhotoTasksCommand extends Command
{
    protected static $defaultName = 'photo-tasks';
    protected static $defaultDescription = 'Обработка задач по удалению водяных знаков с фото';

    private const SLEEP_INTERVAL = 3; // Интервал проверки новых задач (секунды)
    private const BATCH_LIMIT = 10;   // Максимум задач за итерацию

    private PhotoTaskService $photoTaskService;
    private LoggerInterface $logger;

    public function __construct(PhotoTaskService $photoTaskService, LoggerInterface $logger)
    {
        parent::__construct();
        $this->photoTaskService = $photoTaskService;
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>🖼️  Photo Tasks Command</info>');
        $output->writeln('<comment>Проверка новых задач каждые ' . self::SLEEP_INTERVAL . ' сек. Для остановки: Ctrl+C</comment>');
        $output->writeln('');

        $this->logger->info('Photo Tasks Command запущен');

        while (true) {
            try {
                $tasks = PhotoTask::getPendingTasks(self::BATCH_LIMIT);

                if ($tasks->isNotEmpty()) {
                    $output->writeln(sprintf('[%s] Найдено задач: %d', date('H:i:s'), $tasks->count()));

                    foreach ($tasks as $task) {
                        $output->write(sprintf('  [%d] %s... ', $task->id, $task->external_id));

                        try {
                            $result = $this->photoTaskService->processTask($task);

                            if ($result) {
                                $output->writeln(sprintf('<info>✓ %d фото</info>', $task->fresh()->photos_count));
                            } else {
                                $output->writeln('<error>✗ Ошибка</error>');
                            }
                        } catch (\Throwable $e) {
                            $output->writeln(sprintf('<error>✗ %s</error>', $e->getMessage()));
                            $this->logger->error('Ошибка обработки задачи', [
                                'task_id' => $task->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Пауза между задачами
                        sleep(1);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Ошибка в цикле обработки', ['error' => $e->getMessage()]);
                $output->writeln(sprintf('<error>Ошибка: %s</error>', $e->getMessage()));
            }

            sleep(self::SLEEP_INTERVAL);
        }
    }
}
