<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentListing;
use App\Models\CallStatus;
use App\Models\Listing;
use App\Models\ListingStatus;
use App\Models\User;
use App\Models\UserFavorite;
use App\Models\UserLocationPolygon;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Capsule\Manager as DB;
use JetBrains\PhpStorm\ArrayShape;

/**
 * Сервис для фильтрации объявлений с учётом полигонов пользователя
 * 
 * Реализует гибридный подход:
 * - Если у подписки есть полигоны — возвращаем только объявления внутри них
 * - Если полигонов нет — возвращаем все объявления в локации подписки
 * 
 * Также предоставляет методы для:
 * - Полной фильтрации по всем параметрам
 * - Сортировки по колонкам
 * - Пагинации с подсчётом total
 * - Получения статистики по объявлениям
 */
class ListingFilterService
{
    /**
     * Получить объявления для автозвонка по подписке
     * 
     * @param UserSubscription $subscription Активная подписка пользователя
     * @param int|null $limit Лимит объявлений (null = без лимита)
     * @return Collection<Listing>
     */
    public function getListingsForAutoCall(UserSubscription $subscription, ?int $limit = null): Collection
    {
        // Базовый запрос: объявления в локации подписки, которые ещё не обработаны
        $query = Listing::query()
            ->where('location_id', $subscription->location_id)
            ->where('category_id', $subscription->category_id)
            ->whereNull('auto_call_processed_at')
            ->whereNotNull('phone')
            ->orderBy('created_at', 'desc');

        // Применяем фильтр по полигонам
        $query = $this->applyPolygonFilter($query, $subscription->id);

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Получить объявления для подписки с учётом полигонов
     * 
     * @param UserSubscription $subscription
     * @param array $filters Дополнительные фильтры
     * @return Builder
     */
    public function getListingsQuery(UserSubscription $subscription, array $filters = [])
    {
        $query = Listing::query()
            ->where('location_id', $subscription->location_id)
            ->where('category_id', $subscription->category_id);

        // Применяем фильтр по полигонам
        $query = $this->applyPolygonFilter($query, $subscription->id);

        // Применяем дополнительные фильтры
        if (!empty($filters['status'])) {
            $query->where('listing_status_id', $filters['status']);
        }

        if (!empty($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (!empty($filters['rooms'])) {
            $query->whereIn('rooms', (array)$filters['rooms']);
        }

        return $query;
    }

    /**
     * Проверить, попадает ли объявление в полигоны подписки
     * 
     * @param Listing $listing
     * @param int $subscriptionId
     * @return bool
     */
    public function isListingInSubscriptionPolygons(Listing $listing, int $subscriptionId): bool
    {
        // Если у объявления нет координат — не можем проверить
        if (!$listing->hasCoordinates()) {
            return false;
        }

        // Проверяем, есть ли полигоны у подписки
        $hasPolygons = UserLocationPolygon::where('subscription_id', $subscriptionId)->exists();
        
        // Если полигонов нет — считаем что объявление подходит
        if (!$hasPolygons) {
            return true;
        }

        // Проверяем попадание в полигоны через PostGIS
        $result = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM user_location_polygons p 
                WHERE p.subscription_id = ? 
                AND ST_Contains(p.polygon, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            ) as in_polygon
        ", [$subscriptionId, $listing->lng, $listing->lat]);

        return (bool)$result->in_polygon;
    }

    /**
     * Получить количество полигонов у подписки
     * 
     * @param int $subscriptionId
     * @return int
     */
    public function getPolygonsCount(int $subscriptionId): int
    {
        return UserLocationPolygon::where('subscription_id', $subscriptionId)->count();
    }

    /**
     * Применить фильтр по полигонам к запросу
     * 
     * @param Builder $query
     * @param int $subscriptionId
     * @return Builder
     */
    private function applyPolygonFilter($query, int $subscriptionId)
    {
        // Проверяем, есть ли полигоны у подписки
        $hasPolygons = UserLocationPolygon::where('subscription_id', $subscriptionId)->exists();

        if ($hasPolygons) {
            // Фильтруем только объявления внутри полигонов
            $query->whereRaw('
                listings.point IS NOT NULL 
                AND EXISTS (
                    SELECT 1 FROM user_location_polygons p 
                    WHERE p.subscription_id = ? 
                    AND ST_Contains(p.polygon, listings.point)
                )
            ', [$subscriptionId]);
        }

        return $query;
    }

    /**
     * Ограничить выборку по подпискам пользователя
     * 
     * Админ видит все объявления.
     * Обычный пользователь видит только объявления из своих оплаченных категорий/локаций.
     * 
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    private function applySubscriptionFilter(Builder $query, int $userId): Builder
    {
        $user = User::find($userId);
        
        // Админ видит всё
        if ($user && $user->role === 'admin') {
            return $query;
        }
        
        // Получаем активные подписки пользователя
        $subscriptions = UserSubscription::where('user_id', $userId)
            ->where('status', 'active')
            ->get();
        
        if ($subscriptions->isEmpty()) {
            // Нет подписок — не показываем ничего
            $query->whereRaw('1 = 0');
            return $query;
        }
        
        // Строим условие: (category_id = X AND location_id = Y) OR (category_id = Z AND location_id = W) ...
        $query->where(function (Builder $q) use ($subscriptions) {
            foreach ($subscriptions as $subscription) {
                $q->orWhere(function (Builder $subQ) use ($subscription) {
                    $subQ->where('category_id', $subscription->category_id)
                         ->where('location_id', $subscription->location_id);
                });
            }
        });
        
        return $query;
    }

    /**
     * Получить статистику по объявлениям в полигонах
     * 
     * @param int $subscriptionId
     * @return array
     */
    public function getPolygonStats(int $subscriptionId): array
    {
        $subscription = UserSubscription::find($subscriptionId);
        if (!$subscription) {
            return [
                'total_in_location' => 0,
                'total_in_polygons' => 0,
                'polygons_count' => 0,
            ];
        }

        // Всего объявлений в локации
        $totalInLocation = Listing::where('location_id', $subscription->location_id)
            ->where('category_id', $subscription->category_id)
            ->count();

        // Количество полигонов
        $polygonsCount = $this->getPolygonsCount($subscriptionId);

        // Объявлений в полигонах
        $totalInPolygons = 0;
        if ($polygonsCount > 0) {
            $totalInPolygons = Listing::where('location_id', $subscription->location_id)
                ->where('category_id', $subscription->category_id)
                ->whereRaw('
                    listings.point IS NOT NULL 
                    AND EXISTS (
                        SELECT 1 FROM user_location_polygons p 
                        WHERE p.subscription_id = ? 
                        AND ST_Contains(p.polygon, listings.point)
                    )
                ', [$subscriptionId])
                ->count();
        }

        return [
            'total_in_location' => $totalInLocation,
            'total_in_polygons' => $polygonsCount > 0 ? $totalInPolygons : $totalInLocation,
            'polygons_count' => $polygonsCount,
        ];
    }

    /**
     * Получить отфильтрованные объявления с пагинацией
     * 
     * @param array $filters Фильтры
     * @param string $sort Поле сортировки
     * @param string $order Направление сортировки (asc/desc)
     * @param int $page Номер страницы
     * @param int $perPage Записей на странице
     * @return array ['listings' => array, 'total' => int]
     */
    #[ArrayShape(['listings' => "mixed[]", 'total' => "int"])]
    public function getFilteredListings(
        array $filters,
        string $sort = 'created_at',
        string $order = 'desc',
        int $page = 1,
        int $perPage = 20,
        ?int $userId = null
    ): array {
        $query = Listing::query()
            ->with(['source', 'category', 'listingStatus', 'location', 'room', 'metroStations', 'photoTask']);

        // Ограничиваем выборку по подпискам пользователя (если не админ)
        if ($userId) {
            $query = $this->applySubscriptionFilter($query, $userId);
        }

        // Применяем фильтры
        $query = $this->applyFilters($query, $filters);

        // Применяем фильтр по статусу звонка (если указан)
        if (!empty($filters['call_status_id']) && $userId) {
            $callStatusIds = is_array($filters['call_status_id']) 
                ? array_map('intval', $filters['call_status_id'])
                : [(int)$filters['call_status_id']];
            $query = $this->applyCallStatusFilter($query, $callStatusIds, $userId);
        }

        // Получаем общее количество
        $total = $query->count();

        // Валидация полей сортировки
        $allowedSortFields = [
            'created_at',
            'updated_at', 
            'price',
            'square_meters',
            'source_id',
            'listing_status_id',
        ];
        
        if (!in_array($sort, $allowedSortFields, true)) {
            $sort = 'created_at';
        }

        // Применяем сортировку с вторичной сортировкой по id для стабильности
        $query->orderBy($sort, $order)->orderBy('id', $order);

        // Применяем пагинацию
        $offset = ($page - 1) * $perPage;
        $listings = $query->skip($offset)->take($perPage)->get();

        // Получаем статусы звонков для текущего пользователя (если указан)
        $agentListingsMap = [];
        $favoriteIds = [];
        if ($userId) {
            $listingIds = $listings->pluck('id')->toArray();
            $agentListings = AgentListing::where('user_id', $userId)
                ->whereIn('listing_id', $listingIds)
                ->with('callStatus')
                ->get()
                ->keyBy('listing_id');
            
            foreach ($agentListings as $al) {
                $agentListingsMap[$al->listing_id] = $al;
            }
            
            // Получаем избранные объявления пользователя
            $favoriteIds = UserFavorite::where('user_id', $userId)
                ->whereIn('listing_id', $listingIds)
                ->pluck('listing_id')
                ->toArray();
        }

        // Получаем дубликаты для всех объявлений одним запросом
        $duplicatesMap = $this->findDuplicatesForListings($listings);

        // Форматируем объявления
        $formattedListings = $listings->map(function ($listing) use ($agentListingsMap, $duplicatesMap, $favoriteIds) {
            return $this->formatListingForApi(
                $listing, 
                $agentListingsMap[$listing->id] ?? null,
                $duplicatesMap[$listing->id] ?? [],
                in_array($listing->id, $favoriteIds)
            );
        })->toArray();

        return [
            'listings' => $formattedListings,
            'total' => $total,
        ];
    }

    /**
     * Найти дубликаты для списка объявлений
     * 
     * Дубликат — это любое другое объявление с тем же адресом 
     * (location_id + city + street + house + floor + room_id), независимо от источника.
     * 
     * Для полей city, floor, room_id: если оба значения NULL — не считаем совпадением,
     * сравниваем только когда оба значения заполнены.
     * 
     * @param \Illuminate\Support\Collection $listings
     * @return array<int, array> Карта listing_id => [дубликаты]
     */
    private function findDuplicatesForListings($listings): array
    {
        if ($listings->isEmpty()) {
            return [];
        }

        $listingIds = $listings->pluck('id')->toArray();
        
        // Собираем уникальные комбинации адресов для поиска дубликатов
        $addressKeys = [];
        foreach ($listings as $listing) {
            // Пропускаем объявления без полного адреса
            if (empty($listing->location_id) || empty($listing->street) || empty($listing->house)) {
                continue;
            }
            
            $key = implode('|', [
                $listing->location_id,
                $listing->city ? mb_strtolower(trim($listing->city)) : '',
                mb_strtolower(trim($listing->street)),
                mb_strtolower(trim($listing->house)),
                $listing->floor ?? '',
                $listing->room_id ?? '',
            ]);
            
            if (!isset($addressKeys[$key])) {
                $addressKeys[$key] = [];
            }
            $addressKeys[$key][] = $listing->id;
        }

        // Ищем дубликаты только для адресов, которые встречаются в нашей выборке
        $duplicatesMap = [];
        
        foreach ($addressKeys as $key => $ids) {
            [$locationId, $city, $street, $house, $floor, $roomId] = explode('|', $key);
            
            // Запрос на поиск всех объявлений с таким же адресом
            $query = Listing::query()
                ->select(['id', 'source_id', 'url'])
                ->with('source:id,name')
                ->where('location_id', (int)$locationId)
                ->whereRaw('LOWER(TRIM(street)) = ?', [$street])
                ->whereRaw('LOWER(TRIM(house)) = ?', [$house]);
            
            // City: сравниваем только если заполнено, иначе игнорируем
            if ($city !== '') {
                $query->whereRaw('LOWER(TRIM(city)) = ?', [$city]);
            } else {
                // Если city пустой — ищем тоже пустые (NULL или '')
                $query->where(function ($q) {
                    $q->whereNull('city')->orWhere('city', '');
                });
            }
            
            // Floor: сравниваем только если заполнено
            if ($floor !== '') {
                $query->where('floor', (int)$floor);
            } else {
                $query->whereNull('floor');
            }
            
            // Room: сравниваем только если заполнено
            if ($roomId !== '') {
                $query->where('room_id', (int)$roomId);
            } else {
                $query->whereNull('room_id');
            }
            
            $allDuplicates = $query->get();
            
            // Если нашли больше одного объявления с таким адресом — это дубликаты
            if ($allDuplicates->count() > 1) {
                foreach ($ids as $listingId) {
                    $duplicatesMap[$listingId] = $allDuplicates
                        ->filter(fn($d) => $d->id !== $listingId)
                        ->map(fn($d) => [
                            'id' => $d->id,
                            'source_id' => $d->source_id,
                            'source_name' => $d->source?->name,
                            'url' => $d->url,
                        ])
                        ->values()
                        ->toArray();
                }
            }
        }

        return $duplicatesMap;
    }

    /**
     * Применить фильтры к запросу
     * 
     * @param Builder $query
     * @param array $filters
     * @return Builder
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        // Фильтр по дате создания
        if (!empty($filters['date_from'])) {
            try {
                $dateFrom = Carbon::parse($filters['date_from'])->startOfDay();
                $query->where('created_at', '>=', $dateFrom);
            } catch (Exception $e) {
                // Игнорируем невалидную дату
            }
        }
        
        if (!empty($filters['date_to'])) {
            try {
                $dateTo = Carbon::parse($filters['date_to'])->endOfDay();
                $query->where('created_at', '<=', $dateTo);
            } catch (Exception $e) {
                // Игнорируем невалидную дату
            }
        }

        // Фильтр по статусу (по имени)
        if (!empty($filters['status'])) {
            $status = ListingStatus::where('name', $filters['status'])->first();
            if ($status) {
                $query->where('listing_status_id', $status->id);
            }
        }

        // Фильтр по источнику (всегда массив)
        if (!empty($filters['source_id'])) {
            $query->whereIn('source_id', (array)$filters['source_id']);
        }

        // Фильтр по категории (всегда массив)
        if (!empty($filters['category_id'])) {
            $query->whereIn('category_id', (array)$filters['category_id']);
        }

        // Фильтр по локации (всегда массив)
        if (!empty($filters['location_id'])) {
            $query->whereIn('location_id', (array)$filters['location_id']);
        }

        // Фильтр по цене
        if (!empty($filters['price_from'])) {
            $query->where('price', '>=', $filters['price_from']);
        }
        if (!empty($filters['price_to'])) {
            $query->where('price', '<=', $filters['price_to']);
        }

        // Фильтр по площади
        if (!empty($filters['area_from'])) {
            $query->where('square_meters', '>=', $filters['area_from']);
        }
        if (!empty($filters['area_to'])) {
            $query->where('square_meters', '<=', $filters['area_to']);
        }

        // Фильтр по типу комнат (всегда массив)
        if (!empty($filters['room_id'])) {
            $query->whereIn('room_id', (array)$filters['room_id']);
        }

        // Фильтр по станции метро (всегда массив)
        if (!empty($filters['metro_id'])) {
            $metroIds = (array)$filters['metro_id'];
            $query->whereHas('metroStations', function ($q) use ($metroIds) {
                $q->whereIn('metro_stations.id', $metroIds);
            });
        }

        // Фильтр по телефону (частичное совпадение)
        if (!empty($filters['phone'])) {
            // Убираем все нецифровые символы для поиска
            $phoneDigits = preg_replace('/\D/', '', $filters['phone']);
            $query->where(function ($q) use ($filters, $phoneDigits) {
                $q->where('phone', 'LIKE', '%' . $filters['phone'] . '%')
                  ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '', 'g') LIKE ?", ['%' . $phoneDigits . '%']);
            });
        }

        // Фильтр по external_id (номер объявления)
        if (!empty($filters['external_id'])) {
            $query->where('external_id', 'LIKE', '%' . $filters['external_id'] . '%');
        }

        return $query;
    }

    /**
     * Применить фильтр по статусу звонка
     * 
     * Этот фильтр особый — он фильтрует по данным из agent_listings,
     * а не по полям самого объявления.
     * 
     * @param Builder $query
     * @param array $callStatusIds Массив ID статусов звонка
     * @param int $userId ID пользователя
     * @return Builder
     */
    private function applyCallStatusFilter(Builder $query, array $callStatusIds, int $userId): Builder
    {
        // Проверяем, есть ли в списке "Новое" (id = 0)
        $includeNew = in_array(0, $callStatusIds);
        $otherStatusIds = array_filter($callStatusIds, fn($id) => $id !== 0);

        if ($includeNew && empty($otherStatusIds)) {
            // Только "Новое" — объявления без записи в agent_listings для этого пользователя
            $query->whereNotExists(function ($subQuery) use ($userId) {
                $subQuery->select(DB::raw(1))
                    ->from('agent_listings')
                    ->whereColumn('agent_listings.listing_id', 'listings.id')
                    ->where('agent_listings.user_id', $userId);
            });
        } elseif (!$includeNew && !empty($otherStatusIds)) {
            // Только конкретные статусы — объявления с записью в agent_listings
            $query->whereExists(function ($subQuery) use ($userId, $otherStatusIds) {
                $subQuery->select(DB::raw(1))
                    ->from('agent_listings')
                    ->whereColumn('agent_listings.listing_id', 'listings.id')
                    ->where('agent_listings.user_id', $userId)
                    ->whereIn('agent_listings.call_status_id', $otherStatusIds);
            });
        } elseif ($includeNew && !empty($otherStatusIds)) {
            // "Новое" + другие статусы
            $query->where(function ($q) use ($userId, $otherStatusIds) {
                // Либо нет записи (Новое)
                $q->whereNotExists(function ($subQuery) use ($userId) {
                    $subQuery->select(DB::raw(1))
                        ->from('agent_listings')
                        ->whereColumn('agent_listings.listing_id', 'listings.id')
                        ->where('agent_listings.user_id', $userId);
                })
                // Либо есть запись с нужным статусом
                ->orWhereExists(function ($subQuery) use ($userId, $otherStatusIds) {
                    $subQuery->select(DB::raw(1))
                        ->from('agent_listings')
                        ->whereColumn('agent_listings.listing_id', 'listings.id')
                        ->where('agent_listings.user_id', $userId)
                        ->whereIn('agent_listings.call_status_id', $otherStatusIds);
                });
            });
        }

        return $query;
    }

    /**
     * Форматирование объявления для API ответа
     * 
     * @param Listing $listing
     * @param AgentListing|null $agentListing Запись агента (для статуса звонка)
     * @param array $duplicates Список дубликатов объявления
     * @param bool $isFavorite Находится ли в избранном
     * @return array
     */
    private function formatListingForApi(Listing $listing, ?AgentListing $agentListing = null, array $duplicates = [], bool $isFavorite = false): array
    {
        // Декодируем price_history из JSON если это строка
        $priceHistory = $listing->price_history;
        if (is_string($priceHistory)) {
            $priceHistory = json_decode($priceHistory, true);
        }

        $formatted = [
            'id' => $listing->id,
            'external_id' => $listing->external_id,
            'title' => $listing->title,
            'description' => $listing->description,
            'price' => $listing->price,
            'price_history' => $priceHistory,
            'square_meters' => $listing->square_meters,
            'floor' => $listing->floor,
            'floors_total' => $listing->floors_total,
            'phone' => $listing->phone,
            'phone_unavailable' => (bool) $listing->phone_unavailable,
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

        // Статус объявления (Новое/Поднятое)
        if ($listing->relationLoaded('listingStatus') && $listing->listingStatus) {
            $formatted['listing_status'] = [
                'id' => $listing->listingStatus->id,
                'name' => $listing->listingStatus->name,
            ];
        }

        // Статус звонка (из agent_listings)
        // Если записи нет — статус "Новое" (ещё не звонили)
        if ($agentListing && $agentListing->callStatus) {
            $formatted['call_status'] = [
                'id' => $agentListing->callStatus->id,
                'name' => $agentListing->callStatus->name,
                'color' => $agentListing->callStatus->color,
            ];
        } else {
            // Нет записи = "Новое"
            $formatted['call_status'] = [
                'id' => 0,
                'name' => 'Новое',
                'color' => '#9E9E9E',
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
                    'distance' => $metro->pivot->distance ?? null,
                    'travel_type' => $metro->pivot->travel_type ?? 'walk',
                ];
            })->toArray();
        }

        // Дубликаты (объявления с тем же адресом из других источников)
        $formatted['duplicates'] = $duplicates;

        // Избранное
        $formatted['is_favorite'] = $isFavorite;

        // Задача обработки фото (последняя)
        if ($listing->relationLoaded('photoTask') && $listing->photoTask) {
            $formatted['photo_task'] = [
                'id' => $listing->photoTask->id,
                'status' => $listing->photoTask->status,
                'photos_count' => $listing->photoTask->photos_count,
                'error_message' => $listing->photoTask->error_message,
            ];
        }

        return $formatted;
    }

    /**
     * Получить статистику по объявлениям для текущего пользователя
     * 
     * Статистика считается по call_status (статус звонка из agent_listings),
     * а не по listing_status. Учитываются только объявления, доступные пользователю
     * по его подпискам, а также все применённые фильтры.
     * 
     * Если дата не указана — по умолчанию за сегодня.
     * Также возвращает тренды (сравнение с предыдущим аналогичным периодом).
     * 
     * @param int|null $userId ID пользователя (если null - общая статистика)
     * @param array $filters Фильтры (те же, что применяются к списку объявлений)
     * @return array
     */
    public function getListingsStats(?int $userId = null, array $filters = []): array
    {
        // Определяем период для статистики
        $dateFrom = !empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : Carbon::today();
        $dateTo = !empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : Carbon::today()->endOfDay();
        
        // Вычисляем длительность периода в днях
        $periodDays = $dateFrom->diffInDays($dateTo) + 1;
        
        // Вычисляем предыдущий период такой же длительности
        $prevDateTo = $dateFrom->copy()->subDay()->endOfDay();
        $prevDateFrom = $prevDateTo->copy()->subDays($periodDays - 1)->startOfDay();
        
        // Получаем статистику за текущий период
        $currentStats = $this->calculateStatsForPeriod($userId, $filters, $dateFrom, $dateTo);
        
        // Получаем статистику за предыдущий период (с теми же фильтрами, но другими датами)
        $prevFilters = $filters;
        $prevFilters['date_from'] = $prevDateFrom->toDateString();
        $prevFilters['date_to'] = $prevDateTo->toDateString();
        $prevStats = $this->calculateStatsForPeriod($userId, $prevFilters, $prevDateFrom, $prevDateTo);
        
        // Вычисляем тренды (изменение в процентах)
        $trends = $this->calculateTrends($currentStats, $prevStats);
        
        return [
            'total' => $currentStats['total'],
            'our_apartments' => $currentStats['our_apartments'],
            'not_picked_up' => $currentStats['not_picked_up'],
            'not_first' => $currentStats['not_first'],
            'not_answered' => $currentStats['not_answered'],
            'agent' => $currentStats['agent'],
            'new' => $currentStats['new'],
            'conversion' => $currentStats['conversion'],
            'trends' => $trends,
            'period' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
                'days' => $periodDays,
            ],
            'prev_period' => [
                'from' => $prevDateFrom->toDateString(),
                'to' => $prevDateTo->toDateString(),
            ],
        ];
    }

    /**
     * Вычислить статистику за конкретный период
     * 
     * @param int|null $userId
     * @param array $filters
     * @param Carbon $dateFrom
     * @param Carbon $dateTo
     * @return array
     */
    private function calculateStatsForPeriod(?int $userId, array $filters, Carbon $dateFrom, Carbon $dateTo): array
    {
        // Базовый запрос с учётом подписок пользователя
        $baseQuery = Listing::query();
        if ($userId) {
            $baseQuery = $this->applySubscriptionFilter($baseQuery, $userId);
        }
        
        // Применяем все фильтры
        $baseQuery = $this->applyFilters($baseQuery, $filters);
        
        // Принудительно применяем фильтр по дате (перезаписываем)
        $baseQuery->where('created_at', '>=', $dateFrom)
                  ->where('created_at', '<=', $dateTo);
        
        // Применяем фильтр по статусу звонка если указан
        if (!empty($filters['call_status_id']) && $userId) {
            $callStatusIds = is_array($filters['call_status_id']) 
                ? array_map('intval', $filters['call_status_id'])
                : [(int)$filters['call_status_id']];
            $baseQuery = $this->applyCallStatusFilter($baseQuery, $callStatusIds, $userId);
        }
        
        // Общее количество объявлений
        $total = (clone $baseQuery)->count();
        
        // Получаем ID объявлений для подсчёта статусов звонков
        $listingIds = (clone $baseQuery)->pluck('id')->toArray();
        
        // Если нет объявлений - возвращаем нули
        if (empty($listingIds)) {
            return [
                'total' => 0,
                'our_apartments' => 0,
                'not_picked_up' => 0,
                'not_first' => 0,
                'not_answered' => 0,
                'agent' => 0,
                'new' => 0,
                'conversion' => 0,
            ];
        }
        
        // Считаем статусы звонков из agent_listings для текущего пользователя
        $callStatusCounts = [];
        if ($userId) {
            $callStatusCounts = AgentListing::where('user_id', $userId)
                ->whereIn('listing_id', $listingIds)
                ->selectRaw('call_status_id, COUNT(*) as count')
                ->groupBy('call_status_id')
                ->pluck('count', 'call_status_id')
                ->toArray();
        }
        
        // Количество объявлений с записями в agent_listings
        $processedCount = array_sum($callStatusCounts);
        
        // "Новые" = объявления без записи в agent_listings
        $newCount = $total - $processedCount;
        
        // Статусы звонков
        $ourApartments = $callStatusCounts[1] ?? 0;
        $notAnswered = $callStatusCounts[2] ?? 0;
        $notPickedUp = $callStatusCounts[3] ?? 0;
        $agent = $callStatusCounts[4] ?? 0;
        $notFirst = $callStatusCounts[5] ?? 0;
        
        // Конверсия
        $processed = $ourApartments + $notAnswered + $notPickedUp + $agent + $notFirst;
        $conversion = $processed > 0 ? round(($ourApartments / $processed) * 100, 1) : 0;
        
        return [
            'total' => $total,
            'our_apartments' => $ourApartments,
            'not_picked_up' => $notPickedUp,
            'not_first' => $notFirst,
            'not_answered' => $notAnswered,
            'agent' => $agent,
            'new' => $newCount,
            'conversion' => $conversion,
        ];
    }

    /**
     * Вычислить тренды (изменение в процентах относительно предыдущего периода)
     * 
     * @param array $current Статистика за текущий период
     * @param array $previous Статистика за предыдущий период
     * @return array
     */
    private function calculateTrends(array $current, array $previous): array
    {
        $fields = ['total', 'our_apartments', 'not_picked_up', 'not_first', 'not_answered', 'agent', 'new'];
        $trends = [];
        
        foreach ($fields as $field) {
            $currentVal = $current[$field] ?? 0;
            $prevVal = $previous[$field] ?? 0;
            
            if ($prevVal > 0) {
                // Процентное изменение
                $change = round((($currentVal - $prevVal) / $prevVal) * 100, 1);
            } elseif ($currentVal > 0) {
                // Было 0, стало > 0 — рост 100%
                $change = 100;
            } else {
                // Было 0 и осталось 0
                $change = 0;
            }
            
            $trends[$field] = $change;
        }
        
        // Для конверсии считаем абсолютное изменение (не процент от процента)
        $trends['conversion'] = round(($current['conversion'] ?? 0) - ($previous['conversion'] ?? 0), 1);
        
        return $trends;
    }
}
