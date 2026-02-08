import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { billingApi, subscriptionsApi, type BillingFilters } from '../../services/api';
import { DatePicker } from '../../components/UI/DatePicker';
import { Tooltip } from '../../components/UI/Tooltip';
import { Pagination } from '../../components/UI/Pagination';
import './Billing.css';

// Тип подписки для биллинга (обновлённый)
interface BillingSubscriptionItem {
  id: number;
  created_at: string;
  tariff_id: number;
  tariff_name: string;
  category_name: string;
  location_name: string;
  status: string;
  days_left: string;
  start_date: string | null;
  end_date: string | null;
}

// Статусы подписок с человекочитаемыми названиями и цветами
const STATUS_MAP: Record<string, { label: string; color: string }> = {
  active: { label: 'Активна', color: '#16a34a' },
  pending: { label: 'Ожидает оплаты', color: '#ea580c' },
  extend_pending: { label: 'Ожидает продления', color: '#2563eb' },
  expired: { label: 'Истекла', color: '#dc2626' },
  cancelled: { label: 'Отменена', color: '#4b5563' },
};

// Форматирование даты с временем
function formatDateTime(dateStr: string | null): string {
  if (!dateStr) return '—';
  const date = new Date(dateStr);
  return date.toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// Форматирование даты (только дата)
function formatDate(dateStr: string | null): string {
  if (!dateStr) return '—';
  const date = new Date(dateStr);
  return date.toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
  });
}

// Форматирование даты для фильтра (DD.MM.YYYY)
// DatePicker уже возвращает дату в формате DD.MM.YYYY, просто передаём как есть
function formatDateForFilter(dateStr: string): string {
  if (!dateStr) return '';
  // Если уже в формате DD.MM.YYYY — возвращаем как есть
  if (dateStr.includes('.')) {
    return dateStr;
  }
  // Если ISO формат — конвертируем
  const date = new Date(dateStr);
  if (isNaN(date.getTime())) return dateStr;
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  return `${day}.${month}.${year}`;
}

// Тип сортировки
type SortField = 'id' | 'created_at' | 'category_name' | 'location_name' | 'status' | 'days_left' | null;
type SortDirection = 'asc' | 'desc';

