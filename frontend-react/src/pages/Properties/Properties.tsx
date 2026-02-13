import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { propertiesApi, clientsApi } from '../../services/api';
import { usePropertyStore } from '../../stores/propertyStore';
import { Tooltip } from '../../components/UI/Tooltip';
import { Pagination } from '../../components/UI/Pagination';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { PropertyForm } from './PropertyForm';
import { Pipeline } from './Pipeline';
import { FunnelChart } from '../Clients/FunnelChart';
import { StageSettings } from '../Clients/StageSettings';
import type {
  Property,
  PropertyFilters,
  PipelineStage,
  PipelineColumn,
  DealType,
} from '../../types/property';
import { DEAL_TYPE_LABELS } from '../../types/property';
import './Properties.css';

/** Конфигурация сортируемых колонок */
type SortField = 'created_at' | 'price' | 'address' | 'deal_type' | 'owner_name' | 'stage';

const SORT_COLUMNS: { field: SortField; label: string }[] = [
  { field: 'address', label: 'Адрес' },
  { field: 'price', label: 'Цена' },
  { field: 'deal_type', label: 'Тип сделки' },
  { field: 'owner_name', label: 'Собственник' },
  { field: 'created_at', label: 'Создан' },
];

interface PaginationInfo {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export function Properties() {
  const navigate = useNavigate();
  const {
    viewMode, setViewMode,
    searchQuery, setSearchQuery,
    selectedDealType, setSelectedDealType,
    selectedStageIds, setSelectedStageIds, toggleStageId,
    showArchived, setShowArchived,
    sortField, setSortField,
    sortOrder, setSortOrder,
    perPage, setPerPage,
    resetFilters,
  } = usePropertyStore();

  const [properties, setProperties] = useState<Property[]>([]);
  const [stages, setStages] = useState<PipelineStage[]>([]);
  const [pagination, setPagination] = useState<PaginationInfo>({ page: 1, per_page: perPage, total: 0, total_pages: 0 });
  const [loading, setLoading] = useState(true);
  const [isRefetching, setIsRefetching] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Флаг первой загрузки — для показа loading-state
  const isFirstLoad = useRef(true);

  // Воронка
  const [showFunnel, setShowFunnel] = useState(false);

  // Настройки стадий
  const [showStageSettings, setShowStageSettings] = useState(false);

  // Баг 2: Модальная форма создания объекта вместо navigate
  const [showCreateForm, setShowCreateForm] = useState(false);

  // Kanban-доска (колонки со связками объект+контакт)
  const [pipelineColumns, setPipelineColumns] = useState<PipelineColumn[]>([]);
  const [pipelineLoading, setPipelineLoading] = useState(false);

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

  /** Загрузка стадий (общие с клиентами) */
  const loadStages = useCallback(async () => {
    try {
      const response = await clientsApi.getStages();
      setStages(response.data?.data?.stages || []);
    } catch {
      // Стадии не загрузились — не критично
    }
  }, []);

  /** Загрузка объектов */
  const loadProperties = useCallback(async (page = 1) => {
    // При первой загрузке — полноценный loading, при рефетче — overlay
    if (isFirstLoad.current) {
      setLoading(true);
    } else {
      setIsRefetching(true);
    }
    setError(null);
    try {
      const filters: PropertyFilters = {
        page,
        per_page: perPage,
        sort: sortField,
        order: sortOrder,
      };

      if (searchQuery) filters.search = searchQuery;
      if (selectedDealType) filters.deal_type = selectedDealType;
      if (selectedStageIds.length > 0) filters.stage_ids = selectedStageIds.join(',');
      if (showArchived) filters.is_archived = true;

      const response = await propertiesApi.getAll(filters);
      const data = response.data?.data;
      setProperties(data?.properties || []);
      setPagination(data?.pagination || { page: 1, per_page: perPage, total: 0, total_pages: 0 });
      isFirstLoad.current = false;
    } catch {
      setError('Ошибка загрузки объектов');
    } finally {
      setLoading(false);
      setIsRefetching(false);
    }
  }, [searchQuery, selectedDealType, selectedStageIds, showArchived, sortField, sortOrder, perPage]);

  /** Загрузка kanban-доски */
  const loadPipeline = useCallback(async () => {
    setPipelineLoading(true);
    try {
      const response = await propertiesApi.getPipeline();
      setPipelineColumns(response.data?.data?.pipeline || []);
    } catch {
      // Ошибка загрузки pipeline — не критично
    } finally {
      setPipelineLoading(false);
    }
  }, []);

