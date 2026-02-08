<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserFavoriteStatus;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для управления пользовательскими статусами избранного
 */
class FavoriteStatusController
{
    use ResponseTrait;

    /**
     * Получить все статусы пользователя
     * 
     * GET /api/v1/favorites/statuses
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $statuses = UserFavoriteStatus::getByUser($userId);

            // Добавляем количество избранных для каждого статуса
            $statusesData = $statuses->map(function ($status) {
                return [
                    'id' => $status->id,
                    'name' => $status->name,
                    'color' => $status->color,
                    'sort_order' => $status->sort_order,
                    'favorites_count' => $status->getFavoritesCount(),
                ];
            })->toArray();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'statuses' => $statusesData,
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при получении статусов', 'internal_error', 500);
        }
    }

    /**
     * Создать новый статус
     * 
     * POST /api/v1/favorites/statuses
     * 
     * Body:
     * - name: string - Название статуса (max 50 символов)
     * - color: string - Цвет в HEX формате (default: #808080)
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
                return $this->respondWithError($response, 'Название статуса обязательно', 'validation_error', 400);
            }

            if (mb_strlen($name) > 50) {
                return $this->respondWithError($response, 'Название статуса не должно превышать 50 символов', 'validation_error', 400);
            }

            // Валидация цвета (HEX формат)
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                return $this->respondWithError($response, 'Неверный формат цвета (ожидается HEX, например #FF5733)', 'validation_error', 400);
            }

            // Проверяем, что статус с таким именем не существует
            $existing = UserFavoriteStatus::where('user_id', $userId)
                ->where('name', $name)
                ->exists();

            if ($existing) {
                return $this->respondWithError($response, 'Статус с таким названием уже существует', 'conflict', 409);
            }

            // Создаём статус
            $status = UserFavoriteStatus::createForUser($userId, $name, $color);

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'data' => [
                    'status' => [
                        'id' => $status->id,
                        'name' => $status->name,
                        'color' => $status->color,
                        'sort_order' => $status->sort_order,
                        'favorites_count' => 0,
                    ],
                    'message' => 'Статус создан',
                ],
            ], 201);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при создании статуса', 'internal_error', 500);
        }
    }

    /**
     * Обновить статус
     * 
     * PUT /api/v1/favorites/statuses/{id}
     * 
     * Body:
     * - name: string - Название статуса (max 50 символов)
     * - color: string - Цвет в HEX формате
     */
    public function update(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            // Получаем ID из атрибутов запроса (Slim 4 + PHP-DI)
            $statusId = (int)($request->getAttribute('id') ?? 0);

            if ($statusId <= 0) {
                return $this->respondWithError($response, 'Не указан ID статуса', 'validation_error', 400);
            }

            // Проверяем, что статус принадлежит пользователю
            $status = UserFavoriteStatus::where('id', $statusId)
                ->where('user_id', $userId)
                ->first();

            if (!$status) {
                return $this->respondWithError($response, 'Статус не найден', 'not_found', 404);
            }

            $body = $request->getParsedBody();
            
            // Обновляем название, если указано
            if (isset($body['name'])) {
                $name = trim($body['name']);
                
                if (empty($name)) {
                    return $this->respondWithError($response, 'Название статуса обязательно', 'validation_error', 400);
                }

                if (mb_strlen($name) > 50) {
                    return $this->respondWithError($response, 'Название статуса не должно превышать 50 символов', 'validation_error', 400);
                }

                // Проверяем уникальность имени (кроме текущего статуса)
                $existing = UserFavoriteStatus::where('user_id', $userId)
                    ->where('name', $name)
                    ->where('id', '!=', $statusId)
                    ->exists();

                if ($existing) {
                    return $this->respondWithError($response, 'Статус с таким названием уже существует', 'conflict', 409);
                }

                $status->name = $name;
            }

            // Обновляем цвет, если указан
            if (isset($body['color'])) {
                $color = $body['color'];
                
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    return $this->respondWithError($response, 'Неверный формат цвета (ожидается HEX, например #FF5733)', 'validation_error', 400);
                }

                $status->color = $color;
            }

            $status->save();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'status' => [
                        'id' => $status->id,
                        'name' => $status->name,
                        'color' => $status->color,
                        'sort_order' => $status->sort_order,
                        'favorites_count' => $status->getFavoritesCount(),
                    ],
                    'message' => 'Статус обновлён',
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при обновлении статуса', 'internal_error', 500);
        }
    }

    /**
     * Удалить статус
     * 
     * DELETE /api/v1/favorites/statuses/{id}
     */
    public function delete(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            // Получаем ID из атрибутов запроса (Slim 4 + PHP-DI)
            $statusId = (int)($request->getAttribute('id') ?? 0);

            if ($statusId <= 0) {
                return $this->respondWithError($response, 'Не указан ID статуса', 'validation_error', 400);
            }

            // Проверяем, что статус принадлежит пользователю
            $status = UserFavoriteStatus::where('id', $statusId)
                ->where('user_id', $userId)
                ->first();

            if (!$status) {
                return $this->respondWithError($response, 'Статус не найден', 'not_found', 404);
            }

            // При удалении статуса, у всех избранных с этим статусом status_id станет NULL (ON DELETE SET NULL)
            $status->delete();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Статус удалён',
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при удалении статуса', 'internal_error', 500);
        }
    }

    /**
     * Обновить порядок статусов
     * 
     * PUT /api/v1/favorites/statuses/reorder
     * 
     * Body:
     * - order: array - Массив ID статусов в нужном порядке
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
                return $this->respondWithError($response, 'Не указан порядок статусов', 'validation_error', 400);
            }

            // Проверяем, что все статусы принадлежат пользователю
            $userStatusIds = UserFavoriteStatus::where('user_id', $userId)
                ->pluck('id')
                ->toArray();

            foreach ($order as $statusId) {
                if (!in_array((int)$statusId, $userStatusIds)) {
                    return $this->respondWithError($response, 'Один или несколько статусов не найдены', 'not_found', 404);
                }
            }

            // Обновляем порядок
            foreach ($order as $index => $statusId) {
                UserFavoriteStatus::where('id', (int)$statusId)
                    ->where('user_id', $userId)
                    ->update(['sort_order' => $index + 1]);
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'message' => 'Порядок статусов обновлён',
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при обновлении порядка', 'internal_error', 500);
        }
    }
}
