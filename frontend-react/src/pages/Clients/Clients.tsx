import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { clientsApi } from '../../services/api';
import { useClientStore } from '../../stores/clientStore';
import { Tooltip } from '../../components/UI/Tooltip';
import { Pagination } from '../../components/UI/Pagination';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { Pipeline } from './Pipeline';
import { ClientForm } from './ClientForm';
import { FunnelChart } from './FunnelChart';
import type { Client, ClientFilters, PipelineStage } from '../../types/client';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import './Clients.css';

/** Конфигурация сортируемых колонок */
type SortField = 'created_at' | 'name' | 'last_contact_at' | 'next_contact_at' | 'budget_max';

const SORT_COLUMNS: { field: SortField; label: string }[] = [
  { field: 'name', label: 'Имя' },
  { field: 'budget_max', label: 'Бюджет' },
  { field: 'next_contact_at', label: 'Следующий контакт' },
  { field: 'last_contact_at', label: 'Последний контакт' },
  { field: 'created_at', label: 'Создан' },
];

interface PaginationInfo {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export function Clients() {
  const navigate = useNavigate();
  const {
    viewMode, setViewMode,
    searchQuery, setSearchQuery,
    selectedType, setSelectedType,
    selectedStageId, setSelectedStageId,
    showArchived, setShowArchived,
    sortField, setSortField,
    sortOrder, setSortOrder,
    perPage, setPerPage,
    resetFilters,
  } = useClientStore();

  const [clients, setClients] = useState<Client[]>([]);
  const [stages, setStages] = useState<PipelineStage[]>([]);
  const [pagination, setPagination] = useState<PaginationInfo>({ page: 1, per_page: perPage, total: 0, total_pages: 0 });
  const [loading, setLoading] = useState(true);
  const [isRefetching, setIsRefetching] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Флаг первой загрузки — для показа loading-state
  const isFirstLoad = useRef(true);

  // Форма создания/редактирования
  const [showForm, setShowForm] = useState(false);
  const [editingClient, setEditingClient] = useState<Client | null>(null);

  // Воронка
  const [showFunnel, setShowFunnel] = useState(false);

  // Ключ для принудительного обновления Pipeline
  const [refreshKey, setRefreshKey] = useState(0);

  // Диалог подтверждения
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  // Статистика
  const [stats, setStats] = useState<{ total_active: number; overdue_contacts: number } | null>(null);

  /** Загрузка стадий */
  const loadStages = useCallback(async () => {
    try {
      const response = await clientsApi.getStages();
      setStages(response.data?.data?.stages || []);
    } catch {
      // Стадии не загрузились — не критично
    }
  }, []);

  /** Загрузка клиентов */
  const loadClients = useCallback(async (page = 1) => {
    // При первой загрузке — полноценный loading, при рефетче — overlay
    if (isFirstLoad.current) {
      setLoading(true);
    } else {
      setIsRefetching(true);
    }
    setError(null);
    try {
      const filters: ClientFilters = {
        page,
        per_page: perPage,
        sort: sortField,
        order: sortOrder,
      };

      if (searchQuery) filters.search = searchQuery;
      if (selectedType) filters.client_type = selectedType;
      if (selectedStageId) filters.stage_id = selectedStageId;
      if (showArchived) filters.is_archived = true;

      const response = await clientsApi.getList(filters);
      const data = response.data?.data;
      setClients(data?.clients || []);
      setPagination(data?.pagination || { page: 1, per_page: perPage, total: 0, total_pages: 0 });
      isFirstLoad.current = false;
    } catch {
      setError('Ошибка загрузки клиентов');
    } finally {
      setLoading(false);
      setIsRefetching(false);
    }
  }, [searchQuery, selectedType, selectedStageId, showArchived, sortField, sortOrder, perPage]);

  /** Загрузка статистики */
  const loadStats = useCallback(async () => {
    try {
      const response = await clientsApi.getStats();
      setStats(response.data?.data || null);
    } catch {
      // Не критично
    }
  }, []);

  useEffect(() => {
    loadStages();
    loadStats();
  }, [loadStages, loadStats]);

  useEffect(() => {
    if (viewMode === 'list') {
      loadClients();
    }
  }, [loadClients, viewMode]);

