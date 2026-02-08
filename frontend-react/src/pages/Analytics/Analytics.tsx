import { useState, useEffect, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
  BarChart,
  Bar,
} from 'recharts';
import { analyticsApi, type AnalyticsChartDataPoint } from '../../services/api';
import { DatePicker } from '../../components/UI/DatePicker';
import './Analytics.css';

type PeriodType = 'week' | 'month' | 'quarter' | 'year' | 'custom';
type ChartType = 'revenue' | 'users' | 'subscriptions' | 'all';
type ChartView = 'line' | 'bar';

// Форматирование числа с разделителями
function formatNumber(num: number): string {
  return new Intl.NumberFormat('ru-RU').format(num);
}

// Форматирование цены
function formatPrice(price: number): string {
  return formatNumber(price) + ' ₽';
}

// Форматирование даты для фильтра
function formatDateForFilter(dateStr: string): string {
  if (!dateStr) return '';
  if (dateStr.includes('.')) return dateStr;
  const date = new Date(dateStr);
  if (isNaN(date.getTime())) return dateStr;
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  return `${day}.${month}.${year}`;
}

// Вычисление дат для периода
function calculateDatesForPeriod(periodType: PeriodType): { from: string; to: string } {
  const now = new Date();
  const toDate = new Date(now);
  let fromDate = new Date(now);
  
  switch (periodType) {
    case 'week':
      fromDate.setDate(now.getDate() - 7);
      break;
    case 'month':
      fromDate.setDate(now.getDate() - 30);
      break;
    case 'quarter':
      fromDate.setDate(now.getDate() - 90);
      break;
    case 'year':
      fromDate.setFullYear(now.getFullYear() - 1);
      break;
  }
  
  // Форматируем в DD.MM.YYYY (формат DatePicker/flatpickr)
  const formatDate = (d: Date) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${day}.${month}.${year}`;
  };
  
  return { from: formatDate(fromDate), to: formatDate(toDate) };
}

export function Analytics() {
  const [period, setPeriod] = useState<PeriodType>('week');
  const [chartType, setChartType] = useState<ChartType>('all');
  const [chartView, setChartView] = useState<ChartView>('line');
  const [appliedDates, setAppliedDates] = useState<{ from: string; to: string }>({ from: '', to: '' });
  
  // Вычисляем даты для текущего периода
  const periodDates = useMemo(() => calculateDatesForPeriod(period), [period]);
  
  // Даты в полях ввода (либо от периода, либо пользовательские)
  const [dateFrom, setDateFrom] = useState(periodDates.from);
  const [dateTo, setDateTo] = useState(periodDates.to);
  
  // Обновляем поля дат при смене периода
  useEffect(() => {
    if (period !== 'custom') {
      setDateFrom(periodDates.from);
      setDateTo(periodDates.to);
    }
  }, [period, periodDates]);

  // Запрос данных для графиков
  const chartsQuery = useQuery({
    queryKey: ['analytics-charts', period, appliedDates],
    queryFn: () => {
      if (period === 'custom' && appliedDates.from && appliedDates.to) {
        return analyticsApi.getChartsData({
          date_from: formatDateForFilter(appliedDates.from),
          date_to: formatDateForFilter(appliedDates.to),
        });
      }
      
      return analyticsApi.getChartsData({
        period: period === 'custom' ? 'week' : period,
      });
    },
    staleTime: 60000, // 1 минута
  });

  // Запрос сводной статистики
  const summaryQuery = useQuery({
    queryKey: ['analytics-summary'],
    queryFn: () => analyticsApi.getSummary(),
    staleTime: 60000,
  });

  // Парсинг данных из API ответа
  const chartsResponse = chartsQuery.data?.data as any;
  const chartData = chartsResponse?.data?.chart_data || chartsResponse?.chart_data || [];
  const totals = chartsResponse?.data?.totals || chartsResponse?.totals || { revenue: 0, users: 0, subscriptions: 0 };
  
  const summaryResponse = summaryQuery.data?.data as any;
  const summary = summaryResponse?.data || summaryResponse;

  const handleApplyCustomDates = () => {
    if (dateFrom && dateTo) {
      setAppliedDates({ from: dateFrom, to: dateTo });
      setPeriod('custom');
    }
  };

  const handlePeriodChange = (newPeriod: PeriodType) => {
    setPeriod(newPeriod);
    setAppliedDates({ from: '', to: '' });
  };

  // Кастомный тултип
  const CustomTooltip = ({ active, payload, label }: any) => {
    if (active && payload && payload.length) {
      return (
        <div className="analytics-tooltip">
          <p className="analytics-tooltip-label">{label}</p>
          {payload.map((entry: any, index: number) => (
            <p key={index} style={{ color: entry.color }}>
              {entry.name}: {entry.name === 'Доход' ? formatPrice(entry.value) : formatNumber(entry.value)}
            </p>
          ))}
        </div>
      );
    }
    return null;
  };

  // Рендер графика
  const renderChart = () => {
    if (chartsQuery.isLoading) {
      return (
        <div className="analytics-chart-loading">
          <span className="material-icons spinning">sync</span>
          <p>Загрузка данных...</p>
        </div>
      );
    }

    if (chartData.length === 0) {
      return (
        <div className="analytics-chart-empty">
          <span className="material-icons">show_chart</span>
          <p>Нет данных за выбранный период</p>
        </div>
      );
    }

    const showRevenue = chartType === 'all' || chartType === 'revenue';
    const showUsers = chartType === 'all' || chartType === 'users';
    const showSubscriptions = chartType === 'all' || chartType === 'subscriptions';

    if (chartView === 'bar') {
      return (
        <ResponsiveContainer width="100%" height={400}>
          <BarChart data={chartData} margin={{ top: 20, right: 30, left: 20, bottom: 5 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
            <XAxis dataKey="label" stroke="#64748b" fontSize={12} />
            <YAxis yAxisId="left" stroke="#64748b" fontSize={12} />
            {showRevenue && <YAxis yAxisId="right" orientation="right" stroke="#16a34a" fontSize={12} />}
            <Tooltip content={<CustomTooltip />} />
            <Legend />
            {showRevenue && (
              <Bar yAxisId="right" dataKey="revenue" name="Доход" fill="#16a34a" radius={[4, 4, 0, 0]} />
            )}
            {showUsers && (
              <Bar yAxisId="left" dataKey="users" name="Пользователи" fill="#3b82f6" radius={[4, 4, 0, 0]} />
            )}
            {showSubscriptions && (
              <Bar yAxisId="left" dataKey="subscriptions" name="Подписки" fill="#f59e0b" radius={[4, 4, 0, 0]} />
            )}
          </BarChart>
        </ResponsiveContainer>
      );
    }

    return (
      <ResponsiveContainer width="100%" height={400}>
        <LineChart data={chartData} margin={{ top: 20, right: 30, left: 20, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
          <XAxis dataKey="label" stroke="#64748b" fontSize={12} />
          <YAxis yAxisId="left" stroke="#64748b" fontSize={12} />
          {showRevenue && <YAxis yAxisId="right" orientation="right" stroke="#16a34a" fontSize={12} />}
          <Tooltip content={<CustomTooltip />} />
          <Legend />
          {showRevenue && (
            <Line
              yAxisId="right"
              type="monotone"
              dataKey="revenue"
              name="Доход"
              stroke="#16a34a"
              strokeWidth={2}
              dot={{ fill: '#16a34a', strokeWidth: 2, r: 4 }}
              activeDot={{ r: 6 }}
            />
          )}
          {showUsers && (
            <Line
              yAxisId="left"
              type="monotone"
              dataKey="users"
              name="Пользователи"
              stroke="#3b82f6"
              strokeWidth={2}
              dot={{ fill: '#3b82f6', strokeWidth: 2, r: 4 }}
              activeDot={{ r: 6 }}
            />
          )}
          {showSubscriptions && (
            <Line
              yAxisId="left"
              type="monotone"
              dataKey="subscriptions"
              name="Подписки"
              stroke="#f59e0b"
              strokeWidth={2}
              dot={{ fill: '#f59e0b', strokeWidth: 2, r: 4 }}
              activeDot={{ r: 6 }}
            />
          )}
        </LineChart>
      </ResponsiveContainer>
    );
  };

  return (
    <div className="analytics-page">
      <div className="analytics-header">
        <h1>
          <span className="material-icons">analytics</span>
          Аналитика
        </h1>
      </div>

      {/* Сводная статистика */}
      {summary && (
        <div className="analytics-summary">
          <div className="analytics-summary-card revenue">
            <div className="analytics-summary-icon">
              <span className="material-icons">payments</span>
            </div>
            <div className="analytics-summary-content">
              <h3>Доход</h3>
              <div className="analytics-summary-values">
                <div className="analytics-summary-item">
                  <span className="label">Сегодня</span>
                  <span className="value">{formatPrice(summary.revenue.today)}</span>
                </div>
                <div className="analytics-summary-item">
                  <span className="label">Неделя</span>
                  <span className="value">{formatPrice(summary.revenue.week)}</span>
                </div>
                <div className="analytics-summary-item">
                  <span className="label">Месяц</span>
                  <span className="value">{formatPrice(summary.revenue.month)}</span>
                </div>
              </div>
            </div>
          </div>

          <div className="analytics-summary-card users">
            <div className="analytics-summary-icon">
              <span className="material-icons">people</span>
            </div>
            <div className="analytics-summary-content">
              <h3>Пользователи</h3>
              <div className="analytics-summary-values">
                <div className="analytics-summary-item">
                  <span className="label">Сегодня</span>
                  <span className="value">+{formatNumber(summary.users.today)}</span>
                </div>
                <div className="analytics-summary-item">
                  <span className="label">Неделя</span>
                  <span className="value">+{formatNumber(summary.users.week)}</span>
                </div>
                <div className="analytics-summary-item">
                  <span className="label">Всего</span>
                  <span className="value">{formatNumber(summary.users.total)}</span>
                </div>
              </div>
            </div>
          </div>

          <div className="analytics-summary-card subscriptions">
            <div className="analytics-summary-icon">
              <span className="material-icons">card_membership</span>
            </div>
            <div className="analytics-summary-content">
              <h3>Подписки</h3>
              <div className="analytics-summary-values">
                <div className="analytics-summary-item">
                  <span className="label">Сегодня</span>
                  <span className="value">+{formatNumber(summary.subscriptions.today)}</span>
                </div>
                <div className="analytics-summary-item">
                  <span className="label">Неделя</span>
                  <span className="value">+{formatNumber(summary.subscriptions.week)}</span>
                </div>
                <div className="analytics-summary-item">
                  <span className="label">Активных</span>
                  <span className="value">{formatNumber(summary.subscriptions.active)}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Фильтры графика */}
      <div className="analytics-filters">
        <div className="analytics-filters-row">
          <div className="analytics-filter-group">
            <label>Период</label>
            <div className="analytics-period-buttons">
              <button className={`analytics-period-btn ${period === 'week' ? 'active' : ''}`} onClick={() => handlePeriodChange('week')}>Неделя</button>
              <button className={`analytics-period-btn ${period === 'month' ? 'active' : ''}`} onClick={() => handlePeriodChange('month')}>Месяц</button>
              <button className={`analytics-period-btn ${period === 'quarter' ? 'active' : ''}`} onClick={() => handlePeriodChange('quarter')}>Квартал</button>
              <button className={`analytics-period-btn ${period === 'year' ? 'active' : ''}`} onClick={() => handlePeriodChange('year')}>Год</button>
            </div>
          </div>

          <div className="analytics-filter-group">
            <label>Произвольный период</label>
            <div className="analytics-custom-dates">
              <DatePicker value={dateFrom} onChange={setDateFrom} placeholder="От" />
              <span className="analytics-date-sep">—</span>
              <DatePicker value={dateTo} onChange={setDateTo} placeholder="До" />
            </div>
          </div>

          <button className="analytics-apply-btn" onClick={handleApplyCustomDates} disabled={!dateFrom || !dateTo}>Применить</button>

          <div className="analytics-filter-group">
            <label>Показать</label>
            <div className="analytics-chart-type-buttons">
              <button className={`analytics-type-btn ${chartType === 'all' ? 'active' : ''}`} onClick={() => setChartType('all')}>Все</button>
              <button className={`analytics-type-btn revenue ${chartType === 'revenue' ? 'active' : ''}`} onClick={() => setChartType('revenue')}>₽</button>
              <button className={`analytics-type-btn users ${chartType === 'users' ? 'active' : ''}`} onClick={() => setChartType('users')}>Юзеры</button>
              <button className={`analytics-type-btn subscriptions ${chartType === 'subscriptions' ? 'active' : ''}`} onClick={() => setChartType('subscriptions')}>Подп.</button>
            </div>
          </div>

          <div className="analytics-filter-group">
            <label>Вид</label>
            <div className="analytics-view-buttons">
              <button className={`analytics-view-btn ${chartView === 'line' ? 'active' : ''}`} onClick={() => setChartView('line')} title="Линейный график">
                <span className="material-icons">show_chart</span>
              </button>
              <button className={`analytics-view-btn ${chartView === 'bar' ? 'active' : ''}`} onClick={() => setChartView('bar')} title="Столбчатая диаграмма">
                <span className="material-icons">bar_chart</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Итоги за период */}
      <div className="analytics-totals">
        <div className="analytics-total-item revenue">
          <span className="analytics-total-label">Доход за период:</span>
          <span className="analytics-total-value">{formatPrice(totals.revenue)}</span>
        </div>
        <div className="analytics-total-item users">
          <span className="analytics-total-label">Новых пользователей:</span>
          <span className="analytics-total-value">{formatNumber(totals.users)}</span>
        </div>
        <div className="analytics-total-item subscriptions">
          <span className="analytics-total-label">Новых подписок:</span>
          <span className="analytics-total-value">{formatNumber(totals.subscriptions)}</span>
        </div>
      </div>

      {/* График */}
      <div className="analytics-chart-container">
        {renderChart()}
      </div>
    </div>
  );
}
