<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Client;
use App\Models\ClientListing;
use App\Models\ClientSearchCriteria;
use App\Services\ClientService;
use App\Traits\ResponseTrait;
use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для управления клиентами CRM
 */
class ClientController
{
    use ResponseTrait;

    public function __construct(
        private ClientService $clientService
    ) {}

    /**
     * Получить список клиентов с фильтрами и пагинацией
     *
     * GET /api/v1/clients
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $params = $request->getQueryParams();
            $result = $this->clientService->getClients($userId, $params);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения списка клиентов', 'internal_error', 500);
        }
    }

    /**
     * Получить карточку клиента со всеми связями
     *
     * GET /api/v1/clients/{id}
     */
    public function show(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            $client = $this->clientService->getClient($clientId, $userId);

            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'client' => $this->clientService->formatClient($client),
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения клиента', 'internal_error', 500);
        }
    }

    /**
     * Создать нового клиента
     *
     * POST /api/v1/clients
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = (array)($request->getParsedBody() ?? []);

            // Валидация обязательных полей
            $name = trim($body['name'] ?? '');
            if (empty($name)) {
                return $this->respondWithError($response, 'Имя клиента обязательно', 'validation_error', 400);
            }

            if (mb_strlen($name) > 255) {
                return $this->respondWithError($response, 'Имя клиента не должно превышать 255 символов', 'validation_error', 400);
            }

            $client = $this->clientService->createClient($userId, $body);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'client' => $this->clientService->formatClient($client),
                    'message' => 'Клиент создан',
                ],
            ], 201);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка создания клиента', 'internal_error', 500);
        }
    }

    /**
     * Обновить клиента
     *
     * PUT /api/v1/clients/{id}
     */
    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);

            // Валидация имени, если передано
            if (isset($body['name'])) {
                $name = trim($body['name']);
                if (empty($name)) {
                    return $this->respondWithError($response, 'Имя клиента обязательно', 'validation_error', 400);
                }
                if (mb_strlen($name) > 255) {
                    return $this->respondWithError($response, 'Имя клиента не должно превышать 255 символов', 'validation_error', 400);
                }
            }

            $client = $this->clientService->updateClient($client, $body);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'client' => $this->clientService->formatClient($client),
                    'message' => 'Клиент обновлён',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления клиента', 'internal_error', 500);
        }
    }

    /**
     * Архивировать/разархивировать клиента
     *
     * PATCH /api/v1/clients/{id}/archive
     */
    public function archive(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $archive = filter_var($body['is_archived'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $client = $this->clientService->toggleArchive($client, $archive);

            $message = $archive ? 'Клиент перемещён в архив' : 'Клиент восстановлен из архива';

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'client' => $this->clientService->formatClient($client),
                    'message' => $message,
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка архивирования клиента', 'internal_error', 500);
        }
    }

    /**
     * Удалить клиента
     *
     * DELETE /api/v1/clients/{id}
     */
    public function delete(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $this->clientService->deleteClient($client);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Клиент удалён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления клиента', 'internal_error', 500);
        }
    }

    /**
     * Переместить клиента на другую стадию воронки
     *
     * PATCH /api/v1/clients/{id}/stage
     */
    public function moveStage(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $stageId = (int)($body['stage_id'] ?? 0);
            if ($stageId <= 0) {
                return $this->respondWithError($response, 'Не указана стадия', 'validation_error', 400);
            }

            $client = $this->clientService->moveToStage($client, $stageId, $userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'client' => $this->clientService->formatClient($client),
                    'message' => 'Стадия изменена',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка перемещения клиента', 'internal_error', 500);
        }
    }

    /**
     * Получить данные воронки для kanban-доски
     *
     * GET /api/v1/clients/pipeline
     */
    public function getPipeline(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $pipeline = $this->clientService->getPipelineBoard($userId);

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
     * Получить статистику по клиентам
     *
     * GET /api/v1/clients/stats
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $stats = $this->clientService->getStats($userId);

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
    // ПОДБОРКИ (ПРИВЯЗКА ОБЪЯВЛЕНИЙ)
    // ==========================================

    /**
     * Привязать объявление к клиенту
     *
     * POST /api/v1/clients/{id}/listings
     */
    public function addListing(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            // Проверяем, что клиент принадлежит пользователю
            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $listingId = (int)($body['listing_id'] ?? 0);
            if ($listingId <= 0) {
                return $this->respondWithError($response, 'Не указан ID объявления', 'validation_error', 400);
            }

            $comment = $body['comment'] ?? null;

            $clientListing = $this->clientService->addListing($clientId, $listingId, $comment);
            $clientListing->load('listing');

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'client_listing' => [
                        'id' => $clientListing->id,
                        'listing_id' => $clientListing->listing_id,
                        'status' => $clientListing->status,
                        'comment' => $clientListing->comment,
                        'listing' => $clientListing->listing ? [
                            'id' => $clientListing->listing->id,
                            'title' => $clientListing->listing->title,
                            'price' => $clientListing->listing->price,
                            'address' => $clientListing->listing->getFullAddress(),
                        ] : null,
                    ],
                    'message' => 'Объявление добавлено в подборку',
                ],
            ], 201);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка добавления объявления в подборку', 'internal_error', 500);
        }
    }

    /**
     * Отвязать объявление от клиента
     *
     * DELETE /api/v1/clients/{id}/listings/{listing_id}
     */
    public function removeListing(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            $listingId = (int)($request->getAttribute('listing_id') ?? 0);

            if ($clientId <= 0 || $listingId <= 0) {
                return $this->respondWithError($response, 'Не указаны ID клиента или объявления', 'validation_error', 400);
            }

            // Проверяем владельца
            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $this->clientService->removeListing($clientId, $listingId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Объявление удалено из подборки',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления объявления из подборки', 'internal_error', 500);
        }
    }

    /**
     * Обновить статус привязки объявления
     *
     * PATCH /api/v1/clients/{id}/listings/{listing_id}
     */
    public function updateListingStatus(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            $listingId = (int)($request->getAttribute('listing_id') ?? 0);

            if ($clientId <= 0 || $listingId <= 0) {
                return $this->respondWithError($response, 'Не указаны ID клиента или объявления', 'validation_error', 400);
            }

            // Проверяем владельца
            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $status = $body['status'] ?? '';
            if (empty($status)) {
                return $this->respondWithError($response, 'Не указан статус', 'validation_error', 400);
            }

            $comment = $body['comment'] ?? null;

            $clientListing = $this->clientService->updateListingStatus($clientId, $listingId, $status, $comment);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'client_listing' => [
                        'id' => $clientListing->id,
                        'listing_id' => $clientListing->listing_id,
                        'status' => $clientListing->status,
                        'comment' => $clientListing->comment,
                        'showed_at' => $clientListing->showed_at?->toIso8601String(),
                    ],
                    'message' => 'Статус обновлён',
                ],
            ], 200);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления статуса подборки', 'internal_error', 500);
        }
    }

    // ==========================================
    // КРИТЕРИИ ПОИСКА
    // ==========================================

    /**
     * Добавить критерий поиска клиенту
     *
     * POST /api/v1/clients/{id}/criteria
     */
    public function addCriteria(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $clientId = (int)($request->getAttribute('id') ?? 0);
            if ($clientId <= 0) {
                return $this->respondWithError($response, 'Не указан ID клиента', 'validation_error', 400);
            }

            // Проверяем владельца
            $client = Client::where('id', $clientId)->where('user_id', $userId)->first();
            if (!$client) {
                return $this->respondWithError($response, 'Клиент не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $criteria = $this->clientService->addSearchCriteria($clientId, $body);
            $criteria->load(['category', 'location']);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'criteria' => [
                        'id' => $criteria->id,
                        'category' => $criteria->category ? ['id' => $criteria->category->id, 'name' => $criteria->category->name] : null,
                        'location' => $criteria->location ? ['id' => $criteria->location->id, 'name' => $criteria->location->getFullName()] : null,
                        'room_ids' => $criteria->room_ids,
                        'price_min' => $criteria->price_min,
                        'price_max' => $criteria->price_max,
                        'area_min' => $criteria->area_min,
                        'area_max' => $criteria->area_max,
                        'floor_min' => $criteria->floor_min,
                        'floor_max' => $criteria->floor_max,
                        'metro_ids' => $criteria->metro_ids,
                        'districts' => $criteria->districts,
                        'notes' => $criteria->notes,
                        'is_active' => $criteria->is_active,
                    ],
                    'message' => 'Критерий поиска добавлен',
                ],
            ], 201);

        } catch (InvalidArgumentException $exception) {
            return $this->respondWithError($response, $exception->getMessage(), 'validation_error', 400);
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка добавления критерия', 'internal_error', 500);
        }
    }

    /**
     * Обновить критерий поиска
     *
     * PUT /api/v1/clients/criteria/{id}
     */
    public function updateCriteria(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $criteriaId = (int)($request->getAttribute('id') ?? 0);
            if ($criteriaId <= 0) {
                return $this->respondWithError($response, 'Не указан ID критерия', 'validation_error', 400);
            }

            // Находим критерий и проверяем владельца через клиента
            $criteria = ClientSearchCriteria::with('client')->find($criteriaId);
            if (!$criteria || !$criteria->client || $criteria->client->user_id !== $userId) {
                return $this->respondWithError($response, 'Критерий не найден', 'not_found', 404);
            }

            $body = (array)($request->getParsedBody() ?? []);
            $criteria = $this->clientService->updateSearchCriteria($criteria, $body);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Критерий обновлён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления критерия', 'internal_error', 500);
        }
    }

    /**
     * Удалить критерий поиска
     *
     * DELETE /api/v1/clients/criteria/{id}
     */
    public function deleteCriteria(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $criteriaId = (int)($request->getAttribute('id') ?? 0);
            if ($criteriaId <= 0) {
                return $this->respondWithError($response, 'Не указан ID критерия', 'validation_error', 400);
            }

            // Находим критерий и проверяем владельца через клиента
            $criteria = ClientSearchCriteria::with('client')->find($criteriaId);
            if (!$criteria || !$criteria->client || $criteria->client->user_id !== $userId) {
                return $this->respondWithError($response, 'Критерий не найден', 'not_found', 404);
            }

            $this->clientService->deleteSearchCriteria($criteria);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Критерий удалён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления критерия', 'internal_error', 500);
        }
    }
}
