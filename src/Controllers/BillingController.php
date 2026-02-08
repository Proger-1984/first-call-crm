<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserSubscription;
use App\Models\SubscriptionHistory;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class BillingController
{
    use ResponseTrait;

    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Проверка прав администратора
     */
    private function checkAdminAccess(Request $request, Response $response): ?Response
    {
        $userRole = $request->getAttribute('userRole', 'user');
        
        if ($userRole !== 'admin') {
            return $this->respondWithError(
                $response,
                'У вас нет доступа к этому ресурсу',
                'access_denied',
                403
            );
        }
        
        return null;
    }

    /**
     * Получение текущих подписок пользователя
     */
    public function getUserSubscriptions(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $filters = $request->getParsedBody() ?: [];

            // Получаем параметры пагинации
            $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
            $perPage = isset($filters['per_page']) ? max(1, (int)$filters['per_page']) : 20;
            $offset = ($page - 1) * $perPage;
            
            // Строим базовый запрос с необходимыми связями
            $query = UserSubscription::with(['user', 'tariff', 'category', 'location']);
            $query->where('user_id', $userId);

            // Фильтр по ID подписки
            if (!empty($filters['filters']['subscription_id'])) {
                $query->where('id', (int)$filters['filters']['subscription_id']);
            }

            // Фильтр по статусу
            if (!empty($filters['filters']['status'])) {
                $query->whereIn('status', $filters['filters']['status']);
            }

            // Фильтр по дате создания подписки
            if (!empty($filters['filters']['created_at'])) {
                if (!empty($filters['filters']['created_at']['from'])) {
                    $fromDate = $this->convertToIsoDate($filters['filters']['created_at']['from']);
                    $query->whereDate('user_subscriptions.created_at', '>=', $fromDate);
                }
                if (!empty($filters['filters']['created_at']['to'])) {
                    $toDate = $this->convertToIsoDate($filters['filters']['created_at']['to']);
                    $query->whereDate('user_subscriptions.created_at', '<=', $toDate);
                }
            }

            // Сортировка
            if (!empty($filters['sorting'])) {
                $sortField = array_key_first($filters['sorting']);
                $sortDir = $filters['sorting'][$sortField];
                
                // Маппинг полей для сортировки по связанным таблицам
                if ($sortField === 'category_name') {
                    $query->join('categories', 'user_subscriptions.category_id', '=', 'categories.id')
                          ->orderBy('categories.name', $sortDir)
                          ->select('user_subscriptions.*');
                } elseif ($sortField === 'location_name') {
                    $query->leftJoin('locations', 'user_subscriptions.location_id', '=', 'locations.id')
                          ->orderBy('locations.city', $sortDir)
                          ->select('user_subscriptions.*');
                } elseif ($sortField === 'days_left') {
                    // Сортируем по дате окончания (чем раньше заканчивается, тем меньше осталось)
                    $query->orderBy('user_subscriptions.end_date', $sortDir);
                } else {
                    // Для стандартных полей добавляем префикс таблицы
                    $query->orderBy('user_subscriptions.' . $sortField, $sortDir);
                }
            } else {
                $query->orderBy('user_subscriptions.created_at', 'desc');
            }
            
            // Получаем общее количество записей
            $total = $query->count();
            
            // Получаем данные с учетом пагинации
            $subscriptions = $query->skip($offset)
                                  ->take($perPage)
                                  ->get();
            
            // Трансформируем данные для вывода
            $items = $subscriptions->map(function (UserSubscription $subscription) {
                $daysLeft = '0 дн.';
                if ($subscription->status === 'active' && $subscription->end_date) {
                    $now = Carbon::now();
                    $endDate = $subscription->end_date;
                    if ($now->lessThan($endDate)) {
                        $diffInDays = $now->diffInDays($endDate);
                        if ($diffInDays > 0) {
                            $daysLeft = $diffInDays . ' дн.';
                        } else {
                            // Если меньше суток, показываем часы
                            $diffInHours = $now->diffInHours($endDate);
                            if ($diffInHours > 0) {
                                $daysLeft = $diffInHours . ' ч.';
                            } else {
                                // Если меньше часа, показываем минуты
                                $diffInMinutes = $now->diffInMinutes($endDate);
                                $daysLeft = $diffInMinutes . ' мин.';
                            }
                        }
                    }
                }
                
                // Формируем название локации
                $locationName = $subscription->location?->city ?? 'Не указан';
                if ($subscription->location?->region && $subscription->location->region !== $subscription->location->city) {
                    $locationName .= ' и ' . $subscription->location->region;
                }
                
                return [
                    'id' => $subscription->id,
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                    'tariff_name' => $subscription->tariff->name,
                    'category_name' => $subscription->category->name,
                    'location_name' => $locationName,
                    'status' => $subscription->status,
                    'days_left' => $daysLeft,
                    'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                    'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                ];
            });
            
            // Формируем ответ с пагинацией по стандарту
            return $this->respondWithData($response, [
                'meta' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => ceil($total / $perPage),
                    'from' => $total ? $offset + 1 : 0,
                    'to' => min($offset + $perPage, $total)
                ],
                'data' => $items
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка при получении данных: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Получение текущих подписок (административная панель)
     * Доступно только администраторам
     */
    public function getCurrentSubscriptions(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $filters = $request->getParsedBody() ?: [];
            
            // Получаем параметры пагинации
            $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
            $perPage = isset($filters['per_page']) ? max(1, (int)$filters['per_page']) : 20;
            $offset = ($page - 1) * $perPage;
            
            // Строим базовый запрос с дополнительными связями
            $query = UserSubscription::with(['user', 'tariff', 'category', 'location']);

            // Фильтр по дате создания подписки
            if (!empty($filters['filters']['created_at'])) {
                if (!empty($filters['filters']['created_at']['from'])) {
                    $fromDate = $this->convertToIsoDate($filters['filters']['created_at']['from']);
                    $query->whereDate('created_at', '>=', $fromDate);
                }
                if (!empty($filters['filters']['created_at']['to'])) {
                    $toDate = $this->convertToIsoDate($filters['filters']['created_at']['to']);
                    $query->whereDate('created_at', '<=', $toDate);
                }
            }

            // Фильтр по ID подписки
            if (!empty($filters['filters']['subscription_id'])) {
                $query->where('id', (int)$filters['filters']['subscription_id']);
            }

            // Фильтр по ID пользователя
            if (!empty($filters['filters']['user_id'])) {
                $query->where('user_id', (int)$filters['filters']['user_id']);
            }

            // Фильтр по тарифу
            if (!empty($filters['filters']['tariff_id'])) {
                $query->where('tariff_id', (int)$filters['filters']['tariff_id']);
            }

            // Фильтр по статусу
            if (!empty($filters['filters']['status'])) {
                $query->whereIn('status', $filters['filters']['status']);
            }

            // Фильтр по остатку дней
            if (!empty($filters['filters']['days_left_min']) || !empty($filters['filters']['days_left_max'])) {
                $now = Carbon::now();

                if (!empty($filters['filters']['days_left_min'])) {
                    $minDaysDate = $now->copy()->addDays((int)$filters['filters']['days_left_min']);
                    $query->where('end_date', '>=', $minDaysDate);
                }

                if (!empty($filters['filters']['days_left_max'])) {
                    $maxDaysDate = $now->copy()->addDays((int)$filters['filters']['days_left_max']);
                    $query->where('end_date', '<=', $maxDaysDate);
                }
            }

            // Сортировка
            if (!empty($filters['sorting'])) {
                $sortField = array_key_first($filters['sorting']);
                $sortDir = $filters['sorting'][$sortField];
                
                // Маппинг полей для сортировки
                if ($sortField === 'days_left') {
                    // Сортируем по дате окончания (чем раньше заканчивается, тем меньше осталось)
                    $query->orderBy('end_date', $sortDir);
                } else {
                    $query->orderBy($sortField, $sortDir);
                }
            } else {
                $query->orderBy('created_at', 'desc');
            }
            
            // Получаем общее количество записей
            $total = $query->count();
            
            // Получаем данные с учетом пагинации
            $subscriptions = $query->skip($offset)
                                  ->take($perPage)
                                  ->get();
            
            // Трансформируем данные для вывода
            $items = $subscriptions->map(function (UserSubscription $subscription) {
                $daysLeft = '0 дн.';
                if ($subscription->status === 'active' && $subscription->end_date) {
                    $now = Carbon::now();
                    $endDate = $subscription->end_date;
                    if ($now->lessThan($endDate)) {
                        $diffInDays = $now->diffInDays($endDate);
                        if ($diffInDays > 0) {
                            $daysLeft = $diffInDays . ' дн.';
                        } else {
                            // Если меньше суток, показываем часы
                            $diffInHours = $now->diffInHours($endDate);
                            if ($diffInHours > 0) {
                                $daysLeft = $diffInHours . ' ч.';
                            } else {
                                // Если меньше часа, показываем минуты
                                $diffInMinutes = $now->diffInMinutes($endDate);
                                $daysLeft = $diffInMinutes . ' мин.';
                            }
                        }
                    }
                }

                return [
                    'id' => $subscription->id,
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                    'tariff_info' => sprintf('%s, %s, %s',
                        str_starts_with($subscription->tariff->name, 'Премиум') ? 'Премиум' : $subscription->tariff->name,
                        $subscription->category->name,
                        sprintf('%s и %s',
                            $subscription->location?->city ?? 'Не указан',
                            $subscription->location?->region ?? ''),
                    ),
                    'telegram' => $subscription->user->telegram_username ?? 'Не указан',
                    'status' => $subscription->status,
                    'payment_method' => $subscription->payment_method,
                    'admin_notes' => $subscription->admin_notes,
                    'days_left' => $daysLeft,
                    'start_date' => $subscription->start_date?->format('Y-m-d H:i:s'),
                    'end_date' => $subscription->end_date?->format('Y-m-d H:i:s'),
                    'user_id' => $subscription->user->id,
                    'price_paid' => $subscription->price_paid,
                ];
            });
            
            // Рассчитываем информацию о пагинации
            $totalPages = ceil($total / $perPage);
            
            return $this->respondWithData($response, [
                'meta' => [
                    'total' => $total,            // Общее количество записей
                    'per_page' => $perPage,       // Количество записей на странице
                    'current_page' => $page,      // Текущая страница
                    'total_pages' => $totalPages, // Всего страниц
                    'from' => $total ? $offset + 1 : 0, // Начальная запись на странице
                    'to' => min($offset + $perPage, $total) // Конечная запись на странице
                ],
                'data' => $items
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка при получении данных: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Получение истории подписок (административная панель)
     * Доступно только администраторам
     */
    public function getSubscriptionHistory(Request $request, Response $response): Response
    {
        // Проверка прав администратора
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }
        
        try {
            $filters = $request->getParsedBody() ?: [];
            
            // Получаем параметры пагинации
            $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
            $perPage = isset($filters['per_page']) ? max(1, (int)$filters['per_page']) : 20;
            $offset = ($page - 1) * $perPage;
            
            // Строим базовый запрос
            $query = SubscriptionHistory::with(['user']);
            
            // Фильтр по ID подписки
            if (!empty($filters['filters']['subscription_id'])) {
                $query->where('subscription_id', (int)$filters['filters']['subscription_id']);
            }
            
            // Фильтр по ID пользователя
            if (!empty($filters['filters']['user_id'])) {
                $query->where('user_id', (int)$filters['filters']['user_id']);
            }
            
            // Фильтр по типу действия
            if (!empty($filters['filters']['action'])) {
                $query->whereIn('action', $filters['filters']['action']);
            }
            
            // Фильтр по дате действия
            if (!empty($filters['filters']['action_date'])) {
                if (!empty($filters['filters']['action_date']['from'])) {
                    $fromDate = $this->convertToIsoDate($filters['filters']['action_date']['from']);
                    $query->whereDate('action_date', '>=', $fromDate);
                }
                if (!empty($filters['filters']['action_date']['to'])) {
                    $toDate = $this->convertToIsoDate($filters['filters']['action_date']['to']);
                    $query->whereDate('action_date', '<=', $toDate);
                }
            }

            // Сортировка
            if (!empty($filters['sorting'])) {
                $sortField = array_key_first($filters['sorting']);
                $sortDir = $filters['sorting'][$sortField];
                
                // Маппинг полей для сортировки
                if ($sortField === 'price') {
                    $query->orderBy('price_paid', $sortDir);
                } else {
                    $query->orderBy($sortField, $sortDir);
                }
            } else {
                $query->orderBy('action_date', 'desc');
            }
            
            // Получаем общее количество записей
            $total = $query->count();
            
            // Получаем данные с учетом пагинации
            $history = $query->skip($offset)
                             ->take($perPage)
                             ->get();
            
            // Трансформируем данные для вывода
            $items = $history->map(function (SubscriptionHistory $record) {
                return [
                    'id' => $record->id,
                    'subscription_id' => $record->subscription_id,
                    'user_id' => $record->user->id,
                    'tariff_info' => sprintf('%s, %s, %s',
                        str_starts_with($record->tariff_name, 'Премиум') ? 'Премиум' : $record->tariff_name,
                        $record->category_name,
                        $record->location_name ?? 'Не указан',
                    ),

                    'old_status' => $this->getOldStatus($record->action),
                    'new_status' => $this->getNewStatus($record->action),
                    'price' => $record->price_paid,
                    'action_date' => $record->action_date->format('Y-m-d H:i:s'),
                    'notes' => $record->notes
                ];
            });
            
            // Рассчитываем информацию о пагинации
            $totalPages = ceil($total / $perPage);
            
            return $this->respondWithData($response, [
                'meta' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'from' => $total ? $offset + 1 : 0,
                    'to' => min($offset + $perPage, $total)
                ],
                'data' => $items
            ], 200);
            
        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка при получении данных: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Получение предыдущего статуса на основе действия (человекочитаемый формат)
     */
    private function getOldStatus(string $action): string
    {
        return match($action) {
            'created', 'requested' => 'Отсутствует',
            'activated' => 'Ожидает подтверждения/активации',
            'extended', 'cancelled', 'expired' => 'Активная',
            'extend_requested' => 'Активная (ожидает продления)',
            default => 'Неизвестный статус'
        };
    }
    
    /**
     * Получение нового статуса на основе действия (человекочитаемый формат)
     */
    private function getNewStatus(string $action): string
    {
        return match($action) {
            'created', 'requested' => 'Ожидает подтверждения/активации',
            'extend_requested' => 'Ожидает продления',
            'activated', 'extended' => 'Активная',
            'cancelled' => 'Отменена',
            'expired' => 'Истекла',
            default => 'Неизвестный статус'
        };
    }
    
    /**
     * Преобразование даты из формата DD.MM.YYYY в ISO-формат YYYY-MM-DD
     */
    private function convertToIsoDate(string $date): string
    {
        if (empty($date)) {
            return $date;
        }
        
        // Если формат уже YYYY-MM-DD, возвращаем как есть
        if (str_contains($date, '-')) {
            return $date;
        }
        
        // Разбиваем по точке или слешу
        $dateParts = preg_split('/[.\/]/', $date);
        
        // Если удалось разбить на три части
        if (count($dateParts) === 3) {
            $day = $dateParts[0];
            $month = $dateParts[1];
            $year = $dateParts[2];
            
            // Добавляем ведущие нули для дня и месяца если нужно
            $day = strlen($day) === 1 ? '0' . $day : $day;
            $month = strlen($month) === 1 ? '0' . $month : $month;
            
            // Возвращаем в формате YYYY-MM-DD
            return sprintf('%s-%s-%s', $year, $month, $day);
        }
        
        // Возвращаем исходную дату, если не удалось преобразовать
        return $date;
    }
} 