  /** Открыть карточку клиента */
  const openClient = (clientId: number) => {
    navigate(`/clients/${clientId}`);
  };

  /** Создать клиента */
  const handleCreate = () => {
    setEditingClient(null);
    setShowForm(true);
  };

  /** После сохранения формы */
  const handleFormSaved = () => {
    setShowForm(false);
    setEditingClient(null);
    loadClients();
    loadStats();
    loadStages();
    // Обновляем Pipeline (канбан) через refreshKey
    setRefreshKey(prev => prev + 1);
  };

  /** Удалить клиента */
  const handleDelete = (clientId: number) => {
    setDialog({
      title: 'Удаление клиента',
      message: 'Удалить клиента? Это действие нельзя отменить.',
      confirmText: 'Удалить',
      cancelText: 'Отмена',
      variant: 'danger',
      onConfirm: async () => {
        setDialog(null);
        try {
          await clientsApi.delete(clientId);
          loadClients();
          loadStats();
        } catch {
          setDialog({ title: 'Ошибка', message: 'Не удалось удалить клиента', variant: 'danger', onConfirm: () => setDialog(null) });
        }
      },
    });
  };

  /** Архивировать/разархивировать */
  const handleArchive = async (clientId: number, archive: boolean) => {
    try {
      await clientsApi.archive(clientId, archive);
      loadClients();
      loadStats();
    } catch {
      setDialog({ title: 'Ошибка', message: 'Не удалось выполнить операцию', variant: 'danger', onConfirm: () => setDialog(null) });
    }
  };

