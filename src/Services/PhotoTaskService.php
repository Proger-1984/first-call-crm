<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Listing;
use App\Models\PhotoTask;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Сервис для работы с задачами обработки фото
 */
class PhotoTaskService
{
    private const UNMARK_API_URL = 'https://unmark.ru/api';
    private const API_VERSION = '2.0';
    
    // Директория для хранения обработанных фото
    private const STORAGE_DIR = 'storage/photo-tasks';

    private Client $httpClient;
    private string $apiKey;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->apiKey = $_ENV['UNMARK_API_KEY'] ?? '';
        $this->httpClient = new Client([
            'timeout' => 30.0,
            'connect_timeout' => 15.0,
            'verify' => false,
        ]);
    }

    /**
     * Создать или обновить задачу на обработку фото
     * 
     * Логика:
     * - Если задача pending/processing — возвращаем её (ничего не делаем)
     * - Если задача completed — возвращаем её (можно скачать)
     * - Если задача failed — сбрасываем на pending (повторная попытка)
     * - Если задачи нет — создаём новую
     */
    public function createTask(int $listingId): array
    {
        // Проверяем существующую задачу
        $existingTask = PhotoTask::where('listing_id', $listingId)->first();

        if ($existingTask) {
            // Если в процессе — просто возвращаем
            if (in_array($existingTask->status, [PhotoTask::STATUS_PENDING, PhotoTask::STATUS_PROCESSING])) {
                return [
                    'success' => false,
                    'error' => 'Задача уже в обработке',
                    'task' => $this->formatTask($existingTask),
                ];
            }
            
            // Если завершена успешно — возвращаем (пользователь может скачать)
            if ($existingTask->status === PhotoTask::STATUS_COMPLETED) {
                return [
                    'success' => false,
                    'error' => 'Задача уже выполнена',
                    'task' => $this->formatTask($existingTask),
                ];
            }
            
            // Если failed — сбрасываем на pending для повторной обработки
            if ($existingTask->status === PhotoTask::STATUS_FAILED) {
                $existingTask->status = PhotoTask::STATUS_PENDING;
                $existingTask->error_message = null;
                $existingTask->save();
                
                $this->logger->info('Задача сброшена на повторную обработку', [
                    'task_id' => $existingTask->id,
                    'listing_id' => $listingId,
                ]);
                
                return [
                    'success' => true,
                    'task' => $this->formatTask($existingTask),
                ];
            }
        }

        // Получаем объявление
        $listing = Listing::with('source')->find($listingId);
        if (!$listing) {
            return [
                'success' => false,
                'error' => 'Объявление не найдено',
            ];
        }

        // Создаём новую задачу
        $task = PhotoTask::create([
            'listing_id' => $listingId,
            'source_id' => $listing->source_id,
            'external_id' => $listing->external_id,
            'url' => $listing->url,
            'status' => PhotoTask::STATUS_PENDING,
        ]);

        $this->logger->info('Создана задача на обработку фото', [
            'task_id' => $task->id,
            'listing_id' => $listingId,
            'url' => $listing->url,
        ]);

        return [
            'success' => true,
            'task' => $this->formatTask($task),
        ];
    }

    /**
     * Получить путь к архиву для скачивания
     */
    public function getArchivePath(int $taskId): ?string
    {
        $task = PhotoTask::where('id', $taskId)
            ->where('status', PhotoTask::STATUS_COMPLETED)
            ->first();

        if (!$task || !$task->archive_path) {
            return null;
        }

        $fullPath = $this->getStoragePath() . '/' . $task->archive_path;
        
        if (!file_exists($fullPath)) {
            return null;
        }

        return $fullPath;
    }

    /**
     * Обработать задачу (вызывается из консольной команды)
     */
    public function processTask(PhotoTask $task): bool
    {
        if (empty($this->apiKey)) {
            $this->logger->error('UNMARK_API_KEY не настроен');
            $task->updateStatus(PhotoTask::STATUS_FAILED, 'API ключ не настроен');
            return false;
        }

        $task->updateStatus(PhotoTask::STATUS_PROCESSING);

        try {
            // 1. Получаем список фото объявления
            $advertData = $this->getAdvertImages($task->url);
            
            if (!$advertData || empty($advertData['images'])) {
                $task->updateStatus(PhotoTask::STATUS_FAILED, 'Фотографии не найдены');
                return false;
            }

            $images = $advertData['images'];
            $this->logger->info('Получен список фото', [
                'task_id' => $task->id,
                'count' => count($images),
            ]);

            // 2. Создаём директорию для задачи
            $taskDir = $this->getStoragePath() . '/' . $task->id;
            if (!is_dir($taskDir)) {
                mkdir($taskDir, 0755, true);
            }

            // 3. Обрабатываем каждое фото
            $processedCount = 0;
            $tempFiles = [];

            foreach ($images as $index => $image) {
                try {
                    $imageUrl = $this->processImage($image['id'], $image['hash']);
                    
                    if ($imageUrl) {
                        $filename = $taskDir . '/' . ($index + 1) . '.jpg';
                        $this->downloadFile($imageUrl, $filename);
                        $tempFiles[] = $filename;
                        $processedCount++;
                        
                        $this->logger->debug('Фото обработано', [
                            'task_id' => $task->id,
                            'index' => $index + 1,
                        ]);
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('Ошибка обработки фото', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                // Небольшая пауза между запросами
                usleep(500000); // 0.5 сек
            }

            if ($processedCount === 0) {
                $task->updateStatus(PhotoTask::STATUS_FAILED, 'Не удалось обработать ни одного фото');
                return false;
            }

            // 4. Создаём архив
            $archiveName = $task->id . '_' . $task->external_id . '.zip';
            $archivePath = $this->getStoragePath() . '/' . $archiveName;
            
            if (!$this->createArchive($tempFiles, $archivePath)) {
                $task->updateStatus(PhotoTask::STATUS_FAILED, 'Ошибка создания архива');
                return false;
            }

            // 5. Удаляем временные файлы
            foreach ($tempFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            if (is_dir($taskDir)) {
                rmdir($taskDir);
            }

            // 6. Обновляем задачу
            $task->status = PhotoTask::STATUS_COMPLETED;
            $task->photos_count = $processedCount;
            $task->archive_path = $archiveName;
            $task->save();

            $this->logger->info('Задача успешно выполнена', [
                'task_id' => $task->id,
                'photos_count' => $processedCount,
            ]);

            return true;

        } catch (Throwable $e) {
            $this->logger->error('Ошибка обработки задачи', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
            $task->updateStatus(PhotoTask::STATUS_FAILED, $e->getMessage());
            return false;
        }
    }

    /**
     * Получить список фото объявления через UNMARK API
     */
    private function getAdvertImages(string $url): ?array
    {
        try {
            $response = $this->httpClient->post(self::UNMARK_API_URL . '/public.advert.get', [
                'headers' => $this->getHeaders(),
                'form_params' => ['url' => $url],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                $this->logger->error('UNMARK API error', ['error' => $data['error']]);
                return null;
            }

            return $data['response'] ?? null;

        } catch (GuzzleException $e) {
            $this->logger->error('Ошибка запроса к UNMARK API', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Обработать одно фото через UNMARK API
     */
    private function processImage(int $imageId, string $hash): ?string
    {
        try {
            $response = $this->httpClient->post(self::UNMARK_API_URL . '/public.task.add', [
                'headers' => $this->getHeaders(),
                'form_params' => [
                    'image_id' => $imageId,
                    'hash' => $hash,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                $this->logger->warning('UNMARK API image error', ['error' => $data['error']]);
                return null;
            }

            return $data['response']['url'] ?? null;

        } catch (GuzzleException $e) {
            $this->logger->warning('Ошибка обработки фото', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Скачать файл
     * @throws GuzzleException
     */
    private function downloadFile(string $url, string $destination): void
    {
        $this->httpClient->get($url, ['sink' => $destination]);
    }

    /**
     * Создать ZIP архив
     */
    private function createArchive(array $files, string $archivePath): bool
    {
        $zip = new ZipArchive();
        
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        foreach ($files as $file) {
            if (file_exists($file)) {
                $zip->addFile($file, basename($file));
            }
        }

        return $zip->close();
    }

    /**
     * Получить заголовки для UNMARK API
     */
    #[ArrayShape(['X-Token' => "mixed|string", 'X-Version' => "string", 'Content-Type' => "string"])]
    private function getHeaders(): array
    {
        return [
            'X-Token' => $this->apiKey,
            'X-Version' => self::API_VERSION,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }

    /**
     * Получить путь к директории хранения
     */
    private function getStoragePath(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::STORAGE_DIR;
        
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        return $path;
    }

    /**
     * Форматировать задачу для API ответа
     */
    #[ArrayShape(['id' => "int", 'listing_id' => "int", 'status' => "string", 'photos_count' => "int", 'error_message' => "null|string", 'created_at' => "string", 'updated_at' => "string"])]
    private function formatTask(PhotoTask $task): array
    {
        return [
            'id' => $task->id,
            'listing_id' => $task->listing_id,
            'status' => $task->status,
            'photos_count' => $task->photos_count,
            'error_message' => $task->error_message,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }
}