  /** Загрузка статистики */
  const loadStats = useCallback(async () => {
    try {
      const response = await propertiesApi.getStats();
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
      loadProperties();
    } else if (viewMode === 'pipeline') {
      loadPipeline();
    }
  }, [loadProperties, loadPipeline, viewMode]);

  // Перезагрузка pipeline при изменении refreshKey
  useEffect(() => {
    if (viewMode === 'pipeline' && refreshKey > 0) {
      loadPipeline();
    }
  }, [refreshKey, viewMode, loadPipeline]);

  /** Открыть карточку объекта */
  const openProperty = (propertyId: number) => {
    navigate(`/properties/${propertyId}`);
  };

  /** Перемещение карточки между стадиями (callback для Pipeline) */
  const handleMoveCard = async (cardId: number, propertyId: number, contactId: number, newStageId: number) => {
    try {
      await propertiesApi.moveContactStage(propertyId, contactId, newStageId);
      loadStages();
      loadStats();
    } catch {
      // Pipeline сам откатит UI при ошибке
    }
  };

  /** После сохранения стадий */
  const handleStagesSaved = () => {
    loadStages();
    loadStats();
    loadProperties();
    setRefreshKey(prev => prev + 1);
  };

  /** Удалить объект */
  const handleDelete = (propertyId: number) => {
    setDialog({
      title: 'Удаление объекта',
      message: 'Удалить объект? Это действие нельзя отменить.',
      confirmText: 'Удалить',
      cancelText: 'Отмена',
      variant: 'danger',
      onConfirm: async () => {
        setDialog(null);
        try {
          await propertiesApi.delete(propertyId);
          loadProperties();
          loadStats();
        } catch {
          setDialog({ title: 'Ошибка', message: 'Не удалось удалить объект', variant: 'danger', onConfirm: () => setDialog(null) });
        }
      },
    });
  };

  /** Архивировать/разархивировать */
  const handleArchive = async (propertyId: number, archive: boolean) => {
    try {
      await propertiesApi.archive(propertyId, archive);
      loadProperties();
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
    loadProperties(page);
  };

  /** Смена perPage */
  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
  };

  /** Применить фильтры (кнопка или Enter) */
  const handleApplyFilters = () => {
    loadProperties();
  };

  /** Сбросить все фильтры и перезагрузить */
  const handleResetFilters = () => {
    resetFilters();
    // После сброса стора, loadProperties вызовется через useEffect
  };

  /** Форматирование цены */
  const formatPrice = (price: number | null): string => {
    if (!price) return '—';
    return price.toLocaleString('ru-RU') + ' \u20BD';
  };

  /** Форматирование даты */
  const formatDate = (dateStr: string | null): string => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
  };

  /** Получить "лучшую" стадию из связок объект-контакт */
  const getPropertyStage = (property: Property) => {
    const firstClient = property.object_clients?.[0];
    return firstClient?.pipeline_stage || null;
  };

  /** Количество привязанных клиентов */
  const getClientsCount = (property: Property): number => {
    return property.object_clients?.length || 0;
  };

  /** Есть ли активные фильтры */
  const hasActiveFilters = searchQuery || selectedDealType || selectedStageIds.length > 0 || showArchived;

