<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CallStatus;
use App\Models\Category;
use App\Models\Location;
use App\Models\MetroStation;
use App\Models\Room;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSubscription;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для получения данных фильтров
 * 
 * Возвращает данные для фильтров с учётом подписок пользователя:
 * - Обычный пользователь видит только свои оплаченные категории/локации
 * - Админ видит всё
 * 
 * Связи фильтров:
 * - Категории → доступные по подпискам
 * - Локации → доступные по подпискам (зависят от выбранной категории)
 * - Метро → станции выбранной локации
 * - Комнаты → доступные для выбранной категории
 */
class FilterController
{
    use ResponseTrait;

    /**
     * Получение всех данных для фильтров
     * 
     * GET /api/v1/filters
     * 
     * Query параметры:
     * - category_id: int - выбранная категория (для фильтрации локаций)
     * - location_id: int - выбранная локация (для фильтрации метро)
     * 
     * Возвращает:
     * - categories: доступные категории
     * - locations: доступные локации (для выбранной категории или все)
     * - metro: станции метро (для выбранной локации)
     * - rooms: типы комнат (для выбранной категории)
     * - sources: источники объявлений
     * - call_statuses: статусы звонков
     */
    public function getFilters(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $user = User::find($userId);
            
            if (!$user) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404);
            }
            
            // Получаем параметры запроса
            $params = $request->getQueryParams();
            $selectedCategoryId = isset($params['category_id']) ? (int)$params['category_id'] : null;
            
            // location_id может быть массивом (location_id[]) или одиночным значением
            $selectedLocationIds = null;
            if (isset($params['location_id'])) {
                if (is_array($params['location_id'])) {
                    $selectedLocationIds = array_map('intval', $params['location_id']);
                } else {
                    $selectedLocationIds = [(int)$params['location_id']];
                }
            }
            // Также проверяем location_id[] формат
            if (isset($params['location_id[]'])) {
                $locIds = $params['location_id[]'];
                if (is_array($locIds)) {
                    $selectedLocationIds = array_map('intval', $locIds);
                } else {
                    $selectedLocationIds = [(int)$locIds];
                }
            }
            
            $isAdmin = $user->role === 'admin';
            
            // Получаем активные подписки пользователя
            $subscriptions = UserSubscription::with(['category', 'location'])
                ->where('user_id', $userId)
                ->whereIn('status', ['active', 'extend_pending'])
                ->where('end_date', '>=', Carbon::now())
                ->get();
            
            // 1. Категории
            $categories = $this->getAvailableCategories($subscriptions, $isAdmin);
            
            // 2. Локации (зависят от выбранной категории)
            $locations = $this->getAvailableLocations($subscriptions, $isAdmin, $selectedCategoryId);
            
            // 3. Метро (зависит от выбранных локаций или всех доступных)
            $effectiveLocationIds = $selectedLocationIds;
            if (!$effectiveLocationIds && $locations->isNotEmpty()) {
                // Если локации не выбраны — берём все доступные
                $effectiveLocationIds = $locations->pluck('id')->toArray();
            }
            $metro = $this->getMetroStations($effectiveLocationIds);
            
            // 4. Комнаты (зависят от выбранной категории)
            $rooms = $this->getRooms($selectedCategoryId);
            
            // 5. Источники (все активные)
            $sources = Source::where('is_active', true)
                ->get(['id', 'name'])
                ->map(fn($s) => ['id' => $s->id, 'name' => $s->name]);
            
            // 6. Статусы звонков (добавляем "Новое" с id=0)
            $callStatuses = collect([
                ['id' => 0, 'name' => 'Новое', 'color' => '#4CAF50'],
            ])->concat(
                CallStatus::orderBy('sort_order')
                    ->get(['id', 'name', 'color'])
                    ->map(fn($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])
            );
            
            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'categories' => $categories,
                    'locations' => $locations,
                    'metro' => $metro,
                    'rooms' => $rooms,
                    'sources' => $sources,
                    'call_statuses' => $callStatuses,
                    // Мета-информация
                    'meta' => [
                        'is_admin' => $isAdmin,
                        'selected_category_id' => $selectedCategoryId,
                        'selected_location_ids' => $effectiveLocationIds,
                    ],
                ],
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), 'internal_error', 500);
        }
    }

    /**
     * Получить доступные категории
     */
    private function getAvailableCategories($subscriptions, bool $isAdmin)
    {
        if ($isAdmin) {
            // Админ видит все категории
            return Category::all(['id', 'name'])
                ->map(fn($c) => ['id' => $c->id, 'name' => $c->name]);
        }
        
        // Пользователь видит только оплаченные категории
        $categoryIds = $subscriptions->pluck('category_id')->unique();
        
        return Category::whereIn('id', $categoryIds)
            ->get(['id', 'name'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name]);
    }

    /**
     * Получить доступные локации
     */
    private function getAvailableLocations($subscriptions, bool $isAdmin, ?int $categoryId)
    {
        if ($isAdmin) {
            // Админ видит все локации
            $query = Location::query();
            
            // Если выбрана категория — можно добавить дополнительную логику
            // Пока возвращаем все локации
            return $query->get(['id', 'city', 'region'])
                ->map(fn($l) => [
                    'id' => $l->id, 
                    'name' => $l->getFullName(),
                ]);
        }
        
        // Пользователь видит только оплаченные локации
        $filteredSubscriptions = $subscriptions;
        
        // Если выбрана категория — фильтруем локации по ней
        if ($categoryId) {
            $filteredSubscriptions = $subscriptions->where('category_id', $categoryId);
        }
        
        $locationIds = $filteredSubscriptions->pluck('location_id')->unique();
        
        return Location::whereIn('id', $locationIds)
            ->get(['id', 'city', 'region'])
            ->map(fn($l) => [
                'id' => $l->id, 
                'name' => $l->getFullName(),
            ]);
    }

    /**
     * Получить станции метро для локаций
     * 
     * @param array|null $locationIds Массив ID локаций
     */
    private function getMetroStations(?array $locationIds)
    {
        if (!$locationIds || empty($locationIds)) {
            return collect([]);
        }
        
        return MetroStation::whereIn('location_id', $locationIds)
            ->orderBy('name')
            ->orderBy('line')
            ->get(['id', 'name', 'line', 'color'])
            ->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'line' => $m->line, // Линия метро для sublabel
                'color' => $m->color,
            ]);
    }

    /**
     * Получить типы комнат
     */
    private function getRooms(?int $categoryId)
    {
        $query = Room::orderBy('sort_order');
        
        // Если указана категория — фильтруем по связи category_rooms
        if ($categoryId) {
            $query->whereHas('categories', function ($q) use ($categoryId) {
                $q->where('categories.id', $categoryId);
            });
        }
        
        return $query->get(['id', 'name', 'code'])
            ->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'code' => $r->code,
            ]);
    }
}
