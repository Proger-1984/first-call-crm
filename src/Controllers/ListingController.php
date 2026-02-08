<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Listing;
use App\Models\ListingStatus;
use App\Services\ListingFilterService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для работы с объявлениями
 * 
 * Предоставляет API для:
 * - Получения списка объявлений с фильтрацией, сортировкой и пагинацией
 * - Получения одного объявления по ID
 * - Обновления статуса объявления
 * - Получения статистики по объявлениям
 */
class ListingController
{
    use ResponseTrait;

    private ListingFilterService $listingFilterService;

    public function __construct(ContainerInterface $container)
    {
        $this->listingFilterService = $container->get(ListingFilterService::class);
    }

    /**
     * Получение списка объявлений с фильтрацией, сортировкой и пагинацией
     * 
     * POST /api/v1/listings
     * 
     * Body параметры (JSON):
     * - page: int (default: 1) - номер страницы
     * - per_page: int (default: 10, max: 100) - записей на странице
     * - sort: string (default: created_at) - поле сортировки
     * - order: string (default: desc) - направление сортировки (asc/desc)
     * - date_from: string - дата создания от (Y-m-d)
     * - date_to: string - дата создания до (Y-m-d)
     * - status: string - код статуса объявления
     * - source_id: int - ID источника
     * - category_id: int - ID категории
     * - location_id: int - ID локации
     * - price_from: float - цена от
     * - price_to: float - цена до
     * - room_id: int - ID типа комнат
     * - metro_id: int - ID станции метро
     * - phone: string - поиск по телефону
     */
    public function getListings(Request $request, Response $response): Response
    {
        try {
            // Для POST читаем параметры из body
            $params = (array)$request->getParsedBody();
            
            // Получаем ID текущего пользователя из атрибутов запроса (устанавливается в AuthMiddleware)
            $userId = $request->getAttribute('userId');
            
            // Параметры пагинации
            $page = max(1, (int)($params['page'] ?? 1));
            $perPage = min(100, max(1, (int)($params['per_page'] ?? 10)));
            
            // Параметры сортировки
            $sort = $params['sort'] ?? 'created_at';
            $order = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            
            // Валидация поля сортировки
            $allowedSortFields = ['created_at', 'price', 'square_meters', 'updated_at', 'source_id', 'listing_status_id'];
            if (!in_array($sort, $allowedSortFields)) {
                $sort = 'created_at';
            }
            
            // Фильтры
            $filters = $this->extractFilters($params);
            
            // Получаем данные через сервис (передаём userId для статуса звонка)
            $result = $this->listingFilterService->getFilteredListings($filters, $sort, $order, $page, $perPage, $userId);
            
            // Получаем статистику для баннеров (с учётом всех фильтров)
            $stats = $this->listingFilterService->getListingsStats($userId, $filters);
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listings' => $result['listings'],
                    'pagination' => [
                        'total' => $result['total'],
                        'page' => $page,
                        'per_page' => $perPage,
                        'total_pages' => (int)ceil($result['total'] / $perPage),
                    ],
                    'stats' => $stats,
                ],
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Получение одного объявления по ID
     * 
     * GET /api/v1/listings/{id}
     */
    public function getListing(Request $request, Response $response): Response
    {
        try {
            $listingId = (int)$request->getAttribute('id');
            
            $listing = Listing::with(['source', 'category', 'listingStatus', 'location', 'room', 'metroStations'])
                ->find($listingId);
            
            if (!$listing) {
                return $this->respondWithError($response, 'Объявление не найдено', 'not_found', 404);
            }
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listing' => $this->formatListing($listing),
                ],
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Обновление статуса объявления
     * 
     * PATCH /api/v1/listings/{id}/status
     * 
     * Body: { "status": "new" | "our_apartment" | "not_answered" | ... }
     */
    public function updateStatus(Request $request, Response $response): Response
    {
        try {
            $listingId = (int)$request->getAttribute('id');
            $data = $request->getParsedBody();
            
            if (!isset($data['status']) || empty($data['status'])) {
                return $this->respondWithError($response, 'Статус обязателен для заполнения', 'validation_error', 400);
            }
            
            $listing = Listing::find($listingId);
            
            if (!$listing) {
                return $this->respondWithError($response, 'Объявление не найдено', 'not_found', 404);
            }
            
            // Ищем статус по имени (код)
            $status = ListingStatus::where('name', $data['status'])->first();
            
            if (!$status) {
                return $this->respondWithError($response, 'Указанный статус не найден', 'validation_error', 400);
            }
            
            $listing->listing_status_id = $status->id;
            $listing->save();
            
            // Перезагружаем с связями
            $listing->load(['source', 'category', 'listingStatus', 'location', 'room', 'metroStations']);
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Статус объявления успешно обновлён',
                'data' => [
                    'listing' => $this->formatListing($listing),
                ],
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Получение статистики по объявлениям
     * 
     * GET /api/v1/listings/stats
     * 
     * Возвращает количество объявлений по статусам и общую конверсию
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            $stats = $this->listingFilterService->getListingsStats();
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $stats,
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Извлечение фильтров из параметров запроса
     * 
     * Поддерживает как одиночные значения, так и массивы для множественного выбора.
     * 
     * @param array $params Query параметры
     * @return array Массив фильтров
     */
    private function extractFilters(array $params): array
    {
        $filters = [];
        
        // Фильтр по дате
        if (!empty($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        
        // Фильтр по статусу объявления (по имени)
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        
        // Фильтр по источнику (поддержка массива)
        if (!empty($params['source_id'])) {
            $filters['source_id'] = $this->normalizeIntParam($params['source_id']);
        }
        
        // Фильтр по категории (поддержка массива)
        if (!empty($params['category_id'])) {
            $filters['category_id'] = $this->normalizeIntParam($params['category_id']);
        }
        
        // Фильтр по локации (поддержка массива)
        if (!empty($params['location_id'])) {
            $filters['location_id'] = $this->normalizeIntParam($params['location_id']);
        }
        
        // Фильтр по цене
        if (!empty($params['price_from'])) {
            $filters['price_from'] = (float)$params['price_from'];
        }
        if (!empty($params['price_to'])) {
            $filters['price_to'] = (float)$params['price_to'];
        }
        
        // Фильтр по площади
        if (!empty($params['area_from'])) {
            $filters['area_from'] = (float)$params['area_from'];
        }
        if (!empty($params['area_to'])) {
            $filters['area_to'] = (float)$params['area_to'];
        }
        
        // Фильтр по комнатам (поддержка массива)
        if (!empty($params['room_id'])) {
            $filters['room_id'] = $this->normalizeIntParam($params['room_id']);
        }
        
        // Фильтр по метро (поддержка массива)
        if (!empty($params['metro_id'])) {
            $filters['metro_id'] = $this->normalizeIntParam($params['metro_id']);
        }
        
        // Фильтр по телефону (только цифры)
        if (!empty($params['phone'])) {
            // Убираем всё кроме цифр
            $filters['phone'] = preg_replace('/\D/', '', $params['phone']);
        }
        
        // Фильтр по номеру объявления (external_id)
        if (!empty($params['external_id'])) {
            // Убираем всё кроме цифр
            $filters['external_id'] = preg_replace('/\D/', '', $params['external_id']);
        }
        
        // Фильтр по статусу звонка (поддержка массива)
        // 0 = "Новое" (нет записи в agent_listings)
        // 1+ = конкретные статусы из call_statuses
        if (isset($params['call_status_id']) && $params['call_status_id'] !== '' && $params['call_status_id'] !== null) {
            $filters['call_status_id'] = $this->normalizeIntParam($params['call_status_id']);
        }
        
        return $filters;
    }

    /**
     * Нормализация параметра к массиву int
     * 
     * Всегда возвращает массив для унификации обработки.
     * 
     * @param mixed $value Значение (int, string или array)
     * @return array<int> Массив чисел
     */
    private function normalizeIntParam(mixed $value): array
    {
        if (is_array($value)) {
            return array_map('intval', $value);
        }
        return [(int)$value];
    }

    /**
     * Форматирование объявления для ответа API
     * 
     * @param Listing $listing Модель объявления
     * @return array Отформатированные данные
     */
    private function formatListing(Listing $listing): array
    {
        $formatted = [
            'id' => $listing->id,
            'external_id' => $listing->external_id,
            'title' => $listing->title,
            'description' => $listing->description,
            'price' => $listing->price,
            'square_meters' => $listing->square_meters,
            'floor' => $listing->floor,
            'floors_total' => $listing->floors_total,
            'phone' => $listing->phone,
            'address' => $listing->getFullAddress(),
            'city' => $listing->city,
            'street' => $listing->street,
            'house' => $listing->house,
            'url' => $listing->url,
            'lat' => $listing->lat,
            'lng' => $listing->lng,
            'is_paid' => $listing->is_paid,
            'created_at' => $listing->created_at?->toIso8601String(),
            'updated_at' => $listing->updated_at?->toIso8601String(),
        ];
        
        // Источник
        if ($listing->relationLoaded('source') && $listing->source) {
            $formatted['source'] = [
                'id' => $listing->source->id,
                'name' => $listing->source->name,
            ];
        }
        
        // Категория
        if ($listing->relationLoaded('category') && $listing->category) {
            $formatted['category'] = [
                'id' => $listing->category->id,
                'name' => $listing->category->name,
            ];
        }
        
        // Статус
        if ($listing->relationLoaded('listingStatus') && $listing->listingStatus) {
            $formatted['status'] = [
                'id' => $listing->listingStatus->id,
                'name' => $listing->listingStatus->name,
            ];
        }
        
        // Локация
        if ($listing->relationLoaded('location') && $listing->location) {
            $formatted['location'] = [
                'id' => $listing->location->id,
                'name' => $listing->location->getFullName(),
            ];
        }
        
        // Тип комнат
        if ($listing->relationLoaded('room') && $listing->room) {
            $formatted['room'] = [
                'id' => $listing->room->id,
                'name' => $listing->room->name,
                'code' => $listing->room->code,
            ];
        }
        
        // Станции метро
        if ($listing->relationLoaded('metroStations')) {
            $formatted['metro'] = $listing->metroStations->map(function ($metro) {
                return [
                    'id' => $metro->id,
                    'name' => $metro->name,
                    'line' => $metro->line,
                    'color' => $metro->color,
                    'travel_time_min' => $metro->pivot->travel_time_min ?? null,
                    'travel_type' => $metro->pivot->travel_type ?? 'walk',
                ];
            })->toArray();
        }
        
        return $formatted;
    }
}
