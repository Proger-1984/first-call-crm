<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\InteractionService;
use App\Traits\ResponseTrait;
use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер таймлайна взаимодействий CRM
 */
class InteractionController
{
    use ResponseTrait;

    public function __construct(
        private InteractionService $interactionService
    ) {}

    /**
     * Таймлайн по объекту (все связки)
     *
     * GET /api/v1/properties/{id}/interactions
     */
    public function getByProperty(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $propertyId = (int)($request->getAttribute('id') ?? 0);
            if ($propertyId <= 0) {
                return $this->respondWithError($response, 'Не указан ID объекта', 'validation_error', 400);
            }

            $params = $request->getQueryParams();
            $limit = min(100, max(1, (int)($params['limit'] ?? 50)));
            $offset = max(0, (int)($params['offset'] ?? 0));

            $result = $this->interactionService->getByProperty($propertyId, $userId, $limit, $offset);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения таймлайна', 'internal_error', 500);
        }
    }

    /**
     * Таймлайн по контакту
     *
     * GET /api/v1/contacts/{id}/interactions
     */
    public function getByContact(Request $request, Response $response): Response
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

            $params = $request->getQueryParams();
            $limit = min(100, max(1, (int)($params['limit'] ?? 50)));
            $offset = max(0, (int)($params['offset'] ?? 0));

            $result = $this->interactionService->getByContact($contactId, $userId, $limit, $offset);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения таймлайна', 'internal_error', 500);
        }
    }

    /**
     * Таймлайн конкретной связки
     *
     * GET /api/v1/properties/{id}/contacts/{contact_id}/interactions
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

            $params = $request->getQueryParams();
            $limit = min(100, max(1, (int)($params['limit'] ?? 50)));
            $offset = max(0, (int)($params['offset'] ?? 0));

            $result = $this->interactionService->getByObjectClient($propertyId, $contactId, $userId, $limit, $offset);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения таймлайна', 'internal_error', 500);
        }
    }

    /**
     * Создать взаимодействие
     *
     * POST /api/v1/properties/{id}/contacts/{contact_id}/interactions
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

            if (empty($body['type'])) {
                return $this->respondWithError($response, 'Не указан тип взаимодействия', 'validation_error', 400);
            }

            $interaction = $this->interactionService->create($propertyId, $contactId, $userId, $body);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'interaction' => $this->interactionService->formatInteraction($interaction),
                    'message' => 'Взаимодействие создано',
                ],
            ], 201);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка создания взаимодействия', 'internal_error', 500);
        }
    }
}
