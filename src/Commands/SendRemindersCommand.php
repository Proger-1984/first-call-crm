<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\LogService;
use App\Services\ReminderService;
use App\Services\TelegramService;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron-команда: отправка Telegram-уведомлений по наступившим напоминаниям
 *
 * Запускается каждые 5 минут через supervisor.
 * Использует атомарную блокировку (sent_at IS NULL) для предотвращения дубликатов.
 */
class SendRemindersCommand extends Command
{
    private LogService $logger;
    private TelegramService $telegramService;
    private ReminderService $reminderService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);
        $this->telegramService = $container->get(TelegramService::class);
        $this->reminderService = $container->get(ReminderService::class);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('crm:send-reminders')
            ->setDescription('Отправляет Telegram-уведомления по наступившим CRM-напоминаниям');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $supervisorCmd = '/usr/bin/supervisorctl stop crm-send-reminders';
        $this->logger->info('Запуск отправки CRM-напоминаний', [
            'date' => date('Y-m-d H:i:s'),
        ], 'crm-reminders');

        try {
            $pendingReminders = $this->reminderService->getPendingReminders();
            $total = $pendingReminders->count();

            $this->logger->info("Найдено напоминаний к отправке: {$total}", [], 'crm-reminders');

            if ($total === 0) {
                sleep(3);
                exec($supervisorCmd);
                return Command::SUCCESS;
            }

            $sentCount = 0;
            $errorCount = 0;

            foreach ($pendingReminders as $reminder) {
                try {
                    // Атомарная блокировка — предотвращает дубли
                    $locked = $this->reminderService->markAsSent($reminder->id);
                    if (!$locked) {
                        $this->logger->info('Напоминание уже обрабатывается другим процессом', [
                            'reminder_id' => $reminder->id,
                        ], 'crm-reminders');
                        continue;
                    }

                    $user = $reminder->user;
                    if (!$user || empty($user->telegram_id)) {
                        $this->logger->warning('Пользователь не найден или нет Telegram ID', [
                            'reminder_id' => $reminder->id,
                            'user_id' => $reminder->user_id,
                        ], 'crm-reminders');
                        continue;
                    }

                    // Пропускаем пользователей, заблокировавших бота
                    if ($user->telegram_bot_blocked) {
                        $this->logger->info('Бот заблокирован пользователем, пропускаем', [
                            'user_id' => $user->id,
                        ], 'crm-reminders');
                        continue;
                    }

                    // Отправляем уведомление через TelegramService
                    $success = $this->telegramService->notifyCrmReminder($user, $reminder);

                    if ($success) {
                        $sentCount++;
                        $this->logger->info('Напоминание отправлено', [
                            'reminder_id' => $reminder->id,
                            'user_id' => $user->id,
                        ], 'crm-reminders');
                    } else {
                        $errorCount++;
                        $this->logger->warning('Не удалось отправить напоминание', [
                            'reminder_id' => $reminder->id,
                            'user_id' => $user->id,
                        ], 'crm-reminders');
                    }

                } catch (Exception $exception) {
                    $errorCount++;
                    $this->logger->error('Ошибка при обработке напоминания', [
                        'reminder_id' => $reminder->id,
                        'error' => $exception->getMessage(),
                    ], 'crm-reminders');
                }
            }

            $this->logger->info("Отправка завершена", [
                'total' => $total,
                'sent' => $sentCount,
                'errors' => $errorCount,
            ], 'crm-reminders');

            sleep(3);
            exec($supervisorCmd);
            return Command::SUCCESS;

        } catch (Exception $exception) {
            $this->logger->error('Критическая ошибка отправки напоминаний', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ], 'crm-reminders');

            sleep(3);
            exec($supervisorCmd);
            return Command::SUCCESS;
        }
    }

}
