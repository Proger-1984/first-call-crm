<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PhotoTaskService;
use App\Traits\ResponseTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use Throwable;

/**
 * Контроллер для работы с задачами обработки фото
 */
class PhotoTaskController
{
    use ResponseTrait;

    public function __construct(
        private readonly PhotoTaskService $photoTaskService
    ) {}

    /**
     * Создать задачу на обработку фото
     * POST /api/v1/photo-tasks
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = $request->getParsedBody();
            $listingId = (int) ($body['listing_id'] ?? 0);

            if ($listingId <= 0) {
                return $this->respondWithError($response, 'Не указан listing_id', 'validation_error', 400);
            }

            $result = $this->photoTaskService->createTask($listingId);

            if (!$result['success']) {
                // Если задача уже существует — возвращаем её статус
                if (isset($result['task'])) {
                    return $this->respondWithData($response, [
                        'code' => 200,
                        'status' => 'success',
                        'data' => $result['task'],
                    ], 200);
                }
                return $this->respondWithError($response, $result['error'], 'validation_error', 400);
            }

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => $result['task'],
            ], 201);

        } catch (Throwable $e) {
            return $this->respondWithError($response, 'Ошибка создания задачи: ' . $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Скачать архив с обработанными фото
     * GET /api/v1/photo-tasks/{id}/download?token=...
     */
    public function download(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $taskId = (int) $request->getAttribute('id', 0);

            if ($taskId <= 0) {
                return $this->respondWithError($response, 'Неверный id задачи', 'validation_error', 400);
            }

            $archivePath = $this->photoTaskService->getArchivePath($taskId);

            if (!$archivePath) {
                return $this->respondWithError($response, 'Архив не найден или задача не завершена', 'not_found', 404);
            }

            // Отдаём файл
            $filename = basename($archivePath);
            $stream = fopen($archivePath, 'rb');

            if ($stream === false) {
                return $this->respondWithError($response, 'Не удалось открыть файл архива', 'internal_error', 500);
            }

            return $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) filesize($archivePath))
                ->withBody(new Stream($stream));

        } catch (Throwable $e) {
            return $this->respondWithError($response, 'Ошибка скачивания: ' . $e->getMessage(), 'internal_error', 500);
        }
    }
}
