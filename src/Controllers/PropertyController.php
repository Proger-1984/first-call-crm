<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Property;
use App\Services\ContactService;
use App\Services\ObjectClientService;
use App\Services\PropertyService;
use App\Traits\ResponseTrait;
use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для управления объектами недвижимости CRM
 */
class PropertyController
{
    use ResponseTrait;

    public function __construct(
        private PropertyService $propertyService,
        private ObjectClientService $objectClientService,
        private ContactService $contactService
    ) {}

    // ==========================================
    // CRUD ОБЪЕКТОВ
    // ==========================================

    /**
     * Получить список объектов с фильтрами и пагинацией
     *
     * GET /api/v1/properties
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $params = $request->getQueryParams();
            $result = $this->propertyService->getProperties($userId, $params);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения списка объектов', 'internal_error', 500);
        }
    }

    /**
     * Получить карточку объекта
     *
     * GET /api/v1/properties/{id}
     */
    public function show(Request $request, Response $response): Response
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

            $property = $this->propertyService->getProperty($propertyId, $userId);
            if (!$property) {
                return $this->respondWithError($response, 'Объект не найден', 'not_found', 404);
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'property' => $this->propertyService->formatProperty($property),
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения объекта', 'internal_error', 500);
        }
    }

    /**
     * Создать объект
     *
     * POST /api/v1/properties
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = (array)($request->getParsedBody() ?? []);

            // Валидация: нужен хотя бы адрес или title или listing_id
            if (empty($body['address']) && empty($body['title']) && empty($body['listing_id'])) {
                return $this->respondWithError($response, 'Укажите адрес, название или объявление', 'validation_error', 400);
            }

            $property = $this->propertyService->createProperty($userId, $body);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'property' => $this->propertyService->formatProperty($property),
                    'message' => 'Объект создан',
                ],
            ], 201);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка создания объекта', 'internal_error', 500);
        }
    }

    /**
     * Обновить объект
     *
     * PUT /api/v1/properties/{id}
     */
    public function update(Request $request, Response $response): Response
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

            $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
            if (!$property) {
                return $this->respondWithError($response, 'Объект не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $property = $this->propertyService->updateProperty($property, $body);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'property' => $this->propertyService->formatProperty($property),
                    'message' => 'Объект обновлён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления объекта', 'internal_error', 500);
        }
    }

    /**
     * Удалить объект
     *
     * DELETE /api/v1/properties/{id}
     */
    public function delete(Request $request, Response $response): Response
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

            $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
            if (!$property) {
                return $this->respondWithError($response, 'Объект не найден', 'not_found', 404);
            }

            $this->propertyService->deleteProperty($property);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Объект удалён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления объекта', 'internal_error', 500);
        }
    }

    /**
     * Архивировать/разархивировать объект
     *
     * PATCH /api/v1/properties/{id}/archive
     */
    public function archive(Request $request, Response $response): Response
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

            $property = Property::where('id', $propertyId)->where('user_id', $userId)->first();
            if (!$property) {
                return $this->respondWithError($response, 'Объект не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $archive = filter_var($body['is_archived'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $property = $this->propertyService->toggleArchive($property, $archive);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'is_archived' => $property->is_archived,
                    'message' => $archive ? 'Объект перемещён в архив' : 'Объект восстановлен из архива',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка архивирования объекта', 'internal_error', 500);
        }
    }

    // ==========================================
    // МАССОВЫЕ ОПЕРАЦИИ
    // ==========================================

    /**
     * Массовая операция с объектами
     *
     * POST /api/v1/properties/bulk-action
     */
    public function bulkAction(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = (array)($request->getParsedBody() ?? []);

            $action = $body['action'] ?? '';
            if (empty($action)) {
                return $this->respondWithError($response, 'Не указано действие', 'validation_error', 400);
            }

            $propertyIds = $body['property_ids'] ?? [];
            if (!is_array($propertyIds) || empty($propertyIds)) {
                return $this->respondWithError($response, 'Не указаны объекты', 'validation_error', 400);
            }

            $params = $body['params'] ?? [];

            $result = $this->propertyService->bulkAction($userId, $action, $propertyIds, $params);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка массовой операции', 'internal_error', 500);
        }
    }

    // ==========================================
    // ВОРОНКА + СТАТИСТИКА
    // ==========================================

    /**
     * Получить данные воронки для kanban-доски
     *
     * GET /api/v1/properties/pipeline
     */
    public function getPipeline(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $pipeline = $this->propertyService->getPipelineBoard($userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'pipeline' => $pipeline,
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения воронки', 'internal_error', 500);
        }
    }

    /**
     * Получить статистику по объектам
     *
     * GET /api/v1/properties/stats
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $stats = $this->propertyService->getStats($userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $stats,
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения статистики', 'internal_error', 500);
        }
    }

    // ==========================================
    // СВЯЗКИ ОБЪЕКТ+КОНТАКТ
    // ==========================================

    /**
     * Привязать контакт к объекту
     *
     * POST /api/v1/properties/{id}/contacts
     */
    public function attachContact(Request $request, Response $response): Response
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

            $body = (array)($request->getParsedBody() ?? []);
            $contactId = (int)($body['contact_id'] ?? 0);
            if ($contactId <= 0) {
                return $this->respondWithError($response, 'Не указан ID контакта', 'validation_error', 400);
            }

            $stageId = isset($body['pipeline_stage_id']) ? (int)$body['pipeline_stage_id'] : null;

            $objectClient = $this->objectClientService->attachContact($propertyId, $contactId, $userId, $stageId);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'object_client' => [
                        'id' => $objectClient->id,
                        'property_id' => $objectClient->property_id,
                        'contact_id' => $objectClient->contact_id,
                        'contact' => $objectClient->contact ? [
                            'id' => $objectClient->contact->id,
                            'name' => $objectClient->contact->name,
                            'phone' => $objectClient->contact->phone,
                        ] : null,
                        'pipeline_stage' => $objectClient->pipelineStage ? [
                            'id' => $objectClient->pipelineStage->id,
                            'name' => $objectClient->pipelineStage->name,
                            'color' => $objectClient->pipelineStage->color,
                        ] : null,
                    ],
                    'message' => 'Контакт привязан к объекту',
                ],
            ], 201);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка привязки контакта', 'internal_error', 500);
        }
    }

    /**
     * Отвязать контакт от объекта
     *
     * DELETE /api/v1/properties/{id}/contacts/{contact_id}
     */
    public function detachContact(Request $request, Response $response): Response
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

            $this->objectClientService->detachContact($propertyId, $contactId, $userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Контакт отвязан от объекта',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка отвязки контакта', 'internal_error', 500);
        }
    }

    /**
     * Сменить стадию связки
     *
     * PATCH /api/v1/properties/{id}/contacts/{contact_id}/stage
     */
    public function moveContactStage(Request $request, Response $response): Response
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
            $stageId = (int)($body['pipeline_stage_id'] ?? 0);
            if ($stageId <= 0) {
                return $this->respondWithError($response, 'Не указана стадия', 'validation_error', 400);
            }

            $objectClient = $this->objectClientService->moveToStage($propertyId, $contactId, $stageId, $userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'pipeline_stage' => $objectClient->pipelineStage ? [
                        'id' => $objectClient->pipelineStage->id,
                        'name' => $objectClient->pipelineStage->name,
                        'color' => $objectClient->pipelineStage->color,
                    ] : null,
                    'message' => 'Стадия обновлена',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка смены стадии', 'internal_error', 500);
        }
    }

    /**
     * Обновить связку (комментарий, даты)
     *
     * PATCH /api/v1/properties/{id}/contacts/{contact_id}
     */
    public function updateContact(Request $request, Response $response): Response
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
            $objectClient = $this->objectClientService->updateObjectClient($propertyId, $contactId, $userId, $body);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Связка обновлена',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления связки', 'internal_error', 500);
        }
    }
}
