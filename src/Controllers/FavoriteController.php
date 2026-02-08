<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Listing;
use App\Models\UserFavorite;
use App\Models\UserFavoriteStatus;
use App\Services\ListingFilterService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Контроллер для работы с избранными объявлениями
 */
class FavoriteController
{
    use ResponseTrait;

    public function __construct(
        private ListingFilterService $listingFilterService
    ) {}

    /**
     * Получить список избранных объявлений
     * 
     * GET /api/v1/favorites
     * 
     * Query параметры:
     * - page: номер страницы (default: 1)
     * - per_page: записей на странице (default: 10, max: 100)
     * - order: направление (asc/desc, default: desc)
     * - sort_by: поле сортировки (created_at, source, price, status; default: created_at)
     * - date_from: фильтр по дате от (Y-m-d)
     * - date_to: фильтр по дате до (Y-m-d)
     * - comment: поиск по комментарию (вхождение)
     * - status_id: фильтр по статусу (int или 'none' для без статуса)
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $params = $request->getQueryParams();
            
            $page = max(1, (int)($params['page'] ?? 1));
            $perPage = min(100, max(1, (int)($params['per_page'] ?? 10)));
            $order = strtolower($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $sortBy = $params['sort_by'] ?? 'created_at';
            $allowedSort = ['created_at', 'source', 'price', 'status'];
            if (!in_array($sortBy, $allowedSort, true)) {
                $sortBy = 'created_at';
            }

            // Фильтры
            $dateFrom = $params['date_from'] ?? null;
            $dateTo = $params['date_to'] ?? null;
            $commentSearch = $params['comment'] ?? null;
            $statusFilter = $params['status_id'] ?? null;

            // Получаем избранные объявления с пагинацией
            $query = UserFavorite::where('user_id', $userId)
                ->with(['listing' => function ($q) {
                    $q->with(['source', 'category', 'listingStatus', 'location', 'room', 'metroStations']);
                }, 'status']);
            
            // Фильтр по дате добавления в избранное
            if ($dateFrom) {
                $query->whereDate('user_favorites.created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('user_favorites.created_at', '<=', $dateTo);
            }
            
            // Поиск по комментарию (ILIKE для регистронезависимого поиска)
            if ($commentSearch) {
                $query->where('comment', 'ILIKE', '%' . $commentSearch . '%');
            }
            
            // Фильтр по статусу
            if ($statusFilter !== null) {
                if ($statusFilter === 'none' || $statusFilter === '0') {
                    $query->whereNull('status_id');
                } else {
                    $query->where('status_id', (int)$statusFilter);
                }
            }
            
            // Сортировка
            if ($sortBy === 'price' || $sortBy === 'source') {
                $query->join('listings', 'user_favorites.listing_id', '=', 'listings.id')
                    ->select('user_favorites.*');
                if ($sortBy === 'price') {
                    $query->orderBy('listings.price', $order);
                } else {
                    $query->orderBy('listings.source_id', $order);
                }
            } elseif ($sortBy === 'status') {
                $query->orderByRaw('user_favorites.status_id IS NULL ' . ($order === 'asc' ? 'ASC' : 'DESC'))
                    ->orderBy('user_favorites.status_id', $order);
            } else {
                $query->orderBy('user_favorites.created_at', $order);
            }

            $total = $query->count();
            
            $offset = ($page - 1) * $perPage;
            $favorites = $query->skip($offset)->take($perPage)->get();

            // Форматируем объявления
            $listings = $favorites->map(function ($favorite) {
                $listing = $favorite->listing;
                
                if (!$listing) {
                    return null;
                }

                return $this->formatListingForApi($listing, $favorite->created_at, $favorite->comment, $favorite->status);
            })->filter()->values()->toArray();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listings' => $listings,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => (int)ceil($total / $perPage),
                    ],
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при получении избранного', 'internal_error', 500);
        }
    }

    /**
     * Добавить/удалить из избранного (toggle)
     * 
     * POST /api/v1/favorites/toggle
     * 
     * Body:
     * - listing_id: int - ID объявления
     */
    public function toggle(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = $request->getParsedBody();
            $listingId = (int)($body['listing_id'] ?? 0);

            if ($listingId <= 0) {
                return $this->respondWithError($response, 'Не указан ID объявления', 'validation_error', 400);
            }

            // Проверяем, существует ли объявление
            $listing = Listing::find($listingId);
            if (!$listing) {
                return $this->respondWithError($response, 'Объявление не найдено', 'not_found', 404);
            }

            // Toggle избранного
            $isNowFavorite = UserFavorite::toggle($userId, $listingId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listing_id' => $listingId,
                    'is_favorite' => $isNowFavorite,
                    'message' => $isNowFavorite ? 'Добавлено в избранное' : 'Удалено из избранного',
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при обновлении избранного', 'internal_error', 500);
        }
    }

