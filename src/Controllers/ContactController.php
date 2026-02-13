<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Contact;
use App\Services\ContactService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для управления справочником контактов CRM
 */
class ContactController
{
    use ResponseTrait;

    public function __construct(
        private ContactService $contactService
    ) {}

    /**
     * Получить список контактов
     *
     * GET /api/v1/contacts
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $params = $request->getQueryParams();
            $result = $this->contactService->getContacts($userId, $params);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения списка контактов', 'internal_error', 500);
        }
    }

    /**
     * Получить карточку контакта
     *
     * GET /api/v1/contacts/{id}
     */
    public function show(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $contactId = (int)($request->getAttribute('id') ?? 0);
            if ($contactId <= 0) {
                return $this->respondWithError($response, 'Не указан ID контакта', 'validation_error', 400);
            }

            $contact = $this->contactService->getContact($contactId, $userId);
            if (!$contact) {
                return $this->respondWithError($response, 'Контакт не найден', 'not_found', 404);
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'contact' => $this->contactService->formatContact($contact),
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения контакта', 'internal_error', 500);
        }
    }

    /**
     * Создать контакт
     *
     * POST /api/v1/contacts
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = (array)($request->getParsedBody() ?? []);

            if (empty($body['name'])) {
                return $this->respondWithError($response, 'Укажите имя контакта', 'validation_error', 400);
            }

            $contact = $this->contactService->createContact($userId, $body);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'contact' => $this->contactService->formatContact($contact),
                    'message' => 'Контакт создан',
                ],
            ], 201);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка создания контакта', 'internal_error', 500);
        }
    }

    /**
     * Обновить контакт
     *
     * PUT /api/v1/contacts/{id}
     */
    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $contactId = (int)($request->getAttribute('id') ?? 0);
            if ($contactId <= 0) {
                return $this->respondWithError($response, 'Не указан ID контакта', 'validation_error', 400);
            }

            $contact = Contact::where('id', $contactId)->where('user_id', $userId)->first();
            if (!$contact) {
                return $this->respondWithError($response, 'Контакт не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $contact = $this->contactService->updateContact($contact, $body);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'contact' => $this->contactService->formatContact($contact),
                    'message' => 'Контакт обновлён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления контакта', 'internal_error', 500);
        }
    }

    /**
     * Удалить контакт
     *
     * DELETE /api/v1/contacts/{id}
     */
    public function delete(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $contactId = (int)($request->getAttribute('id') ?? 0);
            if ($contactId <= 0) {
                return $this->respondWithError($response, 'Не указан ID контакта', 'validation_error', 400);
            }

            $contact = Contact::where('id', $contactId)->where('user_id', $userId)->first();
            if (!$contact) {
                return $this->respondWithError($response, 'Контакт не найден', 'not_found', 404);
            }

            $this->contactService->deleteContact($contact);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Контакт удалён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления контакта', 'internal_error', 500);
        }
    }

    /**
     * Поиск контактов (для модалки ContactPicker)
     *
     * GET /api/v1/contacts/search
     */
    public function search(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $params = $request->getQueryParams();
            $query = $params['q'] ?? '';

            if (mb_strlen($query) < 1) {
                return $this->respondWithData($response, [
                    'code' => 200,
                    'status' => 'success',
                    'data' => ['contacts' => []],
                ], 200);
            }

            $contacts = $this->contactService->searchContacts($userId, $query);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => ['contacts' => $contacts],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка поиска контактов', 'internal_error', 500);
        }
    }
}
