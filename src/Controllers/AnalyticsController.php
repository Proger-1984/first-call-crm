<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\SubscriptionHistory;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

/**
 * Контроллер аналитики
 * Доступен только администраторам
 */
class AnalyticsController
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
            return $this->respondWithError($response, 'Доступ запрещён', 'forbidden', 403);
        }
        
        return null;
    }

    /**
     * Получение данных для графиков аналитики
     * POST /api/v1/admin/analytics/charts
     * 
     * Параметры:
     * - period: 'week' | 'month' | 'quarter' | 'year' (по умолчанию 'week')
     * - date_from: дата начала (опционально)
     * - date_to: дата окончания (опционально)
     */
    public function getChartsData(Request $request, Response $response): Response
    {
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }

        try {
            $params = $request->getParsedBody() ?: [];
            
            // Определяем период
            $period = $params['period'] ?? 'week';
            $now = Carbon::now();
            
            // Вычисляем даты на основе периода
            if (!empty($params['date_from']) && !empty($params['date_to'])) {
                $dateFrom = Carbon::parse($this->convertToIsoDate($params['date_from']))->startOfDay();
                $dateTo = Carbon::parse($this->convertToIsoDate($params['date_to']))->endOfDay();
            } else {
                switch ($period) {
                    case 'month':
                        $dateFrom = $now->copy()->subMonth()->startOfDay();
                        break;
                    case 'quarter':
                        $dateFrom = $now->copy()->subMonths(3)->startOfDay();
                        break;
                    case 'year':
                        $dateFrom = $now->copy()->subYear()->startOfDay();
                        break;
                    case 'week':
                    default:
                        $dateFrom = $now->copy()->subWeek()->startOfDay();
                        break;
                }
                $dateTo = $now->copy()->endOfDay();
            }

            // Определяем группировку (по дням или месяцам)
            $daysDiff = $dateFrom->diffInDays($dateTo);
            $groupBy = $daysDiff > 60 ? 'month' : 'day';

            // Получаем данные о доходе
            $revenueData = $this->getRevenueData($dateFrom, $dateTo, $groupBy);
            
            // Получаем данные о новых пользователях
            $usersData = $this->getUsersData($dateFrom, $dateTo, $groupBy);
            
            // Получаем данные о новых подписках
            $subscriptionsData = $this->getSubscriptionsData($dateFrom, $dateTo, $groupBy);

            // Формируем единый массив дат для графика
            $chartData = $this->mergeChartData($dateFrom, $dateTo, $groupBy, $revenueData, $usersData, $subscriptionsData);

            // Считаем итоги
            $totals = [
                'revenue' => array_sum(array_column($revenueData, 'value')),
                'users' => array_sum(array_column($usersData, 'value')),
                'subscriptions' => array_sum(array_column($subscriptionsData, 'value')),
            ];

            return $this->respondWithData($response, [
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                    'group_by' => $groupBy,
                ],
                'chart_data' => $chartData,
                'totals' => $totals,
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка получения аналитики: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Получение сводной статистики
     * GET /api/v1/admin/analytics/summary
     */
    public function getSummary(Request $request, Response $response): Response
    {
        if ($adminCheck = $this->checkAdminAccess($request, $response)) {
            return $adminCheck;
        }

        try {
            $now = Carbon::now();
            $todayStart = $now->copy()->startOfDay();
            $weekStart = $now->copy()->subWeek()->startOfDay();
            $monthStart = $now->copy()->subMonth()->startOfDay();

            // Получаем ID админов для исключения из статистики
            $adminIds = User::where('role', 'admin')->pluck('id')->toArray();

            // Доход (исключаем подписки админов)
            $revenueToday = SubscriptionHistory::whereIn('action', ['activated', 'extended'])
                ->whereDate('action_date', $todayStart)
                ->whereNotIn('user_id', $adminIds)
                ->sum('price_paid');
            
            $revenueWeek = SubscriptionHistory::whereIn('action', ['activated', 'extended'])
                ->where('action_date', '>=', $weekStart)
                ->whereNotIn('user_id', $adminIds)
                ->sum('price_paid');
            
            $revenueMonth = SubscriptionHistory::whereIn('action', ['activated', 'extended'])
                ->where('action_date', '>=', $monthStart)
                ->whereNotIn('user_id', $adminIds)
                ->sum('price_paid');

            // Пользователи (исключаем админов)
            $usersToday = User::whereDate('created_at', $todayStart)->where('role', '!=', 'admin')->count();
            $usersWeek = User::where('created_at', '>=', $weekStart)->where('role', '!=', 'admin')->count();
            $usersMonth = User::where('created_at', '>=', $monthStart)->where('role', '!=', 'admin')->count();
            $usersTotal = User::where('role', '!=', 'admin')->count();

            // Подписки (только активные, исключаем подписки админов)
            $subscriptionsToday = UserSubscription::where('status', 'active')
                ->whereDate('created_at', $todayStart)
                ->whereNotIn('user_id', $adminIds)
                ->count();
            $subscriptionsWeek = UserSubscription::where('status', 'active')
                ->where('created_at', '>=', $weekStart)
                ->whereNotIn('user_id', $adminIds)
                ->count();
            $subscriptionsMonth = UserSubscription::where('status', 'active')
                ->where('created_at', '>=', $monthStart)
                ->whereNotIn('user_id', $adminIds)
                ->count();
            $subscriptionsActive = UserSubscription::where('status', 'active')
                ->whereNotIn('user_id', $adminIds)
                ->count();

            return $this->respondWithData($response, [
                'revenue' => [
                    'today' => (float) $revenueToday,
                    'week' => (float) $revenueWeek,
                    'month' => (float) $revenueMonth,
                ],
                'users' => [
                    'today' => $usersToday,
                    'week' => $usersWeek,
                    'month' => $usersMonth,
                    'total' => $usersTotal,
                ],
                'subscriptions' => [
                    'today' => $subscriptionsToday,
                    'week' => $subscriptionsWeek,
                    'month' => $subscriptionsMonth,
                    'active' => $subscriptionsActive,
                ],
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка получения статистики: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Получение данных о доходе по периодам (исключая админов)
     */
    private function getRevenueData(Carbon $dateFrom, Carbon $dateTo, string $groupBy): array
    {
        // PostgreSQL использует TO_CHAR вместо DATE_FORMAT
        $format = $groupBy === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD';
        
        // Получаем ID админов для исключения
        $adminIds = User::where('role', 'admin')->pluck('id')->toArray();
        
        $data = SubscriptionHistory::selectRaw("TO_CHAR(action_date, '{$format}') as date, SUM(price_paid) as value")
            ->whereIn('action', ['activated', 'extended'])
            ->whereBetween('action_date', [$dateFrom, $dateTo])
            ->whereNotIn('user_id', $adminIds)
            ->groupByRaw("TO_CHAR(action_date, '{$format}')")
            ->orderByRaw("TO_CHAR(action_date, '{$format}')")
            ->get()
            ->keyBy('date')
            ->toArray();

        return $data;
    }

    /**
     * Получение данных о новых пользователях по периодам (исключая админов)
     */
    private function getUsersData(Carbon $dateFrom, Carbon $dateTo, string $groupBy): array
    {
        $format = $groupBy === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD';
        
        $data = User::selectRaw("TO_CHAR(created_at, '{$format}') as date, COUNT(*) as value")
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('role', '!=', 'admin')
            ->groupByRaw("TO_CHAR(created_at, '{$format}')")
            ->orderByRaw("TO_CHAR(created_at, '{$format}')")
            ->get()
            ->keyBy('date')
            ->toArray();

        return $data;
    }

    /**
     * Получение данных о новых АКТИВНЫХ подписках по периодам (исключая админов)
     */
    private function getSubscriptionsData(Carbon $dateFrom, Carbon $dateTo, string $groupBy): array
    {
        $format = $groupBy === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD';
        
        // Получаем ID админов для исключения
        $adminIds = User::where('role', 'admin')->pluck('id')->toArray();
        
        $data = UserSubscription::selectRaw("TO_CHAR(created_at, '{$format}') as date, COUNT(*) as value")
            ->where('status', 'active') // Только активные подписки
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotIn('user_id', $adminIds)
            ->groupByRaw("TO_CHAR(created_at, '{$format}')")
            ->orderByRaw("TO_CHAR(created_at, '{$format}')")
            ->get()
            ->keyBy('date')
            ->toArray();

        return $data;
    }

    /**
     * Объединение данных для графика с заполнением пустых дат
     */
    private function mergeChartData(
        Carbon $dateFrom, 
        Carbon $dateTo, 
        string $groupBy,
        array $revenueData, 
        array $usersData, 
        array $subscriptionsData
    ): array {
        $result = [];
        
        // Русские названия месяцев
        $russianMonths = [
            1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
            5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
            9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек'
        ];
        
        if ($groupBy === 'month') {
            $period = CarbonPeriod::create($dateFrom->startOfMonth(), '1 month', $dateTo->endOfMonth());
            $format = 'Y-m';
        } else {
            $period = CarbonPeriod::create($dateFrom, '1 day', $dateTo);
            $format = 'Y-m-d';
        }

        foreach ($period as $date) {
            $key = $date->format($format);
            
            // Формируем русскую метку
            if ($groupBy === 'month') {
                $label = $russianMonths[(int)$date->format('n')] . ' ' . $date->format('Y');
            } else {
                $label = $date->format('d') . ' ' . $russianMonths[(int)$date->format('n')];
            }
            
            $result[] = [
                'date' => $key,
                'label' => $label,
                'revenue' => isset($revenueData[$key]) ? (float) $revenueData[$key]['value'] : 0,
                'users' => isset($usersData[$key]) ? (int) $usersData[$key]['value'] : 0,
                'subscriptions' => isset($subscriptionsData[$key]) ? (int) $subscriptionsData[$key]['value'] : 0,
            ];
        }

        return $result;
    }

    /**
     * Конвертация даты из DD.MM.YYYY в ISO формат
     */
    private function convertToIsoDate(string $date): string
    {
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
            $parts = explode('.', $date);
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return $date;
    }
}
