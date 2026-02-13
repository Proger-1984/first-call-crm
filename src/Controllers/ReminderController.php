<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ReminderService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * Контроллер напоминаний CRM
 */
class ReminderController
{
    use ResponseTrait;

    public function __construct(
        private ReminderService $reminderService
    ) {}

    /**
     * Все напоминания текущего пользователя
     *
     * GET /api/v1/reminders
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $reminders = $this->reminderService->getByUser($userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'reminders' => $reminders->map(
                        fn($reminder) => $this->reminderService->formatReminder($reminder)
                    )->toArray(),
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения напоминаний', 'internal_error', 500);
        }
    }

    /**
     * Напоминания по связке объект+контакт
     *
     * GET /api/v1/properties/{id}/contacts/{contact_id}/reminders
     */
    public function getByObjectClient(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $propertyId = (int)($request->getAttribute('id') ?? 0);
            $contactId = (int)($request->getAttribute('contact_id') ?? 0);

            if ($propertyId <= 0 || $contactId <= 0) {
                return $this->respondWithError($response, 'Не указаны ID объекта или контакта', 'validation_error', 400);
            }

            $reminders = $this->reminderService->getByObjectClient($propertyId, $contactId, $userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'reminders' => $reminders->map(
                        fn($reminder) => $this->reminderService->formatReminder($reminder)
                    )->toArray(),
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения напоминаний', 'internal_error', 500);
        }
    }

    /**
     * Создать напоминание
     *
     * POST /api/v1/properties/{id}/contacts/{contact_id}/reminders
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $propertyId = (int)($request->getAttribute('id') ?? 0);
            $contactId = (int)($request->getAttribute('contact_id') ?? 0);

            if ($propertyId <= 0 || $contactId <= 0) {
                return $this->respondWithError($response, 'Не указаны ID объекта или контакта', 'validation_error', 400);
            }

            $body = (array)($request->getParsedBody() ?? []);

            if (empty($body['remind_at'])) {
                return $this->respondWithError($response, 'Не указана дата напоминания', 'validation_error', 400);
            }

            if (empty($body['message'])) {
                return $this->respondWithError($response, 'Не указан текст напоминания', 'validation_error', 400);
            }

            $reminder = $this->reminderService->create($propertyId, $contactId, $userId, $body);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'reminder' => $reminder,
                    'message' => 'Напоминание создано',
                ],
            ], 201);

        } catch (RuntimeException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка создания напоминания', 'internal_error', 500);
        }
    }

    /**
     * Удалить напоминание
     *
     * DELETE /api/v1/reminders/{id}
     */
    public function delete(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $reminderId = (int)($request->getAttribute('id') ?? 0);
            if ($reminderId <= 0) {
                return $this->respondWithError($response, 'Не указан ID напоминания', 'validation_error', 400);
            }

            $this->reminderService->delete($reminderId, $userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Напоминание удалено',
                ],
            ], 200);

        } catch (RuntimeException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'not_found', 404);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления напоминания', 'internal_error', 500);
        }
    }
}