export function Billing() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  
  // Сортировка
  const [sortField, setSortField] = useState<SortField>('created_at');
  const [sortDirection, setSortDirection] = useState<SortDirection>('desc');
  
  // Фильтры
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [statusFilter, setStatusFilter] = useState<string[]>([]);
  const [subscriptionIdFilter, setSubscriptionIdFilter] = useState('');
  
  // Применённые фильтры (с timestamp для принудительного обновления)
  const [appliedFilters, setAppliedFilters] = useState<{
    dateFrom: string;
    dateTo: string;
    status: string[];
    subscriptionId: string;
    _ts?: number;
  }>({
    dateFrom: '',
    dateTo: '',
    status: [],
    subscriptionId: '',
  });

  // Модальное окно продления
  const [extendModal, setExtendModal] = useState<{
    open: boolean;
    subscription: BillingSubscriptionItem | null;
  }>({ open: false, subscription: null });
  const [extendNotes, setExtendNotes] = useState('');

  // Toast уведомление
  const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const showToast = (type: 'success' | 'error', message: string) => {
    setToast({ type, message });
    setTimeout(() => setToast(null), 5000);
  };

  // Мутация для запроса продления
  const extendMutation = useMutation({
    mutationFn: ({ subscriptionId, tariffId, notes }: { subscriptionId: number; tariffId: number; notes?: string }) =>
      subscriptionsApi.requestExtend(subscriptionId, tariffId, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['billing-subscriptions'] });
      closeExtendModal();
      showToast('success', 'Заявка на продление отправлена! Ожидайте подтверждения администратора.');
    },
    onError: (error: any) => {
      showToast('error', error?.response?.data?.message || 'Ошибка при отправке заявки');
    },
  });

  const openExtendModal = (subscription: BillingSubscriptionItem) => {
    setExtendModal({ open: true, subscription });
    setExtendNotes('');
  };

  const closeExtendModal = () => {
    setExtendModal({ open: false, subscription: null });
    setExtendNotes('');
  };

  const handleExtendSubmit = () => {
    if (!extendModal.subscription) return;
    extendMutation.mutate({
      subscriptionId: extendModal.subscription.id,
      tariffId: extendModal.subscription.tariff_id, // Используем текущий тариф подписки
      notes: extendNotes || undefined,
    });
  };

  // Формируем параметры запроса
  const buildFilters = (): BillingFilters => {
    const filters: BillingFilters = {
      page,
      per_page: perPage,
      sorting: sortField ? { [sortField]: sortDirection } : { created_at: 'desc' },
    };
    
    const filterParams: BillingFilters['filters'] = {};
    
    if (appliedFilters.subscriptionId) {
      filterParams.subscription_id = parseInt(appliedFilters.subscriptionId, 10);
    }
    
    if (appliedFilters.status.length > 0) {
      filterParams.status = appliedFilters.status;
    }
    
    if (appliedFilters.dateFrom || appliedFilters.dateTo) {
      filterParams.created_at = {};
      if (appliedFilters.dateFrom) {
        filterParams.created_at.from = formatDateForFilter(appliedFilters.dateFrom);
      }
      if (appliedFilters.dateTo) {
        filterParams.created_at.to = formatDateForFilter(appliedFilters.dateTo);
      }
    }
    
    if (Object.keys(filterParams).length > 0) {
      filters.filters = filterParams;
    }
    
    return filters;
  };

  // Загрузка подписок (без кеша)
  const { data: response, isLoading, error, refetch } = useQuery({
    queryKey: ['billing-subscriptions', page, perPage, appliedFilters, sortField, sortDirection],
    queryFn: () => billingApi.getUserSubscriptions(buildFilters()),
    staleTime: 0,
    gcTime: 0,
  });

  // response.data = { meta, data } - сервер возвращает напрямую без обёртки
  const subscriptions = (response?.data as any)?.data || [];
  const meta = (response?.data as any)?.meta;
  const total = meta?.total || 0;
  const totalPages = meta?.total_pages || 1;

  // Применение фильтров
  const handleApplyFilters = () => {
    setPage(1);
    setAppliedFilters({
      dateFrom,
      dateTo,
      status: statusFilter,
      subscriptionId: subscriptionIdFilter,
    });
    // Принудительно обновляем данные
    refetch();
  };

  // Сброс всех фильтров
  const handleResetFilters = () => {
    setDateFrom('');
    setDateTo('');
    setStatusFilter([]);
    setSubscriptionIdFilter('');
    setPage(1);
    setAppliedFilters({
      dateFrom: '',
      dateTo: '',
      status: [],
      subscriptionId: '',
    });
    // Принудительно обновляем данные
    refetch();
  };

  // Одиночные сбросы фильтров
  const clearSubscriptionId = () => {
    setSubscriptionIdFilter('');
    setAppliedFilters(prev => ({ ...prev, subscriptionId: '', _ts: Date.now() }));
  };
  const clearDateFrom = () => {
    setDateFrom('');
    setAppliedFilters(prev => ({ ...prev, dateFrom: '', _ts: Date.now() }));
  };
  const clearDateTo = () => {
    setDateTo('');
    setAppliedFilters(prev => ({ ...prev, dateTo: '', _ts: Date.now() }));
  };
  const clearStatusFilter = () => {
    setStatusFilter([]);
    setAppliedFilters(prev => ({ ...prev, status: [], _ts: Date.now() }));
  };

  // Переключение статуса в фильтре
  const toggleStatusFilter = (status: string) => {
    setStatusFilter(prev => 
      prev.includes(status) 
        ? prev.filter(s => s !== status)
        : [...prev, status]
    );
  };

  // Обработка сортировки
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      // Переключаем направление
      setSortDirection(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('desc');
    }
    setPage(1);
  };

  // Иконка сортировки
  const renderSortIcon = (field: SortField) => {
    if (sortField !== field) {
      return <span className="material-icons billing-sort-icon inactive">unfold_more</span>;
    }
    return (
      <span className="material-icons billing-sort-icon active">
        {sortDirection === 'asc' ? 'expand_less' : 'expand_more'}
      </span>
    );
  };

  // Рендер статуса
  const renderStatus = (status: string) => {
    const statusInfo = STATUS_MAP[status] || { label: status, color: '#6b7280' };
    return (
      <span 
        className="billing-status-badge"
        style={{ backgroundColor: statusInfo.color }}
      >
        {statusInfo.label}
      </span>
    );
  };

  if (error) {
    return (
      <div className="billing-page">
        <div className="billing-error">
          <span className="material-icons">error</span>
          <p>Ошибка загрузки данных</p>
        </div>
      </div>
    );
  }

  return (
    <div className="billing-page">
      {/* Toast уведомление */}
      {toast && (
        <div className={`billing-toast ${toast.type}`}>
          <span className="material-icons">
            {toast.type === 'success' ? 'check_circle' : 'error'}
          </span>
          <span className="billing-toast-message">{toast.message}</span>
          <button className="billing-toast-close" onClick={() => setToast(null)}>
            <span className="material-icons">close</span>
          </button>
        </div>
      )}

      <div className="billing-header">
        <h1>Мои подписки</h1>
        {total > 0 && <span className="billing-count">{total} подписок</span>}
      </div>

      {/* Фильтры */}
      <div className="card">
        <div className="billing-filters">
          <div className="billing-filters-row">
            <div className="billing-filter-group billing-filter-small">
              <label>ID</label>
              <div className="billing-filter-with-clear">
                <input
                  type="text"
                  value={subscriptionIdFilter}
                  onChange={(e) => setSubscriptionIdFilter(e.target.value)}
                  placeholder="ID"
                  className="billing-filter-input"
                />
                {subscriptionIdFilter && (
                  <button className="billing-clear-btn" onClick={clearSubscriptionId} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            
            <div className="billing-filter-group">
              <label>Дата от</label>
              <div className="billing-filter-with-clear">
                <DatePicker value={dateFrom} onChange={setDateFrom} placeholder="Выберите" />
                {dateFrom && (
                  <button className="billing-clear-btn" onClick={clearDateFrom} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            
            <div className="billing-filter-group">
              <label>Дата до</label>
              <div className="billing-filter-with-clear">
                <DatePicker value={dateTo} onChange={setDateTo} placeholder="Выберите" />
                {dateTo && (
                  <button className="billing-clear-btn" onClick={clearDateTo} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            
            <div className="billing-filter-group">
              <label>
                Статус
                {statusFilter.length > 0 && (
                  <button className="billing-clear-inline-btn" onClick={clearStatusFilter} title="Сбросить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </label>
              <div className="billing-status-filters">
                {Object.entries(STATUS_MAP).map(([key, { label, color }]) => (
                  <button
                    key={key}
                    className="billing-status-filter-btn"
                    style={{ 
                      borderColor: color,
                      backgroundColor: statusFilter.includes(key) ? color : 'transparent',
                      color: statusFilter.includes(key) ? '#fff' : color,
                    }}
                    onClick={() => toggleStatusFilter(key)}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>
            
            <div className="billing-filter-actions">
              <button className="btn btn-primary" onClick={handleApplyFilters}>
                <span className="material-icons">search</span>
                Применить
              </button>
              <button className="btn btn-secondary" onClick={handleResetFilters}>
                <span className="material-icons">clear</span>
                Сбросить
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Таблица подписок */}
      <div className="card">
        {isLoading ? (
          <div className="billing-loading">
            <span className="material-icons spinning">sync</span>
            <p>Загрузка...</p>
          </div>
        ) : subscriptions.length === 0 ? (
          <div className="billing-empty">
            <span className="material-icons">inbox</span>
            <h3>Подписки не найдены</h3>
            <p>Оформите подписку на странице тарифов</p>
          </div>
        ) : (
          <>
            <div className="billing-table-container">
              <table className="billing-table">
                <thead>
                  <tr>
                    <th className="sortable" onClick={() => handleSort('id')}>
                      ID {renderSortIcon('id')}
                    </th>
                    <th className="sortable" onClick={() => handleSort('created_at')}>
                      Дата {renderSortIcon('created_at')}
                    </th>
                    <th>Тариф</th>
                    <th className="sortable" onClick={() => handleSort('category_name')}>
                      Категория {renderSortIcon('category_name')}
                    </th>
                    <th className="sortable" onClick={() => handleSort('location_name')}>
                      Локация {renderSortIcon('location_name')}
                    </th>
                    <th className="sortable" onClick={() => handleSort('status')}>
                      Статус {renderSortIcon('status')}
                    </th>
                    <th className="sortable" onClick={() => handleSort('days_left')}>
                      Осталось {renderSortIcon('days_left')}
                    </th>
                    <th>Период</th>
                    <th>Действия</th>
                  </tr>
                </thead>
                <tbody>
                  {subscriptions.map((sub: BillingSubscriptionItem) => (
                    <tr key={sub.id}>
                      <td className="billing-cell-id">#{sub.id}</td>
                      <td className="billing-cell-date">
                        <div className="date">{formatDate(sub.created_at)}</div>
                      </td>
                      <td className="billing-cell-tariff">{sub.tariff_name}</td>
                      <td className="billing-cell-category">{sub.category_name}</td>
                      <td className="billing-cell-location">{sub.location_name}</td>
                      <td>{renderStatus(sub.status)}</td>
                      <td className="billing-cell-days">{sub.days_left}</td>
                      <td className="billing-cell-period">
                        {sub.start_date || sub.end_date ? (
                          <>
                            <div className="start">{formatDate(sub.start_date)}</div>
                            <div className="end">{formatDate(sub.end_date)}</div>
                          </>
                        ) : '—'}
                      </td>
                      <td className="billing-cell-actions">
                        {/* Для демо-подписок не показываем действия */}
                        {sub.tariff_name?.toLowerCase().includes('демо') || sub.tariff_name?.toLowerCase().includes('demo') ? (
                          <span className="billing-no-actions">—</span>
                        ) : (
                          sub.status === 'active' && (
                            <Tooltip content="Продлить подписку" position="top">
                              <button
                                className="billing-action-btn extend"
                                onClick={() => openExtendModal(sub)}
                              >
                                <span className="material-icons">update</span>
                              </button>
                            </Tooltip>
                          )
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            
            {/* Футер таблицы */}
            <Pagination
              page={page}
              totalPages={totalPages}
              perPage={perPage}
              total={total}
              onPageChange={setPage}
              onPerPageChange={(newPerPage) => { setPerPage(newPerPage); setPage(1); }}
              perPageOptions={[10, 20, 50]}
            />
          </>
        )}
      </div>

      {/* Модальное окно продления */}
      {extendModal.open && extendModal.subscription && (
        <div className="billing-modal-overlay" onClick={closeExtendModal}>
          <div className="billing-modal" onClick={e => e.stopPropagation()}>
            <div className="billing-modal-header">
              <h2>
                <span className="material-icons" style={{ color: '#2563eb' }}>update</span>
                Продление подписки
              </h2>
              <button className="billing-modal-close" onClick={closeExtendModal}>
                <span className="material-icons">close</span>
              </button>
            </div>

            <div className="billing-modal-body">
              <div className="billing-modal-info">
                <div className="billing-modal-info-row">
                  <span className="label">ID подписки:</span>
                  <span className="value">#{extendModal.subscription.id}</span>
                </div>
                <div className="billing-modal-info-row">
                  <span className="label">Текущий тариф:</span>
                  <span className="value">{extendModal.subscription.tariff_name}</span>
                </div>
                <div className="billing-modal-info-row">
                  <span className="label">Категория:</span>
                  <span className="value">{extendModal.subscription.category_name}</span>
                </div>
                <div className="billing-modal-info-row">
                  <span className="label">Локация:</span>
                  <span className="value">{extendModal.subscription.location_name}</span>
                </div>
                <div className="billing-modal-info-row">
                  <span className="label">Действует до:</span>
                  <span className="value">{formatDate(extendModal.subscription.end_date)}</span>
                </div>
              </div>

              <div className="billing-modal-form">
                <div className="billing-modal-field">
                  <label>Комментарий (опционально)</label>
                  <textarea
                    value={extendNotes}
                    onChange={e => setExtendNotes(e.target.value)}
                    placeholder="Например: хочу продлить на месяц"
                    className="billing-modal-input"
                    rows={3}
                  />
                </div>

                <div className="billing-modal-hint">
                  После отправки заявки администратор свяжется с вами для подтверждения оплаты.
                </div>
              </div>
            </div>

            <div className="billing-modal-footer">
              <button className="btn btn-secondary" onClick={closeExtendModal}>
                Отмена
              </button>
              <button
                className="btn btn-primary"
                onClick={handleExtendSubmit}
                disabled={extendMutation.isPending}
              >
                {extendMutation.isPending ? (
                  <><span className="material-icons spinning">sync</span> Отправка...</>
                ) : (
                  <><span className="material-icons">send</span> Отправить заявку</>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