  /** Обработчик сортировки */
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortOrder('desc');
    }
  };

  /** Иконка сортировки */
  const getSortIcon = (field: SortField): string => {
    if (sortField !== field) return 'unfold_more';
    return sortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward';
  };

  /** Смена страницы */
  const handlePageChange = (page: number) => {
    loadClients(page);
  };

  /** Смена perPage */
  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
  };

  /** Форматирование бюджета */
  const formatBudget = (min: number | null, max: number | null): string => {
    if (!min && !max) return '—';
    const formatNum = (n: number) => {
      if (n >= 1000000) return `${(n / 1000000).toFixed(1)} млн`;
      if (n >= 1000) return `${(n / 1000).toFixed(0)} тыс`;
      return String(n);
    };
    if (min && max) return `${formatNum(min)} — ${formatNum(max)} ₽`;
    if (min) return `от ${formatNum(min)} ₽`;
    return `до ${formatNum(max!)} ₽`;
  };

  /** Форматирование даты */
  const formatDate = (dateStr: string | null): string => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
  };

  /** Проверка просроченного контакта */
  const isOverdue = (dateStr: string | null): boolean => {
    if (!dateStr) return false;
    return new Date(dateStr) < new Date();
  };

  return (
    <div className="clients-page">
      {/* Заголовок */}
      <div className="clients-header">
        <div className="clients-header-left">
          <h1>Клиенты</h1>
          {stats && (
            <div className="clients-stats-badges">
              <span className="stats-badge">{stats.total_active} активных</span>
              {stats.overdue_contacts > 0 && (
                <span className="stats-badge overdue">{stats.overdue_contacts} просрочено</span>
              )}
            </div>
          )}
        </div>
        <div className="clients-header-right">
          <div className="view-toggle">
            <Tooltip content="Табличный вид" position="bottom">
              <button
                className={`view-btn ${viewMode === 'list' ? 'active' : ''}`}
                onClick={() => setViewMode('list')}
              >
                <span className="material-icons">view_list</span>
              </button>
            </Tooltip>
            <Tooltip content="Канбан-доска" position="bottom">
              <button
                className={`view-btn ${viewMode === 'pipeline' ? 'active' : ''}`}
                onClick={() => setViewMode('pipeline')}
              >
                <span className="material-icons">view_kanban</span>
              </button>
            </Tooltip>
          </div>
          <button className="btn btn-primary" onClick={handleCreate}>
            <span className="material-icons">person_add</span>
            Новый клиент
          </button>
        </div>
      </div>

      {/* Фильтры (только для режима списка) */}
      {viewMode === 'list' && (
        <div className="card filters-card">
          <div className="card-header">
            <h3 className="card-title">
              <span className="material-icons">filter_list</span>
              Фильтры
            </h3>
            <div className="filter-header-actions">
              {(searchQuery || selectedType || selectedStageId || showArchived) && (
                <button className="btn btn-outline btn-sm" onClick={resetFilters}>
                  <span className="material-icons">clear</span>
                  Сбросить
                </button>
              )}
              <button
                className={`filter-toggle-btn ${!showFunnel ? 'collapsed' : ''}`}
                onClick={() => setShowFunnel(!showFunnel)}
              >
                <span className="material-icons">bar_chart</span>
                Воронка
                <span className="material-icons">expand_less</span>
              </button>
            </div>
          </div>
          <div className="card-body">
            <div className="filters-grid">
              <div className="filter-group">
                <label className="filter-label">Поиск</label>
                <input
                  type="text"
                  className="form-control"
                  placeholder="Имя, телефон, email..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && loadClients()}
                />
              </div>
              <div className="filter-group">
                <label className="filter-label">Тип</label>
                <select
                  className="filter-select"
                  value={selectedType}
                  onChange={(e) => setSelectedType(e.target.value as any)}
                >
                  <option value="">Все типы</option>
                  {Object.entries(CLIENT_TYPE_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </div>
              <div className="filter-group">
                <label className="filter-label">Стадия</label>
                <select
                  className="filter-select"
                  value={selectedStageId || ''}
                  onChange={(e) => setSelectedStageId(e.target.value ? Number(e.target.value) : null)}
                >
                  <option value="">Все стадии</option>
                  {stages.map(stage => (
                    <option key={stage.id} value={stage.id}>{stage.name}</option>
                  ))}
                </select>
              </div>
              <div className="filter-group">
                <label className="filter-label">Опции</label>
                <label className="filter-checkbox">
                  <input
                    type="checkbox"
                    checked={showArchived}
                    onChange={(e) => setShowArchived(e.target.checked)}
                  />
                  Показать архив
                </label>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Контент */}
      {viewMode === 'pipeline' ? (
        <Pipeline stages={stages} onClientClick={openClient} onRefresh={() => { loadStages(); loadStats(); }} refreshKey={refreshKey} />
      ) : showFunnel && stages.length > 0 ? (
        /* Воронка ЗАМЕНЯЕТ таблицу */
        <FunnelChart stages={stages} />
      ) : (
        <>
          {loading && isFirstLoad.current ? (
            <div className="loading-state">
              <div className="loading-spinner" />
              <p>Загрузка клиентов...</p>
            </div>
          ) : error ? (
            <div className="error-state">
              <span className="material-icons">error_outline</span>
              <p>{error}</p>
              <button onClick={() => loadClients()}>Повторить</button>
            </div>
          ) : clients.length === 0 && !isRefetching ? (
            <div className="empty-state">
              <span className="material-icons">people_outline</span>
              <p>{showArchived ? 'Архив пуст' : 'Клиентов пока нет'}</p>
              {!showArchived && (
                <button className="btn btn-primary" onClick={handleCreate}>
                  <span className="material-icons">person_add</span>
                  Добавить первого клиента
                </button>
              )}
            </div>
          ) : (
            <>
              {/* Таблица клиентов */}
              <div className="clients-table-wrapper">
                {isRefetching && <div className="table-refetch-overlay" />}
                <table className="clients-table">
                  <thead>
                    <tr>
                      <th
                        className={`sortable ${sortField === 'name' ? 'sorted' : ''}`}
                        onClick={() => handleSort('name')}
                      >
                        Имя
                        <span className="material-icons sort-icon">{getSortIcon('name')}</span>
                      </th>
                      <th>Телефон</th>
                      <th>Тип</th>
                      <th>Стадия</th>
                      <th
                        className={`sortable ${sortField === 'budget_max' ? 'sorted' : ''}`}
                        onClick={() => handleSort('budget_max')}
                      >
                        Бюджет
                        <span className="material-icons sort-icon">{getSortIcon('budget_max')}</span>
                      </th>
                      <th
                        className={`sortable ${sortField === 'next_contact_at' ? 'sorted' : ''}`}
                        onClick={() => handleSort('next_contact_at')}
                      >
                        След. контакт
                        <span className="material-icons sort-icon">{getSortIcon('next_contact_at')}</span>
                      </th>
                      <th
                        className={`sortable ${sortField === 'created_at' ? 'sorted' : ''}`}
                        onClick={() => handleSort('created_at')}
                      >
                        Создан
                        <span className="material-icons sort-icon">{getSortIcon('created_at')}</span>
                      </th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {clients.map(client => (
                      <tr
                        key={client.id}
                        className={`client-row ${client.is_archived ? 'archived' : ''}`}
                        onClick={() => openClient(client.id)}
                      >
                        <td className="cell-name">
                          <div className="cell-name-inner">
                            <span className="client-name">{client.name}</span>
                            {client.comment && (
                              <Tooltip content={client.comment} position="top">
                                <span className="client-comment-hint">
                                  <span className="material-icons">chat_bubble_outline</span>
                                </span>
                              </Tooltip>
                            )}
                          </div>
                        </td>
                        <td className="cell-phone">
                          {client.phone ? (
                            <a
                              href={`tel:${client.phone}`}
                              onClick={(e) => e.stopPropagation()}
                              className="phone-link"
                            >
                              {client.phone}
                            </a>
                          ) : '—'}
                        </td>
                        <td className="cell-type">
                          <span className={`type-badge type-${client.client_type}`}>
                            {CLIENT_TYPE_LABELS[client.client_type]}
                          </span>
                        </td>
                        <td className="cell-stage">
                          {client.pipeline_stage && (
                            <span
                              className="stage-badge"
                              style={{ backgroundColor: client.pipeline_stage.color + '20', color: client.pipeline_stage.color, borderColor: client.pipeline_stage.color }}
                            >
                              {client.pipeline_stage.name}
                            </span>
                          )}
                        </td>
                        <td className="cell-budget">{formatBudget(client.budget_min, client.budget_max)}</td>
                        <td className={`cell-date ${isOverdue(client.next_contact_at) ? 'overdue' : ''}`}>
                          {formatDate(client.next_contact_at)}
                        </td>
                        <td className="cell-date">{formatDate(client.created_at)}</td>
                        <td className="cell-actions" onClick={(e) => e.stopPropagation()}>
                          <div className="cell-actions-inner">
                            {client.is_archived ? (
                              <Tooltip content="Восстановить" position="top">
                                <button
                                  className="action-btn"
                                  onClick={() => handleArchive(client.id, false)}
                                >
                                  <span className="material-icons">unarchive</span>
                                </button>
                              </Tooltip>
                            ) : (
                              <Tooltip content="В архив" position="top">
                                <button
                                  className="action-btn"
                                  onClick={() => handleArchive(client.id, true)}
                                >
                                  <span className="material-icons">archive</span>
                                </button>
                              </Tooltip>
                            )}
                            <Tooltip content="Удалить" position="top">
                              <button
                                className="action-btn danger"
                                onClick={() => handleDelete(client.id)}
                              >
                                <span className="material-icons">delete_outline</span>
                              </button>
                            </Tooltip>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Пагинация */}
              {pagination.total_pages > 0 && (
                <Pagination
                  page={pagination.page}
                  totalPages={pagination.total_pages}
                  perPage={perPage}
                  total={pagination.total}
                  onPageChange={handlePageChange}
                  onPerPageChange={handlePerPageChange}
                />
              )}
            </>
          )}
        </>
      )}

      {/* Модальная форма создания/редактирования */}
      {showForm && (
        <ClientForm
          client={editingClient}
          stages={stages}
          onClose={() => { setShowForm(false); setEditingClient(null); }}
          onSaved={handleFormSaved}
        />
      )}

      {/* Диалог подтверждения */}
      {dialog && (
        <ConfirmDialog
          title={dialog.title}
          message={dialog.message}
          confirmText={dialog.confirmText}
          cancelText={dialog.cancelText}
          variant={dialog.variant}
          onConfirm={dialog.onConfirm}
          onCancel={() => setDialog(null)}
        />
      )}
    </div>
  );
}

export default Clients;
