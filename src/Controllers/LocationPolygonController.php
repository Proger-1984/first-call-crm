<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserLocationPolygon;
use App\Models\UserSubscription;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LocationPolygonController
{
    use ResponseTrait;

    /**
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Получение локаций пользователя по ID подписки
     */
    public function getLocationPolygonsBySubscription(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            $subscriptionId = $request->getAttribute('subscription_id');
            if (is_null($subscriptionId)) {
                return $this->respondWithError(
                    $response,
                    'Не указан ID подписки',
                    'validation_error',
                    400
                );
            }

            // Проверяем, что подписка принадлежит пользователю и активна
            $subscription = UserSubscription::where('id', $subscriptionId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->first();
                
            if (!$subscription) {
                return $this->respondWithError(
                    $response,
                    'Активная подписка не найдена',
                    'not_found',
                    404
                );
            }
            
            // Получаем локации для данной подписки
            $locationPolygons = UserLocationPolygon::where('user_id', $userId)
                ->where('subscription_id', $subscriptionId)
                ->get()
                ->map(function ($locationPolygon) {
                    return [
                        'id' => $locationPolygon->id,
                        'name' => $locationPolygon->name,
                        'polygon_coordinates' => $locationPolygon->polygon_coordinates,
                        'center_lat' => $locationPolygon->center_lat,
                        'center_lng' => $locationPolygon->center_lng,
                        'bounds' => $locationPolygon->bounds,
                        'created_at' => $locationPolygon->created_at->format('Y-m-d H:i:s')
                    ];
                });
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'location_polygons' => $locationPolygons,
                ]
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Создание новой локации пользователя
     */
    public function createLocationPolygon(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $validationError = $this->validateLocationPolygonData($data);
            if ($validationError !== null) {
                return $this->respondWithError($response, $validationError, 'validation_error', 400);
            }
            
            $subscriptionId = (int)$data['subscription_id'];
            
            // Проверяем, что подписка принадлежит пользователю и активна
            $subscription = UserSubscription::where('id', $subscriptionId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->first();
                
            if (!$subscription) {
                return $this->respondWithError(
                    $response,
                    'Активная подписка не найдена',
                    'not_found',
                    404
                );
            }
            
            // Создаем новую локацию
            $locationPolygon = new UserLocationPolygon([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'name' => $data['name'],
                'polygon_coordinates' => $data['polygon_coordinates']
            ]);
            
            // Вычисляем геометрические данные (центр и границы)
            $locationPolygon->calculateGeometryData();
            
            // Сохраняем локацию
            $locationPolygon->save();
            
            // После сохранения явно вызываем обновление PostGIS полей
            $result = $locationPolygon->updatePostGisFields();
            if (!$result) {
                return $this->respondWithError(
                    $response,
                    'Не удалось обновить геометрические поля',
                    'save_failed',
                    422
                );
            }

            return $this->respondWithData($response, [
                'code' => 201,
                'status' => 'success',
                'message' => 'Локация успешно создана',
            ], 201);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Обновление существующей локации пользователя
     */
    public function updateLocationPolygon(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $locationPolygonId = $request->getAttribute('id');
            if (is_null($locationPolygonId)) {
                return $this->respondWithError(
                    $response,
                    'Не указан ID локации',
                    'validation_error',
                    400
                );
            }
            
            // Проверяем, что локация принадлежит пользователю
            $locationPolygon = UserLocationPolygon::where('id', $locationPolygonId)
                ->where('user_id', $userId)
                ->first();
                
            if (!$locationPolygon) {
                return $this->respondWithError($response, 'Локация не найдена', 'not_found', 404);
            }
            
            // Валидация данных
            $validationError = $this->validateLocationPolygonData($data);
            if ($validationError !== null) {
                return $this->respondWithError($response, $validationError, 'validation_error', 400);
            }
            
            // Если указан другой subscription_id, проверяем, что новая подписка принадлежит пользователю и активна
            if (isset($data['subscription_id']) && $data['subscription_id'] != $locationPolygon->subscription_id) {
                $subscription = UserSubscription::where('id', (int)$data['subscription_id'])
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->first();
                    
                if (!$subscription) {
                    return $this->respondWithError($response, 'Активная подписка не найдена', 'not_found', 404);
                }
                
                $locationPolygon->subscription_id = (int)$data['subscription_id'];
            }
            
            // Обновляем данные локации
            if (isset($data['name'])) {
                $locationPolygon->name = $data['name'];
            }
            
            $needRecalculateGeometry = false;
            
            if (isset($data['polygon_coordinates'])) {
                $locationPolygon->polygon_coordinates = $data['polygon_coordinates'];
                $needRecalculateGeometry = true;
            }
            
            // Если изменились координаты, пересчитываем геометрические данные
            if ($needRecalculateGeometry) {
                $locationPolygon->calculateGeometryData();
            }

            $locationPolygon->save();
            $result = $locationPolygon->updatePostGisFields();
            if (!$result) {
                return $this->respondWithError(
                    $response,
                    'Не удалось обновить геометрические поля',
                    'update_failed',
                    422
                );
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Локация успешно обновлена',
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Удаление локации пользователя
     */
    public function deleteLocationPolygon(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            $locationPolygonId = $request->getAttribute('id');
            if (is_null($locationPolygonId)) {
                return $this->respondWithError(
                    $response,
                    'Не указан ID локации',
                    'validation_error',
                    400
                );
            }
            
            // Проверяем, что локация принадлежит пользователю
            $locationPolygon = UserLocationPolygon::where('id', $locationPolygonId)
                ->where('user_id', $userId)
                ->first();
                
            if (!$locationPolygon) {
                return $this->respondWithError($response, 'Локация не найдена', 'not_found', 404);
            }
            
            // Удаляем локацию
            $locationPolygon->delete();
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Локация успешно удалена'
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Валидация данных локации
     * 
     * @param array $data Данные для валидации
     * @return string|null Текст ошибки или null, если ошибок нет
     */
    private function validateLocationPolygonData(array $data): ?string
    {
        if (!is_array($data)) {
            return 'Данные должны быть переданы в формате JSON';
        }

        if (!isset($data['subscription_id']) || !is_numeric($data['subscription_id'])) {
            return 'Отсутствует или неверный формат subscription_id';
        }
            
        if (!isset($data['name']) || empty($data['name'])) {
            return 'Отсутствует название локации';
        }
            
        if (!isset($data['polygon_coordinates']) || !is_array($data['polygon_coordinates']) || empty($data['polygon_coordinates'])) {
            return 'Отсутствуют или неверный формат координат полигона';
        }

        if (count($data['polygon_coordinates']) < 3) {
            return 'Полигон должен содержать минимум 3 точки';
        }

        return null;
    }
} 