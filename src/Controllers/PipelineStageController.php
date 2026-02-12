<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PipelineStage;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для управления стадиями воронки продаж
 */
class PipelineStageController
{
    use ResponseTrait;

    /**
     * Получить все стадии воронки пользователя
     *
     * GET /api/v1/clients/stages
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $stages = PipelineStage::getOrCreateForUser($userId);

            $stagesData = $stages->map(function (PipelineStage $stage) {
                return [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'sort_order' => $stage->sort_order,
                    'is_system' => $stage->is_system,
                    'is_final' => $stage->is_final,
                    'clients_count' => $stage->getClientsCount(),
                ];
            })->toArray();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'stages' => $stagesData,
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка получения стадий', 'internal_error', 500);
        }
    }

    /**
     * Создать новую стадию
     *
     * POST /api/v1/clients/stages
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = $request->getParsedBody();
            $name = trim($body['name'] ?? '');
            $color = $body['color'] ?? '#808080';

            // Валидация
            if (empty($name)) {
                return $this->respondWithError($response, 'Название стадии обязательно', 'validation_error', 400);
            }

            if (mb_strlen($name) > 100) {
                return $this->respondWithError($response, 'Название стадии не должно превышать 100 символов', 'validation_error', 400);
            }

            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                return $this->respondWithError($response, 'Неверный формат цвета (ожидается HEX, например #FF5733)', 'validation_error', 400);
            }

            // Проверяем уникальность имени
            $existing = PipelineStage::where('user_id', $userId)
                ->where('name', $name)
                ->exists();

            if ($existing) {
                return $this->respondWithError($response, 'Стадия с таким названием уже существует', 'conflict', 409);
            }

            // Инициализируем стадии если нужно
            PipelineStage::getOrCreateForUser($userId);

            // Получаем максимальный sort_order
            $maxOrder = PipelineStage::where('user_id', $userId)->max('sort_order') ?? 0;

            $isFinal = filter_var($body['is_final'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $stage = PipelineStage::create([
                'user_id' => $userId,
                'name' => mb_substr($name, 0, 100),
                'color' => $color,
                'sort_order' => $maxOrder + 1,
                'is_system' => false,
                'is_final' => $isFinal,
            ]);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'stage' => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'sort_order' => $stage->sort_order,
                        'is_system' => $stage->is_system,
                        'is_final' => $stage->is_final,
                        'clients_count' => 0,
                    ],
                    'message' => 'Стадия создана',
                ],
            ], 201);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка создания стадии', 'internal_error', 500);
        }
    }

    /**
     * Обновить стадию
     *
     * PUT /api/v1/clients/stages/{id}
     */
    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $stageId = (int)($request->getAttribute('id') ?? 0);
            if ($stageId <= 0) {
                return $this->respondWithError($response, 'Не указан ID стадии', 'validation_error', 400);
            }

            $stage = PipelineStage::where('id', $stageId)
                ->where('user_id', $userId)
                ->first();

            if (!$stage) {
                return $this->respondWithError($response, 'Стадия не найдена', 'not_found', 404);
            }

            $body = $request->getParsedBody();

            // Обновляем название
            if (isset($body['name'])) {
                $name = trim($body['name']);

                if (empty($name)) {
                    return $this->respondWithError($response, 'Название стадии обязательно', 'validation_error', 400);
                }
                if (mb_strlen($name) > 100) {
                    return $this->respondWithError($response, 'Название стадии не должно превышать 100 символов', 'validation_error', 400);
                }

                // Проверяем уникальность
                $existing = PipelineStage::where('user_id', $userId)
                    ->where('name', $name)
                    ->where('id', '!=', $stageId)
                    ->exists();

                if ($existing) {
                    return $this->respondWithError($response, 'Стадия с таким названием уже существует', 'conflict', 409);
                }

                $stage->name = $name;
            }

            // Обновляем цвет
            if (isset($body['color'])) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'])) {
                    return $this->respondWithError($response, 'Неверный формат цвета', 'validation_error', 400);
                }
                $stage->color = $body['color'];
            }

            // Обновляем is_final (только для не-системных)
            if (isset($body['is_final']) && !$stage->is_system) {
                $stage->is_final = filter_var($body['is_final'], FILTER_VALIDATE_BOOLEAN);
            }

            $stage->save();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'stage' => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'sort_order' => $stage->sort_order,
                        'is_system' => $stage->is_system,
                        'is_final' => $stage->is_final,
                        'clients_count' => $stage->getClientsCount(),
                    ],
                    'message' => 'Стадия обновлена',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления стадии', 'internal_error', 500);
        }
    }

    /**
     * Удалить стадию
     *
     * DELETE /api/v1/clients/stages/{id}
     */
    public function delete(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $stageId = (int)($request->getAttribute('id') ?? 0);
            if ($stageId <= 0) {
                return $this->respondWithError($response, 'Не указан ID стадии', 'validation_error', 400);
            }

            $stage = PipelineStage::where('id', $stageId)
                ->where('user_id', $userId)
                ->first();

            if (!$stage) {
                return $this->respondWithError($response, 'Стадия не найдена', 'not_found', 404);
            }

            // Системные стадии удалять нельзя
            if ($stage->is_system) {
                return $this->respondWithError($response, 'Системную стадию нельзя удалить', 'validation_error', 400);
            }

            // Проверяем, есть ли клиенты на этой стадии
            $clientsCount = $stage->getClientsCount();
            if ($clientsCount > 0) {
                return $this->respondWithError(
                    $response,
                    "Нельзя удалить стадию с клиентами ({$clientsCount}). Сначала переместите клиентов.",
                    'validation_error',
                    400
                );
            }

            $stage->delete();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Стадия удалена',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка удаления стадии', 'internal_error', 500);
        }
    }

    /**
     * Изменить порядок стадий
     *
     * PUT /api/v1/clients/stages/reorder
     */
    public function reorder(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = $request->getParsedBody();
            $order = $body['order'] ?? [];

            if (!is_array($order) || empty($order)) {
                return $this->respondWithError($response, 'Не указан порядок стадий', 'validation_error', 400);
            }

            // Проверяем, что все стадии принадлежат пользователю
            $userStageIds = PipelineStage::where('user_id', $userId)
                ->pluck('id')
                ->toArray();

            foreach ($order as $stageId) {
                if (!in_array((int)$stageId, $userStageIds, true)) {
                    return $this->respondWithError($response, 'Одна или несколько стадий не найдены', 'not_found', 404);
                }
            }

            // Обновляем порядок
            foreach ($order as $index => $stageId) {
                PipelineStage::where('id', (int)$stageId)
                    ->where('user_id', $userId)
                    ->update(['sort_order' => $index + 1]);
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Порядок стадий обновлён',
                ],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка обновления порядка', 'internal_error', 500);
        }
    }
}
