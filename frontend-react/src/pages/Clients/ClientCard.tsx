import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { clientsApi } from '../../services/api';
import { Tooltip } from '../../components/UI/Tooltip';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { ClientForm } from './ClientForm';
import { StageSettings } from './StageSettings';
import { ListingPicker } from './ListingPicker';
import type { Client, PipelineStage, ClientListingStatus } from '../../types/client';
import { CLIENT_TYPE_LABELS, LISTING_STATUS_LABELS } from '../../types/client';
import './ClientCard.css';

export function ClientCard() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const clientId = Number(id);

  const [client, setClient] = useState<Client | null>(null);
  const [stages, setStages] = useState<PipelineStage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showEditForm, setShowEditForm] = useState(false);
  const [showStageSettings, setShowStageSettings] = useState(false);
  const [showListingPicker, setShowListingPicker] = useState(false);

  // Диалог подтверждения
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  /** Загрузка клиента */
  const loadClient = useCallback(async () => {
    if (!clientId) return;
    setLoading(true);
    setError(null);
    try {
      const response = await clientsApi.getById(clientId);
      setClient(response.data?.data?.client || null);
    } catch {
      setError('Ошибка загрузки клиента');
    } finally {
      setLoading(false);
    }
  }, [clientId]);

  /** Загрузка стадий */
  const loadStages = useCallback(async () => {
    try {
      const response = await clientsApi.getStages();
      setStages(response.data?.data?.stages || []);
    } catch {
      // Не критично
    }
  }, []);

  useEffect(() => {
    loadClient();
    loadStages();
  }, [loadClient, loadStages]);

  /** Показать ошибку через диалог */
  const showError = (message: string) => {
    setDialog({ title: 'Ошибка', message, variant: 'danger', onConfirm: () => setDialog(null) });
  };

  /** Изменить стадию (оптимистичное обновление) */
  const handleStageChange = async (stageId: number) => {
    if (!client) return;

    // Находим целевую стадию
    const targetStage = stages.find(s => s.id === stageId);
    if (!targetStage || client.pipeline_stage?.id === stageId) return;

    // Оптимистичное обновление: сразу меняем стадию локально
    const previousStage = client.pipeline_stage;
    setClient(prev => prev ? {
      ...prev,
      pipeline_stage: {
        id: targetStage.id,
        name: targetStage.name,
        color: targetStage.color,
        is_final: targetStage.is_final,
      },
    } : null);

    try {
      await clientsApi.moveStage(clientId, stageId);
    } catch {
      // Откат при ошибке
      setClient(prev => prev ? { ...prev, pipeline_stage: previousStage } : null);
      showError('Ошибка смены стадии');
    }
  };

  /** Удалить объявление из подборки */
  const handleRemoveListing = async (listingId: number) => {
    try {
      await clientsApi.removeListing(clientId, listingId);
      loadClient();
    } catch {
      showError('Ошибка удаления');
    }
  };

  /** Изменить статус объявления в подборке */
  const handleListingStatusChange = async (listingId: number, newStatus: ClientListingStatus) => {
    try {
      await clientsApi.updateListingStatus(clientId, listingId, newStatus);
      loadClient();
    } catch {
      showError('Ошибка обновления');
    }
  };

  /** Удалить критерий поиска */
  const handleDeleteCriteria = async (criteriaId: number) => {
    try {
      await clientsApi.deleteCriteria(criteriaId);
      loadClient();
    } catch {
      showError('Ошибка удаления критерия');
    }
  };

  /** Архивировать */
  const handleArchive = async () => {
    if (!client) return;
    const archive = !client.is_archived;
    try {
      await clientsApi.archive(clientId, archive);
      loadClient();
    } catch {
      showError('Не удалось выполнить операцию');
    }
  };

  /** Удалить клиента */
  const handleDelete = () => {
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
          navigate('/clients');
        } catch {
          showError('Ошибка удаления');
        }
      },
    });
  };

  /** Форматирование даты */
  const formatDate = (dateStr: string | null): string => {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('ru-RU', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  };

  /** Форматирование цены */
  const formatPrice = (value: number | null): string => {
    if (!value) return '—';
    return new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
  };

  if (loading) {
    return (
      <div className="client-card-page">
        <div className="loading-state">
          <div className="loading-spinner" />
          <p>Загрузка...</p>
        </div>
      </div>
    );
  }

  if (error || !client) {
    return (
      <div className="client-card-page">
        <div className="error-state">
          <span className="material-icons">error_outline</span>
          <p>{error || 'Клиент не найден'}</p>
          <button onClick={() => navigate('/clients')}>К списку</button>
        </div>
      </div>
    );
  }

  return (
    <div className="client-card-page">
      {/* Шапка */}
      <div className="card-top-bar">
        <button className="back-btn" onClick={() => navigate('/clients')}>
          <span className="material-icons">arrow_back</span>
          Клиенты
        </button>
        <div className="card-top-actions">
          <Tooltip content="Редактировать" position="bottom">
            <button className="action-btn" onClick={() => setShowEditForm(true)}>
              <span className="material-icons">edit</span>
            </button>
          </Tooltip>
          <Tooltip content={client.is_archived ? 'Восстановить' : 'В архив'} position="bottom">
            <button className="action-btn" onClick={handleArchive}>
              <span className="material-icons">{client.is_archived ? 'unarchive' : 'archive'}</span>
            </button>
          </Tooltip>
          <Tooltip content="Удалить" position="bottom">
            <button className="action-btn danger" onClick={handleDelete}>
              <span className="material-icons">delete_outline</span>
            </button>
          </Tooltip>
        </div>
      </div>

      <div className="card-layout">
        {/* Основная информация */}
        <div className="card-main">
          <div className="card-header-section">
            <h1 className="card-client-name">{client.name}</h1>
            <div className="card-badges">
              <span className={`type-badge type-${client.client_type}`}>
                {CLIENT_TYPE_LABELS[client.client_type]}
              </span>
              {client.is_archived && <span className="archived-badge">Архив</span>}
            </div>
          </div>

          {/* Стадия воронки */}
          <div className="card-section">
            <div className="section-header">
              <h3>Стадия воронки</h3>
              <Tooltip content="Настроить стадии" position="top">
                <button className="settings-btn" onClick={() => setShowStageSettings(true)}>
                  <span className="material-icons">settings</span>
                </button>
              </Tooltip>
            </div>
            <div className="stage-selector">
              {stages.map(stage => {
                const isActive = client.pipeline_stage?.id === stage.id;
                return (
                  <button
                    key={stage.id}
                    className={`stage-option ${isActive ? 'active' : ''}`}
                    style={{
                      borderColor: stage.color,
                      backgroundColor: isActive ? stage.color : 'transparent',
                      color: isActive ? '#fff' : stage.color,
                      boxShadow: isActive ? `0 2px 8px ${stage.color}40` : 'none',
                    }}
                    onClick={() => handleStageChange(stage.id)}
                  >
                    {stage.name}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Комментарий */}
          {client.comment && (
            <div className="card-section">
              <h3>Комментарий</h3>
              <p className="card-comment">{client.comment}</p>
            </div>
          )}

          {/* Критерии поиска */}
          <div className="card-section">
            <h3>Критерии поиска</h3>
            {client.search_criteria && client.search_criteria.length > 0 ? (
              <div className="criteria-list">
                {client.search_criteria.map(criteria => (
                  <div key={criteria.id} className="criteria-item">
                    <div className="criteria-content">
                      {criteria.category && <span className="criteria-tag">{criteria.category.name}</span>}
                      {criteria.location && <span className="criteria-tag">{criteria.location.name}</span>}
                      {criteria.price_min || criteria.price_max ? (
                        <span className="criteria-price">
                          {criteria.price_min ? `от ${formatPrice(criteria.price_min)}` : ''}
                          {criteria.price_min && criteria.price_max ? ' — ' : ''}
                          {criteria.price_max ? `до ${formatPrice(criteria.price_max)}` : ''}
                        </span>
                      ) : null}
                      {criteria.area_min || criteria.area_max ? (
                        <span className="criteria-area">
                          {criteria.area_min ? `от ${criteria.area_min}` : ''}–{criteria.area_max ? `${criteria.area_max}` : ''} м²
                        </span>
                      ) : null}
                      {criteria.notes && <span className="criteria-notes">{criteria.notes}</span>}
                    </div>
                    <button className="remove-btn" onClick={() => handleDeleteCriteria(criteria.id)}>
                      <span className="material-icons">close</span>
                    </button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="empty-text">Нет критериев поиска</p>
            )}
          </div>

          {/* Подборка объявлений */}
          <div className="card-section">
            <div className="section-header">
              <h3>Подборка объявлений ({client.listings_count || 0})</h3>
              <button className="btn-primary btn-sm" onClick={() => setShowListingPicker(true)}>
                <span className="material-icons">add</span>
                Добавить
              </button>
            </div>
            {client.listings && client.listings.length > 0 ? (
              <div className="listings-list">
                {client.listings.map(cl => (
                  <div key={cl.id} className="listing-item">
                    <div className="listing-info">
                      <div className="listing-title">
                        {cl.listing ? (
                          <a href={cl.listing.url || '#'} target="_blank" rel="noreferrer">{cl.listing.title || 'Без заголовка'}</a>
                        ) : `Объявление #${cl.listing_id}`}
                      </div>
                      {cl.listing && (
                        <div className="listing-details">
                          {cl.listing.price && <span>{formatPrice(cl.listing.price)}</span>}
                          {cl.listing.address && <span>{cl.listing.address}</span>}
                        </div>
                      )}
                      {cl.comment && <div className="listing-comment">{cl.comment}</div>}
                    </div>
                    <div className="listing-actions">
                      <select
                        value={cl.status}
                        onChange={(e) => handleListingStatusChange(cl.listing_id, e.target.value as ClientListingStatus)}
                        className={`filter-select listing-status status-${cl.status}`}
                      >
                        {Object.entries(LISTING_STATUS_LABELS).map(([value, label]) => (
                          <option key={value} value={value}>{label}</option>
                        ))}
                      </select>
                      <button className="remove-btn" onClick={() => handleRemoveListing(cl.listing_id)}>
                        <span className="material-icons">close</span>
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="empty-text">Нет привязанных объявлений</p>
            )}
          </div>
        </div>

        {/* Боковая панель */}
        <div className="card-sidebar">
          <div className="sidebar-section">
            <h4>Контакты</h4>
            <div className="contact-list">
              {client.phone && (
                <div className="contact-item">
                  <span className="material-icons">phone</span>
                  <a href={`tel:${client.phone}`}>{client.phone}</a>
                </div>
              )}
              {client.phone_secondary && (
                <div className="contact-item">
                  <span className="material-icons">phone</span>
                  <a href={`tel:${client.phone_secondary}`}>{client.phone_secondary}</a>
                </div>
              )}
              {client.email && (
                <div className="contact-item">
                  <span className="material-icons">email</span>
                  <a href={`mailto:${client.email}`}>{client.email}</a>
                </div>
              )}
              {client.telegram_username && (
                <div className="contact-item">
                  <span className="material-icons">telegram</span>
                  <a href={`https://t.me/${client.telegram_username}`} target="_blank" rel="noreferrer">
                    @{client.telegram_username}
                  </a>
                </div>
              )}
              {!client.phone && !client.email && !client.telegram_username && (
                <p className="empty-text">Нет контактов</p>
              )}
            </div>
          </div>

          <div className="sidebar-section">
            <h4>Бюджет</h4>
            <p>{client.budget_min || client.budget_max
              ? `${client.budget_min ? formatPrice(client.budget_min) : '—'} — ${client.budget_max ? formatPrice(client.budget_max) : '—'}`
              : 'Не указан'
            }</p>
          </div>

          <div className="sidebar-section">
            <h4>Источник</h4>
            <p>{client.source_type || 'Не указан'}</p>
            {client.source_details && <p className="source-details">{client.source_details}</p>}
          </div>

          <div className="sidebar-section">
            <h4>Даты</h4>
            <div className="dates-list">
              <div className="date-item">
                <span>Создан:</span>
                <span>{formatDate(client.created_at)}</span>
              </div>
              <div className="date-item">
                <span>Последний контакт:</span>
                <span>{formatDate(client.last_contact_at)}</span>
              </div>
              <div className={`date-item ${client.next_contact_at && new Date(client.next_contact_at) < new Date() ? 'overdue' : ''}`}>
                <span>Следующий контакт:</span>
                <span>{formatDate(client.next_contact_at)}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Модальные окна */}
      {showEditForm && (
        <ClientForm
          client={client}
          stages={stages}
          onClose={() => setShowEditForm(false)}
          onSaved={() => { setShowEditForm(false); loadClient(); }}
        />
      )}

      {showStageSettings && (
        <StageSettings
          onClose={() => setShowStageSettings(false)}
          onSaved={() => { setShowStageSettings(false); loadStages(); }}
        />
      )}

      {showListingPicker && (
        <ListingPicker
          clientId={clientId}
          onClose={() => setShowListingPicker(false)}
          onAdded={() => { setShowListingPicker(false); loadClient(); }}
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

export default ClientCard;
