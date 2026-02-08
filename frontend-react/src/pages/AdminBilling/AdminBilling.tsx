import { useState, useCallback, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { 
  adminBillingApi, 
  tariffsApi,
  type AdminSubscription, 
  type SubscriptionHistoryItem,
  type AdminBillingFilters 
} from '../../services/api';
import { DatePicker } from '../../components/UI/DatePicker';
import { Tooltip } from '../../components/UI/Tooltip';
import { Pagination } from '../../components/UI/Pagination';
import './AdminBilling.css';

type TabType = 'current' | 'history';
type ModalType = 'activate' | 'extend' | 'cancel' | 'create' | null;

// Типы сортировки для текущих подписок
type CurrentSortField = 'id' | 'user_id' | 'status' | 'days_left' | 'price_paid' | 'created_at' | 'end_date' | null;
// Типы сортировки для истории
type HistorySortField = 'id' | 'subscription_id' | 'user_id' | 'price' | 'action_date' | null;
type SortDirection = 'asc' | 'desc';

// Статусы подписок
const STATUS_MAP: Record<string, { label: string; color: string }> = {
  active: { label: 'Активна', color: '#16a34a' },
  pending: { label: 'Ожидает оплаты', color: '#ea580c' },
  extend_pending: { label: 'Ожидает продления', color: '#2563eb' },
  expired: { label: 'Истекла', color: '#dc2626' },
  cancelled: { label: 'Отменена', color: '#4b5563' },
};

// Типы действий для истории
const ACTION_TYPES: Record<string, string> = {
  created: 'Создана',
  requested: 'Запрошена',
  activated: 'Активирована',
  extended: 'Продлена',
  extend_requested: 'Запрос продления',
  cancelled: 'Отменена',
  expired: 'Истекла',
};

// Форматирование даты
function formatDate(dateStr: string | null): string {
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

// Форматирование цены
function formatPrice(price: number | null): string {
  if (price === null || price === undefined) return '—';
  return new Intl.NumberFormat('ru-RU').format(price) + ' ₽';
}

// Методы оплаты
const PAYMENT_METHODS = [
  { value: 'card', label: 'Банковская карта' },
  { value: 'sbp', label: 'СБП' },
  { value: 'cash', label: 'Наличные' },
  { value: 'crypto', label: 'Криптовалюта' },
  { value: 'other', label: 'Другое' },
];

export function AdminBilling() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  
  // Читаем таб из URL или используем 'current' по умолчанию
  const tabFromUrl = searchParams.get('tab') as TabType | null;
  const [activeTab, setActiveTab] = useState<TabType>(tabFromUrl === 'history' ? 'history' : 'current');
  
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  
  // Сортировка для текущих подписок
  const [currentSortField, setCurrentSortField] = useState<CurrentSortField>('created_at');
  const [currentSortDirection, setCurrentSortDirection] = useState<SortDirection>('desc');
  
  // Сортировка для истории
  const [historySortField, setHistorySortField] = useState<HistorySortField>('action_date');
  const [historySortDirection, setHistorySortDirection] = useState<SortDirection>('desc');
  
  // Фильтры для текущих подписок
  const [subscriptionIdFilter, setSubscriptionIdFilter] = useState('');
  const [userIdFilter, setUserIdFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState<string[]>([]);
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  
  // Фильтры для истории
  const [historySubscriptionId, setHistorySubscriptionId] = useState('');
  const [historyUserId, setHistoryUserId] = useState('');
  const [actionFilter, setActionFilter] = useState<string[]>([]);
  const [actionDateFrom, setActionDateFrom] = useState('');
  const [actionDateTo, setActionDateTo] = useState('');
  
  // Применённые фильтры (используем timestamp для принудительного обновления)
  const [appliedCurrentFilters, setAppliedCurrentFilters] = useState<AdminBillingFilters['filters'] & { _ts?: number }>({});
  const [appliedHistoryFilters, setAppliedHistoryFilters] = useState<AdminBillingFilters['filters'] & { _ts?: number }>({});
  
  // Сохраняем таб в URL при изменении
  useEffect(() => {
    const currentTab = searchParams.get('tab');
    if (currentTab !== activeTab) {
      setSearchParams({ tab: activeTab }, { replace: true });
    }
  }, [activeTab, searchParams, setSearchParams]);

  // Состояние модальных окон
  const [modalType, setModalType] = useState<ModalType>(null);
  const [selectedSubscription, setSelectedSubscription] = useState<AdminSubscription | null>(null);
  
  // Поля формы модального окна
  const [modalPaymentMethod, setModalPaymentMethod] = useState('card');
  const [modalNotes, setModalNotes] = useState('');
  const [modalDurationHours, setModalDurationHours] = useState('');
  const [modalPrice, setModalPrice] = useState('');
  const [modalReason, setModalReason] = useState('');
  
  // Поля для создания подписки
  const [createUserId, setCreateUserId] = useState('');
  const [createTariffId, setCreateTariffId] = useState('');
  const [createCategoryId, setCreateCategoryId] = useState('');
  const [createLocationId, setCreateLocationId] = useState('');
  const [createAutoActivate, setCreateAutoActivate] = useState(true);

  // Toast уведомление
  const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const showToast = (type: 'success' | 'error', message: string) => {
    setToast({ type, message });
    setTimeout(() => setToast(null), 5000);
  };

  // Запрос текущих подписок
  const { data: currentQueryData, isLoading: isLoadingCurrent, refetch: refetchCurrent } = useQuery({
    queryKey: ['admin-current-subscriptions', page, perPage, appliedCurrentFilters, currentSortField, currentSortDirection],
    queryFn: () => adminBillingApi.getCurrentSubscriptions({
      page,
      per_page: perPage,
      filters: appliedCurrentFilters,
      sorting: currentSortField ? { [currentSortField]: currentSortDirection } : { created_at: 'desc' },
    }),
    enabled: activeTab === 'current',
    staleTime: 0,
    gcTime: 0,
  });

  // Запрос истории
  const { data: historyQueryData, isLoading: isLoadingHistory, refetch: refetchHistory } = useQuery({
    queryKey: ['admin-subscription-history', page, perPage, appliedHistoryFilters, historySortField, historySortDirection],
    queryFn: () => adminBillingApi.getSubscriptionHistory({
      page,
      per_page: perPage,
      filters: appliedHistoryFilters,
      sorting: historySortField ? { [historySortField]: historySortDirection } : { action_date: 'desc' },
    }),
    enabled: activeTab === 'history',
    staleTime: 0,
    gcTime: 0,
  });

  const currentData = (currentQueryData?.data as any)?.data || [];
  const currentMeta = (currentQueryData?.data as any)?.meta;
  
  const historyData = (historyQueryData?.data as any)?.data || [];
  const historyMeta = (historyQueryData?.data as any)?.meta;

  const meta = activeTab === 'current' ? currentMeta : historyMeta;
  const isLoading = activeTab === 'current' ? isLoadingCurrent : isLoadingHistory;
  const totalPages = meta?.total_pages || 1;

  // Загрузка тарифов, категорий и локаций для создания подписки
  const { data: tariffInfoResponse } = useQuery({
    queryKey: ['tariff-info-for-create'],
    queryFn: () => tariffsApi.getTariffInfo(),
  });
  const tariffInfo = (tariffInfoResponse?.data?.data as any) || {};
  const tariffs = tariffInfo.tariffs || [];
  const categories = tariffInfo.categories || [];
  const locations = tariffInfo.locations || [];

  // Мутации
  const activateMutation = useMutation({
    mutationFn: (data: { subscriptionId: number; paymentMethod: string; notes?: string; durationHours?: number }) =>
      adminBillingApi.activateSubscription(data.subscriptionId, data.paymentMethod, data.notes, data.durationHours),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-current-subscriptions'] });
      queryClient.invalidateQueries({ queryKey: ['admin-subscription-history'] });
      closeModal();
    },
  });

  const extendMutation = useMutation({
    mutationFn: (data: { subscriptionId: number; paymentMethod: string; price?: number; notes?: string; durationHours?: number }) =>
      adminBillingApi.extendSubscription(data.subscriptionId, data.paymentMethod, data.price, data.notes, data.durationHours),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-current-subscriptions'] });
      queryClient.invalidateQueries({ queryKey: ['admin-subscription-history'] });
      closeModal();
    },
  });

  const cancelMutation = useMutation({
    mutationFn: (data: { subscriptionId: number; reason?: string }) =>
      adminBillingApi.cancelSubscription(data.subscriptionId, data.reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-current-subscriptions'] });
      queryClient.invalidateQueries({ queryKey: ['admin-subscription-history'] });
      closeModal();
    },
  });

  const createMutation = useMutation({
    mutationFn: (data: { 
      userId: number; 
      tariffId: number; 
      categoryId: number; 
      locationId: number;
      paymentMethod: string; 
      notes?: string; 
      durationHours?: number;
      price?: number;
      autoActivate?: boolean;
    }) =>
      adminBillingApi.createSubscription(
        data.userId, 
        data.tariffId, 
        data.categoryId, 
        data.locationId, 
        data.paymentMethod,
        {
          notes: data.notes,
          durationHours: data.durationHours,
          price: data.price,
          autoActivate: data.autoActivate,
        }
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-current-subscriptions'] });
      queryClient.invalidateQueries({ queryKey: ['admin-subscription-history'] });
      closeModal();
      showToast('success', 'Подписка успешно создана!');
    },
    onError: (error: any) => {
      showToast('error', error?.response?.data?.message || 'Ошибка при создании подписки');
    },
  });

  const openModal = useCallback((type: ModalType, subscription: AdminSubscription | null = null) => {
    setModalType(type);
    setSelectedSubscription(subscription);
    setModalPaymentMethod('card');
    setModalNotes('');
    setModalDurationHours('');
    setModalPrice(subscription?.price_paid?.toString() || '');
    setModalReason('');
    // Сброс полей создания
    if (type === 'create') {
      setCreateUserId('');
      setCreateTariffId('');
      setCreateCategoryId('');
      setCreateLocationId('');
      setCreateAutoActivate(true);
    }
  }, []);

  const closeModal = useCallback(() => {
    setModalType(null);
    setSelectedSubscription(null);
  }, []);

  const handleModalSubmit = useCallback(() => {
    // Для create не нужна selectedSubscription
    if (modalType === 'create') {
      if (!createUserId || !createTariffId || !createCategoryId || !createLocationId) {
        showToast('error', 'Заполните все обязательные поля');
        return;
      }
      createMutation.mutate({
        userId: parseInt(createUserId, 10),
        tariffId: parseInt(createTariffId, 10),
        categoryId: parseInt(createCategoryId, 10),
        locationId: parseInt(createLocationId, 10),
        paymentMethod: modalPaymentMethod,
        notes: modalNotes || undefined,
        durationHours: modalDurationHours ? parseInt(modalDurationHours, 10) : undefined,
        price: modalPrice ? parseFloat(modalPrice) : undefined,
        autoActivate: createAutoActivate,
      });
      return;
    }

    if (!selectedSubscription) return;

    if (modalType === 'activate') {
      activateMutation.mutate({
        subscriptionId: selectedSubscription.id,
        paymentMethod: modalPaymentMethod,
        notes: modalNotes || undefined,
        durationHours: modalDurationHours ? parseInt(modalDurationHours, 10) : undefined,
      });
    } else if (modalType === 'extend') {
      extendMutation.mutate({
        subscriptionId: selectedSubscription.id,
        paymentMethod: modalPaymentMethod,
        price: modalPrice ? parseFloat(modalPrice) : undefined,
        notes: modalNotes || undefined,
        durationHours: modalDurationHours ? parseInt(modalDurationHours, 10) : undefined,
      });
    } else if (modalType === 'cancel') {
      cancelMutation.mutate({
        subscriptionId: selectedSubscription.id,
        reason: modalReason || undefined,
      });
    }
  }, [selectedSubscription, modalType, modalPaymentMethod, modalNotes, modalDurationHours, modalPrice, modalReason, createUserId, createTariffId, createCategoryId, createLocationId, createAutoActivate, activateMutation, extendMutation, cancelMutation, createMutation]);

  const isModalLoading = activateMutation.isPending || extendMutation.isPending || cancelMutation.isPending || createMutation.isPending;
  const modalError = activateMutation.error || extendMutation.error || cancelMutation.error || createMutation.error;

  // Применение фильтров для текущих подписок
  const handleApplyCurrentFilters = () => {
    setPage(1);
    const filters: AdminBillingFilters['filters'] = {};
    
    if (subscriptionIdFilter) filters.subscription_id = parseInt(subscriptionIdFilter, 10);
    if (userIdFilter) filters.user_id = parseInt(userIdFilter, 10);
    if (statusFilter.length > 0) filters.status = statusFilter;
    
    if (dateFrom || dateTo) {
      filters.created_at = {};
      if (dateFrom) filters.created_at.from = formatDateForFilter(dateFrom);
      if (dateTo) filters.created_at.to = formatDateForFilter(dateTo);
    }
    
    setAppliedCurrentFilters(filters);
    // Принудительно обновляем данные
    refetchCurrent();
  };

  // Применение фильтров для истории
  const handleApplyHistoryFilters = () => {
    setPage(1);
    const filters: AdminBillingFilters['filters'] = {};
    
    if (historySubscriptionId) filters.subscription_id = parseInt(historySubscriptionId, 10);
    if (historyUserId) filters.user_id = parseInt(historyUserId, 10);
    if (actionFilter.length > 0) filters.action = actionFilter;
    
    if (actionDateFrom || actionDateTo) {
      filters.action_date = {};
      if (actionDateFrom) filters.action_date.from = formatDateForFilter(actionDateFrom);
      if (actionDateTo) filters.action_date.to = formatDateForFilter(actionDateTo);
    }
    
    setAppliedHistoryFilters(filters);
    // Принудительно обновляем данные
    refetchHistory();
  };

  // Сброс всех фильтров
  const handleResetFilters = () => {
    setPage(1);
    if (activeTab === 'current') {
      setSubscriptionIdFilter('');
      setUserIdFilter('');
      setStatusFilter([]);
      setDateFrom('');
      setDateTo('');
      setAppliedCurrentFilters({});
      refetchCurrent();
    } else {
      setHistorySubscriptionId('');
      setHistoryUserId('');
      setActionFilter([]);
      setActionDateFrom('');
      setActionDateTo('');
      setAppliedHistoryFilters({});
      refetchHistory();
    }
  };

  // Одиночные сбросы для текущих подписок
  const clearCurrentSubscriptionId = () => {
    setSubscriptionIdFilter('');
    handleApplyCurrentFilters();
  };
  const clearCurrentUserId = () => {
    setUserIdFilter('');
    handleApplyCurrentFilters();
  };
  const clearCurrentDateFrom = () => {
    setDateFrom('');
    handleApplyCurrentFilters();
  };
  const clearCurrentDateTo = () => {
    setDateTo('');
    handleApplyCurrentFilters();
  };
  const clearCurrentStatus = () => {
    setStatusFilter([]);
    handleApplyCurrentFilters();
  };

  // Одиночные сбросы для истории
  const clearHistorySubscriptionId = () => {
    setHistorySubscriptionId('');
    handleApplyHistoryFilters();
  };
  const clearHistoryUserId = () => {
    setHistoryUserId('');
    handleApplyHistoryFilters();
  };
  const clearHistoryDateFrom = () => {
    setActionDateFrom('');
    handleApplyHistoryFilters();
  };
  const clearHistoryDateTo = () => {
    setActionDateTo('');
    handleApplyHistoryFilters();
  };
  const clearHistoryAction = () => {
    setActionFilter([]);
    handleApplyHistoryFilters();
  };

  // Переключение вкладки
  const handleTabChange = (tab: TabType) => {
    setActiveTab(tab);
    setPage(1);
  };

  // Сортировка для текущих подписок
  const handleCurrentSort = (field: CurrentSortField) => {
    if (currentSortField === field) {
      setCurrentSortDirection(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setCurrentSortField(field);
      setCurrentSortDirection('desc');
    }
    setPage(1);
  };

  // Сортировка для истории
  const handleHistorySort = (field: HistorySortField) => {
    if (historySortField === field) {
      setHistorySortDirection(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setHistorySortField(field);
      setHistorySortDirection('desc');
    }
    setPage(1);
  };

  // Иконка сортировки
  const renderSortIcon = (field: string, currentField: string | null, direction: SortDirection) => {
    if (currentField !== field) {
      return <span className="material-icons admin-billing-sort-icon inactive">unfold_more</span>;
    }
    return (
      <span className="material-icons admin-billing-sort-icon active">
        {direction === 'asc' ? 'expand_less' : 'expand_more'}
      </span>
    );
  };

  // Рендер статуса
  const renderStatus = (status: string) => {
    const statusInfo = STATUS_MAP[status] || { label: status, color: '#6b7280' };
    return (
      <span className="admin-billing-status-badge" style={{ backgroundColor: statusInfo.color }}>
        {statusInfo.label}
      </span>
    );
  };

  return (
    <div className="admin-billing-page">
      <div className="admin-billing-header">
        <h1>Управление подписками</h1>
        <button className="btn btn-primary" onClick={() => openModal('create')}>
          <span className="material-icons">add</span>
          Создать подписку
        </button>
      </div>

      {/* Вкладки */}
      <div className="admin-billing-tabs">
        <button
          className={`admin-billing-tab ${activeTab === 'current' ? 'active' : ''}`}
          onClick={() => handleTabChange('current')}
        >
          <span className="material-icons">list_alt</span>
          Текущие подписки
        </button>
        <button
          className={`admin-billing-tab ${activeTab === 'history' ? 'active' : ''}`}
          onClick={() => handleTabChange('history')}
        >
          <span className="material-icons">history</span>
          История изменений
        </button>
      </div>

      {/* Фильтры для текущих подписок */}
      {activeTab === 'current' && (
        <div className="card">
          <div className="admin-billing-filters">
            <div className="admin-billing-filters-row">
            <div className="admin-billing-filter-group admin-billing-filter-small">
              <label>ID</label>
              <div className="admin-billing-filter-with-clear">
                <input
                  type="text"
                  value={subscriptionIdFilter}
                  onChange={(e) => setSubscriptionIdFilter(e.target.value)}
                  placeholder="ID"
                  className="admin-billing-filter-input"
                />
                {subscriptionIdFilter && (
                  <button className="admin-billing-clear-btn" onClick={() => { setSubscriptionIdFilter(''); handleApplyCurrentFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group admin-billing-filter-small">
              <label>User ID</label>
              <div className="admin-billing-filter-with-clear">
                <input
                  type="text"
                  value={userIdFilter}
                  onChange={(e) => setUserIdFilter(e.target.value)}
                  placeholder="User"
                  className="admin-billing-filter-input"
                />
                {userIdFilter && (
                  <button className="admin-billing-clear-btn" onClick={() => { setUserIdFilter(''); handleApplyCurrentFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group">
              <label>Дата от</label>
              <div className="admin-billing-filter-with-clear">
                <DatePicker value={dateFrom} onChange={setDateFrom} placeholder="Выберите" />
                {dateFrom && (
                  <button className="admin-billing-clear-btn" onClick={() => { setDateFrom(''); handleApplyCurrentFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group">
              <label>Дата до</label>
              <div className="admin-billing-filter-with-clear">
                <DatePicker value={dateTo} onChange={setDateTo} placeholder="Выберите" />
                {dateTo && (
                  <button className="admin-billing-clear-btn" onClick={() => { setDateTo(''); handleApplyCurrentFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group admin-billing-filter-status-inline">
              <label>
                Статус
                {statusFilter.length > 0 && (
                  <button className="admin-billing-clear-inline-btn" onClick={() => { setStatusFilter([]); handleApplyCurrentFilters(); }} title="Сбросить статусы">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </label>
              <div className="admin-billing-status-filters">
                {Object.entries(STATUS_MAP).map(([key, { label, color }]) => (
                  <button
                    key={key}
                    className="admin-billing-status-filter-btn"
                    style={{ 
                      borderColor: color,
                      backgroundColor: statusFilter.includes(key) ? color : '#fff',
                      color: statusFilter.includes(key) ? '#fff' : color,
                    }}
                    onClick={() => setStatusFilter(prev => 
                      prev.includes(key) ? prev.filter(s => s !== key) : [...prev, key]
                    )}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>
            <div className="admin-billing-filter-actions">
              <button className="btn btn-primary" onClick={handleApplyCurrentFilters}>
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
      )}

      {/* Фильтры для истории */}
      {activeTab === 'history' && (
        <div className="card">
          <div className="admin-billing-filters">
            <div className="admin-billing-filters-row">
            <div className="admin-billing-filter-group admin-billing-filter-small">
              <label>ID</label>
              <div className="admin-billing-filter-with-clear">
                <input
                  type="text"
                  value={historySubscriptionId}
                  onChange={(e) => setHistorySubscriptionId(e.target.value)}
                  placeholder="ID"
                  className="admin-billing-filter-input"
                />
                {historySubscriptionId && (
                  <button className="admin-billing-clear-btn" onClick={() => { setHistorySubscriptionId(''); handleApplyHistoryFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group admin-billing-filter-small">
              <label>User ID</label>
              <div className="admin-billing-filter-with-clear">
                <input
                  type="text"
                  value={historyUserId}
                  onChange={(e) => setHistoryUserId(e.target.value)}
                  placeholder="User"
                  className="admin-billing-filter-input"
                />
                {historyUserId && (
                  <button className="admin-billing-clear-btn" onClick={() => { setHistoryUserId(''); handleApplyHistoryFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group">
              <label>Дата от</label>
              <div className="admin-billing-filter-with-clear">
                <DatePicker value={actionDateFrom} onChange={setActionDateFrom} placeholder="Выберите" />
                {actionDateFrom && (
                  <button className="admin-billing-clear-btn" onClick={() => { setActionDateFrom(''); handleApplyHistoryFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group">
              <label>Дата до</label>
              <div className="admin-billing-filter-with-clear">
                <DatePicker value={actionDateTo} onChange={setActionDateTo} placeholder="Выберите" />
                {actionDateTo && (
                  <button className="admin-billing-clear-btn" onClick={() => { setActionDateTo(''); handleApplyHistoryFilters(); }} title="Очистить">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </div>
            </div>
            <div className="admin-billing-filter-group admin-billing-filter-status-inline">
              <label>
                Действие
                {actionFilter.length > 0 && (
                  <button className="admin-billing-clear-inline-btn" onClick={() => { setActionFilter([]); handleApplyHistoryFilters(); }} title="Сбросить действия">
                    <span className="material-icons">close</span>
                  </button>
                )}
              </label>
              <div className="admin-billing-action-filters">
                {Object.entries(ACTION_TYPES).map(([key, label]) => {
                  const isSelected = actionFilter.includes(key);
                  return (
                    <button
                      key={key}
                      className={`admin-billing-action-filter-btn ${isSelected ? 'selected' : ''}`}
                      style={{
                        borderColor: '#64748b',
                        backgroundColor: isSelected ? '#64748b' : 'transparent',
                        color: isSelected ? '#fff' : '#64748b',
                      }}
                      onClick={() => setActionFilter(prev => 
                        prev.includes(key) ? prev.filter(a => a !== key) : [...prev, key]
                      )}
                    >
                      {label}
                    </button>
                  );
                })}
              </div>
            </div>
            <div className="admin-billing-filter-actions">
              <button className="btn btn-primary" onClick={handleApplyHistoryFilters}>
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
      )}

      {/* Таблица */}
      <div className="card">
        {isLoading ? (
          <div className="admin-billing-loading">
            <span className="material-icons spinning">sync</span>
            <p>Загрузка...</p>
          </div>
        ) : activeTab === 'current' ? (
          currentData.length === 0 ? (
            <div className="admin-billing-empty">
              <span className="material-icons">inbox</span>
              <h3>Подписки не найдены</h3>
              <p>Измените параметры фильтрации</p>
            </div>
          ) : (
            <>
              <div className="admin-billing-table-container">
                <table className="admin-billing-table">
                  <thead>
                    <tr>
                      <th className="sortable" onClick={() => handleCurrentSort('id')}>
                        ID {renderSortIcon('id', currentSortField, currentSortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleCurrentSort('user_id')}>
                        User {renderSortIcon('user_id', currentSortField, currentSortDirection)}
                      </th>
                      <th>Telegram</th>
                      <th className="sortable" onClick={() => handleCurrentSort('status')}>
                        Статус {renderSortIcon('status', currentSortField, currentSortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleCurrentSort('days_left')}>
                        Осталось {renderSortIcon('days_left', currentSortField, currentSortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleCurrentSort('price_paid')}>
                        Оплачено {renderSortIcon('price_paid', currentSortField, currentSortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleCurrentSort('created_at')}>
                        Создана {renderSortIcon('created_at', currentSortField, currentSortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleCurrentSort('end_date')}>
                        Окончание {renderSortIcon('end_date', currentSortField, currentSortDirection)}
                      </th>
                      <th>Заметки</th>
                      <th>Действия</th>
                    </tr>
                  </thead>
                  <tbody>
                    {currentData.map((sub: AdminSubscription) => (
                      <tr key={sub.id}>
                        <td className="admin-billing-cell-id">#{sub.id}</td>
                        <td className="admin-billing-cell-user">#{sub.user_id}</td>
                        <td className="admin-billing-cell-telegram">
                          {sub.telegram !== 'Не указан' ? (
                            <a href={`https://t.me/${sub.telegram}`} target="_blank" rel="noopener noreferrer">
                              @{sub.telegram}
                            </a>
                          ) : '—'}
                        </td>
                        <td>{renderStatus(sub.status)}</td>
                        <td className="admin-billing-cell-days">{sub.days_left}</td>
                        <td className="admin-billing-cell-price">{formatPrice(sub.price_paid)}</td>
                        <td className="admin-billing-cell-date">{formatDate(sub.created_at)}</td>
                        <td className="admin-billing-cell-date">{formatDate(sub.end_date)}</td>
                        <td className="admin-billing-cell-notes">{sub.admin_notes || ''}</td>
                        <td className="admin-billing-cell-actions">
                          {/* Для демо-подписок не показываем действия */}
                          {sub.tariff_info?.toLowerCase().includes('демо') || sub.tariff_info?.toLowerCase().includes('demo') ? (
                            <span className="admin-billing-no-actions">—</span>
                          ) : (
                            <>
                              {(sub.status === 'pending' || sub.status === 'expired') && (
                                <Tooltip content="Активировать" position="top">
                                  <button
                                    className="admin-billing-action-icon activate"
                                    onClick={() => openModal('activate', sub)}
                                  >
                                    <span className="material-icons">check_circle</span>
                                  </button>
                                </Tooltip>
                              )}
                              {(sub.status === 'active' || sub.status === 'extend_pending') && (
                                <Tooltip content="Продлить" position="top">
                                  <button
                                    className="admin-billing-action-icon extend"
                                    onClick={() => openModal('extend', sub)}
                                  >
                                    <span className="material-icons">update</span>
                                  </button>
                                </Tooltip>
                              )}
                              {(sub.status === 'pending' || sub.status === 'active' || sub.status === 'extend_pending') && (
                                <Tooltip content="Отменить" position="top">
                                  <button
                                    className="admin-billing-action-icon cancel"
                                    onClick={() => openModal('cancel', sub)}
                                  >
                                    <span className="material-icons">cancel</span>
                                  </button>
                                </Tooltip>
                              )}
                            </>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination
                page={page}
                totalPages={totalPages}
                perPage={perPage}
                total={currentMeta?.total || currentData.length}
                onPageChange={setPage}
                onPerPageChange={(newPerPage) => { setPerPage(newPerPage); setPage(1); }}
              />
            </>
          )
        ) : (
          historyData.length === 0 ? (
            <div className="admin-billing-empty">
              <span className="material-icons">inbox</span>
              <h3>История не найдена</h3>
              <p>Измените параметры фильтрации</p>
            </div>
          ) : (
            <>
              <div className="admin-billing-table-container">
                <table className="admin-billing-table">
                  <thead>
                    <tr>
                      <th className="sortable" onClick={() => handleHistorySort('id')}>
                        ID {renderSortIcon('id', historySortField, historySortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleHistorySort('subscription_id')}>
                        Подписка {renderSortIcon('subscription_id', historySortField, historySortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleHistorySort('user_id')}>
                        User {renderSortIcon('user_id', historySortField, historySortDirection)}
                      </th>
                      <th>Было</th>
                      <th>Стало</th>
                      <th className="sortable" onClick={() => handleHistorySort('price')}>
                        Сумма {renderSortIcon('price', historySortField, historySortDirection)}
                      </th>
                      <th className="sortable" onClick={() => handleHistorySort('action_date')}>
                        Дата {renderSortIcon('action_date', historySortField, historySortDirection)}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {historyData.map((item: SubscriptionHistoryItem) => (
                      <tr key={item.id}>
                        <td className="admin-billing-cell-id">#{item.id}</td>
                        <td className="admin-billing-cell-id">#{item.subscription_id}</td>
                        <td className="admin-billing-cell-user">#{item.user_id}</td>
                        <td className="admin-billing-cell-status-text">{item.old_status}</td>
                        <td className="admin-billing-cell-status-text">{item.new_status}</td>
                        <td className="admin-billing-cell-price">{formatPrice(item.price)}</td>
                        <td className="admin-billing-cell-date">{formatDate(item.action_date)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination
                page={page}
                totalPages={totalPages}
                perPage={perPage}
                total={historyMeta?.total || historyData.length}
                onPageChange={setPage}
                onPerPageChange={(newPerPage) => { setPerPage(newPerPage); setPage(1); }}
              />
            </>
          )
        )}
      </div>

      {/* Модальное окно */}
      {modalType && selectedSubscription && (
        <div className="admin-billing-modal-overlay" onClick={closeModal}>
          <div className="admin-billing-modal" onClick={e => e.stopPropagation()}>
            <div className="admin-billing-modal-header">
              <h2>
                {modalType === 'activate' && <><span className="material-icons" style={{ color: '#16a34a' }}>check_circle</span> Активация подписки</>}
                {modalType === 'extend' && <><span className="material-icons" style={{ color: '#2563eb' }}>update</span> Продление подписки</>}
                {modalType === 'cancel' && <><span className="material-icons" style={{ color: '#dc2626' }}>cancel</span> Отмена подписки</>}
              </h2>
              <button className="admin-billing-modal-close" onClick={closeModal}>
                <span className="material-icons">close</span>
              </button>
            </div>

            <div className="admin-billing-modal-body">
              <div className="admin-billing-modal-info">
                <div className="admin-billing-modal-info-row">
                  <span className="label">ID подписки:</span>
                  <span className="value">#{selectedSubscription.id}</span>
                </div>
                <div className="admin-billing-modal-info-row">
                  <span className="label">Пользователь:</span>
                  <span className="value">
                    #{selectedSubscription.user_id}
                    {selectedSubscription.telegram !== 'Не указан' && (
                      <a href={`https://t.me/${selectedSubscription.telegram}`} target="_blank" rel="noopener noreferrer" style={{ marginLeft: 8 }}>
                        @{selectedSubscription.telegram}
                      </a>
                    )}
                  </span>
                </div>
                <div className="admin-billing-modal-info-row">
                  <span className="label">Тариф:</span>
                  <span className="value">{selectedSubscription.tariff_info}</span>
                </div>
                <div className="admin-billing-modal-info-row">
                  <span className="label">Текущий статус:</span>
                  <span className="value">{renderStatus(selectedSubscription.status)}</span>
                </div>
              </div>

              {(modalType === 'activate' || modalType === 'extend') && (
                <div className="admin-billing-modal-form">
                  <div className="admin-billing-modal-field">
                    <label>Метод оплаты *</label>
                    <select value={modalPaymentMethod} onChange={e => setModalPaymentMethod(e.target.value)} className="admin-billing-filter-input">
                      {PAYMENT_METHODS.map(pm => <option key={pm.value} value={pm.value}>{pm.label}</option>)}
                    </select>
                  </div>

                  {modalType === 'extend' && (
                    <div className="admin-billing-modal-field">
                      <label>Сумма оплаты (₽)</label>
                      <input type="number" value={modalPrice} onChange={e => setModalPrice(e.target.value)} placeholder="Оставьте пустым для текущей цены" className="admin-billing-filter-input" min="0" step="100" />
                    </div>
                  )}

                  <div className="admin-billing-modal-field">
                    <label>Продолжительность (часов)</label>
                    <input type="number" value={modalDurationHours} onChange={e => setModalDurationHours(e.target.value)} placeholder="По умолчанию из тарифа" className="admin-billing-filter-input" min="1" />
                    <span className="admin-billing-modal-hint">Оставьте пустым для стандартной продолжительности</span>
                  </div>

                  <div className="admin-billing-modal-field">
                    <label>Примечания</label>
                    <textarea value={modalNotes} onChange={e => setModalNotes(e.target.value)} placeholder="Комментарий администратора..." className="admin-billing-filter-input" rows={3} />
                  </div>
                </div>
              )}

              {modalType === 'cancel' && (
                <div className="admin-billing-modal-form">
                  <div className="admin-billing-modal-warning">
                    <span className="material-icons">warning</span>
                    <p>Вы уверены, что хотите отменить эту подписку? Это действие нельзя отменить.</p>
                  </div>
                  <div className="admin-billing-modal-field">
                    <label>Причина отмены</label>
                    <textarea value={modalReason} onChange={e => setModalReason(e.target.value)} placeholder="Укажите причину отмены..." className="admin-billing-filter-input" rows={3} />
                  </div>
                </div>
              )}

              {modalError && (
                <div className="admin-billing-modal-error">
                  <span className="material-icons">error</span>
                  {(modalError as any)?.response?.data?.message || 'Произошла ошибка при выполнении операции'}
                </div>
              )}
            </div>

            <div className="admin-billing-modal-footer">
              <button className="admin-billing-btn secondary" onClick={closeModal} disabled={isModalLoading}>Отмена</button>
              <button className={`admin-billing-btn ${modalType === 'cancel' ? 'danger' : 'primary'}`} onClick={handleModalSubmit} disabled={isModalLoading}>
                {isModalLoading ? <><span className="material-icons spinning">sync</span> Выполняется...</> : (
                  <>
                    {modalType === 'activate' && <><span className="material-icons">check_circle</span> Активировать</>}
                    {modalType === 'extend' && <><span className="material-icons">update</span> Продлить</>}
                    {modalType === 'cancel' && <><span className="material-icons">cancel</span> Отменить подписку</>}
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Модальное окно создания подписки */}
      {modalType === 'create' && (
        <div className="admin-billing-modal-overlay" onClick={closeModal}>
          <div className="admin-billing-modal admin-billing-modal-wide" onClick={e => e.stopPropagation()}>
            <div className="admin-billing-modal-header">
              <h2>
                <span className="material-icons" style={{ color: '#16a34a' }}>add_circle</span>
                Создание подписки
              </h2>
              <button className="admin-billing-modal-close" onClick={closeModal}>
                <span className="material-icons">close</span>
              </button>
            </div>

            <div className="admin-billing-modal-body">
              <div className="admin-billing-modal-hint" style={{ marginBottom: '20px' }}>
                Используйте эту форму для создания подписки пользователю (например, при миграции со старой CRM).
              </div>

              <div className="admin-billing-modal-form">
                <div className="admin-billing-modal-field">
                  <label>ID пользователя *</label>
                  <input
                    type="number"
                    value={createUserId}
                    onChange={e => setCreateUserId(e.target.value)}
                    placeholder="Введите ID пользователя"
                    className="admin-billing-filter-input"
                  />
                </div>

                <div className="admin-billing-modal-field">
                  <label>Тариф *</label>
                  <select
                    value={createTariffId}
                    onChange={e => setCreateTariffId(e.target.value)}
                    className="admin-billing-filter-input"
                  >
                    <option value="">Выберите тариф</option>
                    {tariffs.filter((t: any) => t.code !== 'demo').map((t: any) => (
                      <option key={t.id} value={t.id}>
                        {t.name} — {t.duration_hours} ч. ({Math.round(t.duration_hours / 24)} дн.)
                      </option>
                    ))}
                  </select>
                </div>

                <div className="admin-billing-modal-field">
                  <label>Категория *</label>
                  <select
                    value={createCategoryId}
                    onChange={e => setCreateCategoryId(e.target.value)}
                    className="admin-billing-filter-input"
                  >
                    <option value="">Выберите категорию</option>
                    {categories.map((c: any) => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                </div>

                <div className="admin-billing-modal-field">
                  <label>Локация *</label>
                  <select
                    value={createLocationId}
                    onChange={e => setCreateLocationId(e.target.value)}
                    className="admin-billing-filter-input"
                  >
                    <option value="">Выберите локацию</option>
                    {locations.map((l: any) => (
                      <option key={l.id} value={l.id}>{l.name}</option>
                    ))}
                  </select>
                </div>

                <div className="admin-billing-modal-field">
                  <label>Метод оплаты *</label>
                  <select
                    value={modalPaymentMethod}
                    onChange={e => setModalPaymentMethod(e.target.value)}
                    className="admin-billing-filter-input"
                  >
                    {PAYMENT_METHODS.map(m => (
                      <option key={m.value} value={m.value}>{m.label}</option>
                    ))}
                  </select>
                </div>

                <div className="admin-billing-modal-field">
                  <label>Длительность (часы)</label>
                  <input
                    type="number"
                    value={modalDurationHours}
                    onChange={e => setModalDurationHours(e.target.value)}
                    placeholder="По умолчанию из тарифа"
                    className="admin-billing-filter-input"
                  />
                  <span className="admin-billing-modal-field-hint">Оставьте пустым для использования длительности из тарифа</span>
                </div>

                <div className="admin-billing-modal-field">
                  <label>Цена (₽)</label>
                  <input
                    type="number"
                    value={modalPrice}
                    onChange={e => setModalPrice(e.target.value)}
                    placeholder="По умолчанию из тарифа"
                    className="admin-billing-filter-input"
                  />
                </div>

                <div className="admin-billing-modal-field">
                  <label>Примечание</label>
                  <textarea
                    value={modalNotes}
                    onChange={e => setModalNotes(e.target.value)}
                    placeholder="Например: миграция со старой CRM"
                    className="admin-billing-filter-input"
                    rows={2}
                  />
                </div>

                <div className="admin-billing-modal-field admin-billing-checkbox-field">
                  <label>
                    <input
                      type="checkbox"
                      checked={createAutoActivate}
                      onChange={e => setCreateAutoActivate(e.target.checked)}
                    />
                    Сразу активировать подписку
                  </label>
                </div>
              </div>

              {createMutation.error && (
                <div className="admin-billing-modal-error">
                  <span className="material-icons">error</span>
                  {(createMutation.error as any)?.response?.data?.message || 'Произошла ошибка при создании подписки'}
                </div>
              )}
            </div>

            <div className="admin-billing-modal-footer">
              <button className="admin-billing-btn secondary" onClick={closeModal} disabled={createMutation.isPending}>
                Отмена
              </button>
              <button className="admin-billing-btn primary" onClick={handleModalSubmit} disabled={createMutation.isPending || !createUserId || !createTariffId || !createCategoryId || !createLocationId}>
                {createMutation.isPending ? (
                  <><span className="material-icons spinning">sync</span> Создание...</>
                ) : (
                  <><span className="material-icons">add_circle</span> Создать подписку</>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Toast уведомление */}
      {toast && (
        <div className={`admin-billing-toast ${toast.type}`}>
          <span className="material-icons">
            {toast.type === 'success' ? 'check_circle' : 'error'}
          </span>
          <span className="admin-billing-toast-message">{toast.message}</span>
          <button className="admin-billing-toast-close" onClick={() => setToast(null)}>
            <span className="material-icons">close</span>
          </button>
        </div>
      )}
    </div>
  );
}