  return (
    <div className="properties-page">
      {/* Заголовок */}
      <div className="properties-header">
        <div className="properties-header-left">
          <h1>Объекты</h1>
          {stats && (
            <div className="properties-stats-badges">
              <span className="stats-badge">{stats.total_active} активных</span>
              {stats.overdue_contacts > 0 && (
                <span className="stats-badge overdue">{stats.overdue_contacts} просрочено</span>
              )}
            </div>
          )}
        </div>
        <div className="properties-header-right">
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
          <Tooltip content="Настройка стадий" position="bottom">
            <button className="btn btn-outline btn-icon" onClick={() => setShowStageSettings(true)}>
              <span className="material-icons">tune</span>
            </button>
          </Tooltip>
          <button className="btn btn-primary" onClick={() => setShowCreateForm(true)}>
            <span className="material-icons">add_home</span>
            Создать объект
          </button>
        </div>
      </div>

      {/* Фильтры (только для режима списка) — стиль AdminBilling */}
      {viewMode === 'list' && (
        <div className="card">
          <div className="properties-filters">
            <div className="properties-filters-row">
              {/* Поиск */}
              <div className="properties-filter-group">
                <label>Поиск</label>
                <div className="filter-search-wrapper">
                  <input
                    type="text"
                    className="form-control filter-search-input"
                    placeholder="Адрес, собственник, телефон..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && handleApplyFilters()}
                  />
                  {searchQuery && (
                    <button
                      className="filter-search-clear"
                      onClick={() => setSearchQuery('')}
                      title="Очистить"
                    >
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
              </div>

              {/* Тип сделки — чипы */}
              <div className="properties-filter-group">
                <label>Тип сделки</label>
                <div className="filter-chips">
                  {(Object.entries(DEAL_TYPE_LABELS) as [DealType, string][]).map(([value, label]) => (
                    <button
                      key={value}
                      className={`filter-chip ${selectedDealType === value ? 'active' : ''}`}
                      onClick={() => setSelectedDealType(selectedDealType === value ? '' : value)}
                    >
                      {label}
                      {selectedDealType === value && (
                        <span
                          className="chip-clear"
                          onClick={(e) => { e.stopPropagation(); setSelectedDealType(''); }}
                        >
                          &times;
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              </div>

              {/* Стадии — чипы, множественный выбор */}
              <div className="properties-filter-group">
                <label>Стадия</label>
                <div className="filter-chips">
                  {stages.map(stage => (
                    <button
                      key={stage.id}
                      className={`filter-chip ${selectedStageIds.includes(stage.id) ? 'active' : ''}`}
                      onClick={() => toggleStageId(stage.id)}
                    >
                      <span className="chip-dot" style={{ backgroundColor: stage.color }} />
                      {stage.name}
                      {selectedStageIds.includes(stage.id) && (
                        <span
                          className="chip-clear"
                          onClick={(e) => { e.stopPropagation(); toggleStageId(stage.id); }}
                        >
                          &times;
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              </div>

              {/* Показать архив — чекбокс */}
              <div className="properties-filter-group">
                <label>&nbsp;</label>
                <label className={`filter-checkbox-inline ${showArchived ? 'active' : ''}`}>
                  <input
                    type="checkbox"
                    checked={showArchived}
                    onChange={(e) => setShowArchived(e.target.checked)}
                  />
                  Архив
                </label>
              </div>

              {/* Кнопки "Применить" / "Сбросить" */}
              <div className="properties-filter-actions">
                <button className="btn btn-primary" onClick={handleApplyFilters}>
                  <span className="material-icons">search</span>
                  Применить
                </button>
                {hasActiveFilters && (
                  <button className="btn btn-secondary" onClick={handleResetFilters}>
                    <span className="material-icons">clear</span>
                    Сбросить
                  </button>
                )}
              </div>

              {/* Кнопка воронки */}
              {stages.length > 0 && (
                <button
                  className={`properties-funnel-btn ${showFunnel ? 'active' : ''}`}
                  onClick={() => setShowFunnel(!showFunnel)}
                >
                  <span className="material-icons">bar_chart</span>
                  Воронка
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Контент */}
      {viewMode === 'pipeline' ? (
        pipelineLoading ? (
          <div className="loading-state">
            <div className="loading-spinner" />
            <p>Загрузка воронки...</p>
          </div>
        ) : (
          <Pipeline
            stages={pipelineColumns}
            onMoveCard={handleMoveCard}
            onCardClick={openProperty}
          />
        )
      ) : showFunnel && stages.length > 0 ? (
        /* Воронка ЗАМЕНЯЕТ таблицу */
        <FunnelChart stages={stages} />
      ) : (
        <>
          {loading && isFirstLoad.current ? (
            <div className="loading-state">
              <div className="loading-spinner" />
              <p>Загрузка объектов...</p>
            </div>
          ) : error ? (
            <div className="error-state">
              <span className="material-icons">error_outline</span>
              <p>{error}</p>
              <button onClick={() => loadProperties()}>Повторить</button>
            </div>
          ) : properties.length === 0 && !isRefetching ? (
            <div className="empty-state">
              <span className="material-icons">home_work</span>
              <p>{showArchived ? 'Архив пуст' : 'Объектов пока нет'}</p>
              {!showArchived && (
                <button className="btn btn-primary" onClick={() => setShowCreateForm(true)}>
                  <span className="material-icons">add_home</span>
                  Добавить первый объект
                </button>
              )}
            </div>
          ) : (
            <>
              {/* Таблица объектов */}
              <div className="properties-table-wrapper">
                {isRefetching && <div className="table-refetch-overlay" />}
                <table className="properties-table">
                  <thead>
                    <tr>
                      <th
                        className={`sortable ${sortField === 'address' ? 'sorted' : ''}`}
                        onClick={() => handleSort('address')}
                      >
                        Адрес / Название
                        <span className="material-icons sort-icon">{getSortIcon('address')}</span>
                      </th>
                      <th
                        className={`sortable ${sortField === 'price' ? 'sorted' : ''}`}
                        onClick={() => handleSort('price')}
                      >
                        Цена
                        <span className="material-icons sort-icon">{getSortIcon('price')}</span>
                      </th>
                      <th
                        className={`sortable ${sortField === 'deal_type' ? 'sorted' : ''}`}
                        onClick={() => handleSort('deal_type')}
                      >
                        Тип сделки
                        <span className="material-icons sort-icon">{getSortIcon('deal_type')}</span>
                      </th>
                      <th
                        className={`sortable ${sortField === 'owner_name' ? 'sorted' : ''}`}
                        onClick={() => handleSort('owner_name')}
                      >
                        Собственник
                        <span className="material-icons sort-icon">{getSortIcon('owner_name')}</span>
                      </th>
                      <th>Клиенты</th>
                      <th
                        className={`sortable ${sortField === 'stage' ? 'sorted' : ''}`}
                        onClick={() => handleSort('stage')}
                      >
                        Стадия
                        <span className="material-icons sort-icon">{getSortIcon('stage')}</span>
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
                    {properties.map(property => {
                      const clientsCount = getClientsCount(property);
                      const propertyStage = getPropertyStage(property);

                      return (
                        <tr
                          key={property.id}
                          className={property.is_archived ? 'archived' : ''}
                          onClick={() => openProperty(property.id)}
                        >
                          <td className="cell-address">
                            <div className="cell-address-inner">
                              <span className="property-title">
                                {property.address || property.title || '—'}
                              </span>
                              {property.address && property.title && (
                                <span className="property-subtitle">{property.title}</span>
                              )}
                            </div>
                          </td>
                          <td className="cell-price">{formatPrice(property.price)}</td>
                          <td>
                            <span className={`deal-badge deal-${property.deal_type}`}>
                              {DEAL_TYPE_LABELS[property.deal_type]}
                            </span>
                          </td>
                          <td className="cell-owner">
                            {property.owner_name ? (
                              <div className="cell-owner-inner">
                                <span className="owner-name">{property.owner_name}</span>
                                {property.owner_phone && (
                                  <span className="owner-phone">{property.owner_phone}</span>
                                )}
                              </div>
                            ) : '—'}
                          </td>
                          <td className="cell-clients">
                            <span className={`clients-count-badge ${clientsCount === 0 ? 'empty' : ''}`}>
                              {clientsCount}
                            </span>
                          </td>
                          <td className="cell-stage">
                            {propertyStage && (
                              <span
                                className="stage-badge"
                                style={{
                                  backgroundColor: propertyStage.color + '20',
                                  color: propertyStage.color,
                                  borderColor: propertyStage.color,
                                }}
                              >
                                {propertyStage.name}
                              </span>
                            )}
                          </td>
                          <td className="cell-date">{formatDate(property.created_at)}</td>
                          <td className="cell-actions" onClick={(e) => e.stopPropagation()}>
                            <div className="cell-actions-inner">
                              {property.is_archived ? (
                                <Tooltip content="Восстановить" position="top">
                                  <button
                                    className="action-btn"
                                    onClick={() => handleArchive(property.id, false)}
                                  >
                                    <span className="material-icons">unarchive</span>
                                  </button>
                                </Tooltip>
                              ) : (
                                <Tooltip content="В архив" position="top">
                                  <button
                                    className="action-btn"
                                    onClick={() => handleArchive(property.id, true)}
                                  >
                                    <span className="material-icons">archive</span>
                                  </button>
                                </Tooltip>
                              )}
                              <Tooltip content="Удалить" position="top">
                                <button
                                  className="action-btn danger"
                                  onClick={() => handleDelete(property.id)}
                                >
                                  <span className="material-icons">delete_outline</span>
                                </button>
                              </Tooltip>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
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

      {/* Модальная форма создания объекта */}
      {showCreateForm && (
        <PropertyForm
          isOpen={showCreateForm}
          onClose={() => setShowCreateForm(false)}
          onSaved={() => { setShowCreateForm(false); loadProperties(); loadStats(); }}
        />
      )}

      {/* Модальное окно настроек стадий */}
      {showStageSettings && (
        <StageSettings
          onClose={() => setShowStageSettings(false)}
          onSaved={handleStagesSaved}
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

export default Properties;
