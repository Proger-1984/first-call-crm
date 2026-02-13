import { useState, useEffect, useCallback } from 'react';
import { interactionsApi } from '../../services/api';
import type { Interaction, InteractionType } from '../../types/property';
import { INTERACTION_TYPE_LABELS, INTERACTION_TYPE_ICONS } from '../../types/property';
import './InteractionTimeline.css';

interface InteractionTimelineProps {
  /** ID объекта — показать таймлайн по всем связкам объекта */
  propertyId?: number;
  /** ID контакта — показать таймлайн по всем связкам контакта */
  contactId?: number;
  /** ID объекта + контакта — таймлайн конкретной связки */
  objectClientPropertyId?: number;
  objectClientContactId?: number;
  /** Callback для открытия формы создания взаимодействия */
  onAddInteraction?: () => void;
}

/** Форматирование даты для таймлайна */
function formatTimelineDate(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMinutes = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  if (diffMinutes < 1) return 'только что';
  if (diffMinutes < 60) return `${diffMinutes} мин. назад`;
  if (diffHours < 24) return `${diffHours} ч. назад`;
  if (diffDays < 7) return `${diffDays} дн. назад`;

  return date.toLocaleDateString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

export function InteractionTimeline({
  propertyId,
  contactId,
  objectClientPropertyId,
  objectClientContactId,
  onAddInteraction,
}: InteractionTimelineProps) {
  const [interactions, setInteractions] = useState<Interaction[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);

  const LIMIT = 20;

  const loadInteractions = useCallback(async (offset = 0, append = false) => {
    if (offset === 0) {
      setLoading(true);
    } else {
      setLoadingMore(true);
    }

    try {
      let response;

      if (objectClientPropertyId && objectClientContactId) {
        response = await interactionsApi.getByObjectClient(objectClientPropertyId, objectClientContactId, LIMIT, offset);
      } else if (propertyId) {
        response = await interactionsApi.getByProperty(propertyId, LIMIT, offset);
      } else if (contactId) {
        response = await interactionsApi.getByContact(contactId, LIMIT, offset);
      } else {
        return;
      }

      const data = response.data?.data;
      if (data) {
        setInteractions(prev => append ? [...prev, ...data.interactions] : data.interactions);
        setTotal(data.total);
      }
    } catch {
      // Ошибка загрузки — не критично
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, [propertyId, contactId, objectClientPropertyId, objectClientContactId]);

  useEffect(() => {
    loadInteractions();
  }, [loadInteractions]);

  const handleLoadMore = () => {
    loadInteractions(interactions.length, true);
  };

  if (loading) {
    return (
      <div className="interaction-timeline">
        <div className="timeline-loading">
          <div className="loading-spinner" />
        </div>
      </div>
    );
  }

  return (
    <div className="interaction-timeline">
      <div className="timeline-header">
        <h3>История взаимодействий ({total})</h3>
        {onAddInteraction && (
          <button className="btn btn-primary btn-sm" onClick={onAddInteraction}>
            <span className="material-icons">add</span>
            Добавить
          </button>
        )}
      </div>

      {interactions.length === 0 ? (
        <p className="timeline-empty">Нет записей</p>
      ) : (
        <div className="timeline-list">
          {interactions.map(interaction => (
            <div key={interaction.id} className={`timeline-item type-${interaction.type}`}>
              <div className="timeline-icon">
                <span className="material-icons">
                  {INTERACTION_TYPE_ICONS[interaction.type as InteractionType] || 'event'}
                </span>
              </div>
              <div className="timeline-content">
                <div className="timeline-content-header">
                  <span className="timeline-type-label">
                    {INTERACTION_TYPE_LABELS[interaction.type as InteractionType] || interaction.type}
                  </span>
                  <span className="timeline-date">{formatTimelineDate(interaction.interaction_at)}</span>
                </div>
                {interaction.description && (
                  <p className="timeline-description">{interaction.description}</p>
                )}
                <div className="timeline-meta">
                  {interaction.user && (
                    <span className="timeline-user">{interaction.user.name}</span>
                  )}
                  {interaction.contact && (
                    <span className="timeline-contact">{interaction.contact.name}</span>
                  )}
                  {interaction.property && (
                    <span className="timeline-property">{interaction.property.address}</span>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {interactions.length < total && (
        <button
          className="timeline-load-more"
          onClick={handleLoadMore}
          disabled={loadingMore}
        >
          {loadingMore ? 'Загрузка...' : 'Показать ещё'}
        </button>
      )}
    </div>
  );
}

export default InteractionTimeline;