    /**
     * Проверить, находится ли объявление в избранном
     * 
     * GET /api/v1/favorites/check/{id}
     */
    public function check(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            // Получаем ID из атрибутов запроса (Slim 4 + PHP-DI)
            $listingId = (int)($request->getAttribute('id') ?? 0);

            if ($listingId <= 0) {
                return $this->respondWithError($response, 'Не указан ID объявления', 'validation_error', 400);
            }

            $isFavorite = UserFavorite::isFavorite($userId, $listingId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listing_id' => $listingId,
                    'is_favorite' => $isFavorite,
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при проверке избранного', 'internal_error', 500);
        }
    }

    /**
     * Получить количество избранных
     * 
     * GET /api/v1/favorites/count
     */
    public function count(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $count = UserFavorite::where('user_id', $userId)->count();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'count' => $count,
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при получении количества', 'internal_error', 500);
        }
    }

    /**
     * Обновить комментарий к избранному
     * 
     * PUT /api/v1/favorites/comment
     * 
     * Body:
     * - listing_id: int - ID объявления
     * - comment: string|null - Комментарий (max 250 символов)
     */
    public function updateComment(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = $request->getParsedBody();
            $listingId = (int)($body['listing_id'] ?? 0);
            $comment = $body['comment'] ?? null;

            if ($listingId <= 0) {
                return $this->respondWithError($response, 'Не указан ID объявления', 'validation_error', 400);
            }

            // Валидация длины комментария
            if ($comment !== null && mb_strlen($comment) > 250) {
                return $this->respondWithError($response, 'Комментарий не может быть длиннее 250 символов', 'validation_error', 400);
            }

            // Проверяем, что объявление в избранном
            if (!UserFavorite::isFavorite($userId, $listingId)) {
                return $this->respondWithError($response, 'Объявление не в избранном', 'not_found', 404);
            }

            // Обновляем комментарий
            UserFavorite::updateComment($userId, $listingId, $comment);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listing_id' => $listingId,
                    'comment' => $comment ? mb_substr(trim($comment), 0, 250) : null,
                    'message' => 'Комментарий обновлён',
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при обновлении комментария', 'internal_error', 500);
        }
    }

    /**
     * Обновить статус избранного
     * 
     * PUT /api/v1/favorites/status
     * 
     * Body:
     * - listing_id: int - ID объявления
     * - status_id: int|null - ID статуса (null для удаления статуса)
     */
    public function updateStatus(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            
            if (!$userId) {
                return $this->respondWithError($response, 'Требуется авторизация', 'unauthorized', 401);
            }

            $body = $request->getParsedBody();
            $listingId = (int)($body['listing_id'] ?? 0);
            $statusId = isset($body['status_id']) && $body['status_id'] !== null ? (int)$body['status_id'] : null;

            if ($listingId <= 0) {
                return $this->respondWithError($response, 'Не указан ID объявления', 'validation_error', 400);
            }

            // Проверяем, что объявление в избранном
            if (!UserFavorite::isFavorite($userId, $listingId)) {
                return $this->respondWithError($response, 'Объявление не в избранном', 'not_found', 404);
            }

            // Проверяем, что статус принадлежит пользователю (если указан)
            if ($statusId !== null && !UserFavoriteStatus::belongsToUser($statusId, $userId)) {
                return $this->respondWithError($response, 'Статус не найден', 'not_found', 404);
            }

            // Обновляем статус
            UserFavorite::updateStatus($userId, $listingId, $statusId);

            // Получаем обновлённый статус
            $status = $statusId ? UserFavoriteStatus::find($statusId) : null;

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'listing_id' => $listingId,
                    'status' => $status ? [
                        'id' => $status->id,
                        'name' => $status->name,
                        'color' => $status->color,
                    ] : null,
                    'message' => 'Статус обновлён',
                ],
            ], 200);
            
        } catch (Exception) {
            return $this->respondWithError($response, 'Ошибка при обновлении статуса', 'internal_error', 500);
        }
    }

    /**
     * Форматирование объявления для API
     */
    private function formatListingForApi(Listing $listing, $favoriteCreatedAt, ?string $comment = null, ?UserFavoriteStatus $status = null): array
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
            'is_paid' => $listing->is_paid,
            'created_at' => $listing->created_at?->toIso8601String(),
            'favorited_at' => $favoriteCreatedAt?->toIso8601String(),
            'is_favorite' => true, // Всегда true в списке избранного
            'comment' => $comment,
            'status' => $status ? [
                'id' => $status->id,
                'name' => $status->name,
                'color' => $status->color,
            ] : null,
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

        // Статус объявления
        if ($listing->relationLoaded('listingStatus') && $listing->listingStatus) {
            $formatted['listing_status'] = [
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
