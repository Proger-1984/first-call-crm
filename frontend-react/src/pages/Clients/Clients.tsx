import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { clientsApi } from '../../services/api';
import { useClientStore } from '../../stores/clientStore';
import { Pipeline } from './Pipeline';
import { ClientForm } from './ClientForm';
import type { Client, ClientFilters, PipelineStage } from '../../types/client';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import './Clients.css';

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
    resetFilters,
  } = useClientStore();

  const [clients, setClients] = useState<Client[]>([]);
  const [stages, setStages] = useState<PipelineStage[]>([]);
  const [pagination, setPagination] = useState<PaginationInfo>({ page: 1, per_page: 20, total: 0, total_pages: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Форма создания/редактирования
  const [showForm, setShowForm] = useState(false);
  const [editingClient, setEditingClient] = useState<Client | null>(null);

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
    setLoading(true);
    setError(null);
    try {
      const filters: ClientFilters = {
        page,
        per_page: 20,
        sort: 'created_at',
        order: 'desc',
      };

      if (searchQuery) filters.search = searchQuery;
      if (selectedType) filters.client_type = selectedType;
      if (selectedStageId) filters.stage_id = selectedStageId;
      if (showArchived) filters.is_archived = true;

      const response = await clientsApi.getList(filters);
      const data = response.data?.data;
      setClients(data?.clients || []);
      setPagination(data?.pagination || { page: 1, per_page: 20, total: 0, total_pages: 0 });
    } catch {
      setError('Ошибка загрузки клиентов');
    } finally {
      setLoading(false);
    }
  }, [searchQuery, selectedType, selectedStageId, showArchived]);

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
  };

  /** Удалить клиента */
  const handleDelete = async (clientId: number) => {
    if (!confirm('Удалить клиента? Это действие нельзя отменить.')) return;
    try {
      await clientsApi.delete(clientId);
      loadClients();
      loadStats();
    } catch {
      alert('Ошибка удаления клиента');
    }
  };

  /** Архивировать/разархивировать */
  const handleArchive = async (clientId: number, archive: boolean) => {
    try {
      await clientsApi.archive(clientId, archive);
      loadClients();
      loadStats();
    } catch {
      alert('Ошибка');
    }
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
            <button
              className={`view-btn ${viewMode === 'list' ? 'active' : ''}`}
              onClick={() => setViewMode('list')}
              title="Список"
            >
              <span className="material-icons">view_list</span>
            </button>
            <button
              className={`view-btn ${viewMode === 'pipeline' ? 'active' : ''}`}
              onClick={() => setViewMode('pipeline')}
              title="Kanban"
            >
              <span className="material-icons">view_kanban</span>
            </button>
          </div>
          <button className="btn-primary" onClick={handleCreate}>
            <span className="material-icons">person_add</span>
            Новый клиент
          </button>
        </div>
      </div>

      {/* Фильтры (только для режима списка) */}
      {viewMode === 'list' && (
        <div className="clients-filters">
          <div className="filter-search">
            <span className="material-icons">search</span>
            <input
              type="text"
              placeholder="Поиск по имени, телефону, email..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && loadClients()}
            />
          </div>
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
          <label className="filter-checkbox">
            <input
              type="checkbox"
              checked={showArchived}
              onChange={(e) => setShowArchived(e.target.checked)}
            />
            Архив
          </label>
          {(searchQuery || selectedType || selectedStageId || showArchived) && (
            <button className="filter-reset" onClick={resetFilters}>
              <span className="material-icons">clear</span>
              Сбросить
            </button>
          )}
        </div>
      )}

      {/* Контент */}
      {viewMode === 'pipeline' ? (
        <Pipeline stages={stages} onClientClick={openClient} onRefresh={() => { loadStages(); loadStats(); }} />
      ) : (
        <>
          {loading ? (
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
          ) : clients.length === 0 ? (
            <div className="empty-state">
              <span className="material-icons">people_outline</span>
              <p>{showArchived ? 'Архив пуст' : 'Клиентов пока нет'}</p>
              {!showArchived && (
                <button className="btn-primary" onClick={handleCreate}>
                  <span className="material-icons">person_add</span>
                  Добавить первого клиента
                </button>
              )}
            </div>
          ) : (
            <>
              {/* Таблица клиентов */}
              <div className="clients-table-wrapper">
                <table className="clients-table">
                  <thead>
                    <tr>
                      <th>Имя</th>
                      <th>Телефон</th>
                      <th>Тип</th>
                      <th>Стадия</th>
                      <th>Бюджет</th>
                      <th>Следующий контакт</th>
                      <th>Создан</th>
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
                          <span className="client-name">{client.name}</span>
                          {client.comment && (
                            <span className="client-comment-hint" title={client.comment}>
                              <span className="material-icons">chat_bubble_outline</span>
                            </span>
                          )}
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
                          {client.is_archived ? (
                            <button
                              className="action-btn"
                              title="Восстановить"
                              onClick={() => handleArchive(client.id, false)}
                            >
                              <span className="material-icons">unarchive</span>
                            </button>
                          ) : (
                            <button
                              className="action-btn"
                              title="В архив"
                              onClick={() => handleArchive(client.id, true)}
                            >
                              <span className="material-icons">archive</span>
                            </button>
                          )}
                          <button
                            className="action-btn danger"
                            title="Удалить"
                            onClick={() => handleDelete(client.id)}
                          >
                            <span className="material-icons">delete_outline</span>
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Пагинация */}
              {pagination.total_pages > 1 && (
                <div className="clients-pagination">
                  <button
                    disabled={pagination.page <= 1}
                    onClick={() => loadClients(pagination.page - 1)}
                  >
                    <span className="material-icons">chevron_left</span>
                  </button>
                  <span className="pagination-info">
                    {pagination.page} из {pagination.total_pages} ({pagination.total} клиентов)
                  </span>
                  <button
                    disabled={pagination.page >= pagination.total_pages}
                    onClick={() => loadClients(pagination.page + 1)}
                  >
                    <span className="material-icons">chevron_right</span>
                  </button>
                </div>
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
    </div>
  );
}

export default Clients;
