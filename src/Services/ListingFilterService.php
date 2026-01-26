<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Listing;
use App\Models\UserLocationPolygon;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Сервис для фильтрации объявлений с учётом полигонов пользователя
 * 
 * Реализует гибридный подход:
 * - Если у подписки есть полигоны — возвращаем только объявления внутри них
 * - Если полигонов нет — возвращаем все объявления в локации подписки
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
     * @return \Illuminate\Database\Eloquent\Builder
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
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $subscriptionId
     * @return \Illuminate\Database\Eloquent\Builder
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
}
