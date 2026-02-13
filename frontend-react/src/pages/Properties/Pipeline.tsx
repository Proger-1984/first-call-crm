import { useState, useRef, useCallback } from 'react';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { Tooltip } from '../../components/UI/Tooltip';
import type { PipelineColumn, PipelineCard, DealType } from '../../types/property';
import { DEAL_TYPE_LABELS } from '../../types/property';
import './Pipeline.css';

interface PipelineProps {
  /** Колонки kanban (стадии воронки с карточками) */
  stages: PipelineColumn[];
  /** Callback при перемещении карточки между стадиями */
  onMoveCard: (cardId: number, propertyId: number, contactId: number, newStageId: number) => void;
  /** Callback при клике на карточку (открытие карточки объекта) */
  onCardClick?: (propertyId: number) => void;
  /** Callback для быстрого добавления взаимодействия */
  onAddInteraction?: (propertyId: number, contactId: number) => void;
  /** Callback для быстрого добавления напоминания */
  onAddReminder?: (propertyId: number, contactId: number) => void;
}

/** Относительная дата: "через Xд.", "Xд. назад", "сегодня" */
function getRelativeTime(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = date.getTime() - now.getTime();
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  if (diffDays < -1) return `${Math.abs(diffDays)}д. назад`;
  if (diffDays === -1) return 'вчера';
  if (diffDays === 0) return 'сегодня';
  if (diffDays === 1) return 'завтра';
  return `через ${diffDays}д.`;
}

/** Форматирование цены: 5200000 -> "5.2 млн", 850000 -> "850 тыс." */
function formatPrice(price: number | null): string {
  if (!price) return '';
  if (price >= 1_000_000) {
    const millions = price / 1_000_000;
    // Убираем .0 для целых
    const formatted = millions % 1 === 0 ? String(millions) : millions.toFixed(1);
    return `${formatted} млн`;
  }
  if (price >= 1_000) {
    return `${Math.round(price / 1_000)} тыс.`;
  }
  return String(price);
}

/** Краткое описание объекта: "2к, 65м², 15 этаж" */
function getPropertySummary(property: PipelineCard['property']): string {
  if (!property) return 'Объект не найден';
  const parts: string[] = [];
  if (property.rooms) parts.push(`${property.rooms}к`);
  if (property.area) parts.push(`${property.area}м\u00B2`);
  if (property.floor) parts.push(`${property.floor} этаж`);
  return parts.length > 0 ? parts.join(', ') : (property.title || 'Без описания');
}

export function Pipeline({ stages, onMoveCard, onCardClick, onAddInteraction, onAddReminder }: PipelineProps) {
  const [columns, setColumns] = useState<PipelineColumn[]>(stages);

  // Drag-n-drop
  const dragCardRef = useRef<{
    cardId: number;
    propertyId: number;
    contactId: number;
    sourceColumnId: number;
  } | null>(null);
  const [dragOverColumnId, setDragOverColumnId] = useState<number | null>(null);
  const [draggingCardId, setDraggingCardId] = useState<number | null>(null);

  // Диалог для ошибок
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  // Синхронизация props -> state при изменении stages извне
  const prevStagesRef = useRef(stages);
  if (prevStagesRef.current !== stages) {
    prevStagesRef.current = stages;
    setColumns(stages);
  }

  /** Перемещение карточки на другую стадию (оптимистичное обновление) */
  const moveCard = useCallback(async (
    cardId: number,
    propertyId: number,
    contactId: number,
    sourceColumnId: number,
    targetStageId: number,
  ) => {
    if (sourceColumnId === targetStageId) return;

    // Сохраняем предыдущее состояние для отката
    const previousColumns = columns;

    // Оптимистичное обновление: перемещаем карточку локально
    setColumns(prevColumns => {
      const newColumns = prevColumns.map(col => ({ ...col, cards: [...col.cards] }));
      const sourceCol = newColumns.find(c => c.id === sourceColumnId);
      const targetCol = newColumns.find(c => c.id === targetStageId);
      if (!sourceCol || !targetCol) return prevColumns;

      const cardIndex = sourceCol.cards.findIndex(c => c.id === cardId);
      if (cardIndex === -1) return prevColumns;

      const [movedCard] = sourceCol.cards.splice(cardIndex, 1);
      targetCol.cards.push(movedCard);
      return newColumns;
    });

    try {
      // Вызываем внешний callback (API-запрос делается родителем)
      onMoveCard(cardId, propertyId, contactId, targetStageId);
    } catch {
      // Откат при ошибке
      setColumns(previousColumns);
      setDialog({
        title: 'Ошибка',
        message: 'Не удалось переместить карточку',
        variant: 'danger',
        onConfirm: () => setDialog(null),
      });
    }
  }, [columns, onMoveCard]);

  /** Drag start */
  const handleDragStart = (card: PipelineCard, columnId: number) => {
    dragCardRef.current = {
      cardId: card.id,
      propertyId: card.property_id,
      contactId: card.contact_id,
      sourceColumnId: columnId,
    };
    setDraggingCardId(card.id);
  };

  /** Drag over column */
  const handleDragOverColumn = (event: React.DragEvent, columnId: number) => {
    event.preventDefault();
    setDragOverColumnId(columnId);
  };

  /** Drop on column */
  const handleDropOnColumn = (targetColumnId: number) => {
    const dragData = dragCardRef.current;
    if (!dragData) return;

    moveCard(
      dragData.cardId,
      dragData.propertyId,
      dragData.contactId,
      dragData.sourceColumnId,
      targetColumnId,
    );
    dragCardRef.current = null;
    setDragOverColumnId(null);
    setDraggingCardId(null);
  };

  /** Drag end */
  const handleDragEnd = () => {
    dragCardRef.current = null;
    setDragOverColumnId(null);
    setDraggingCardId(null);
  };

  /** Проверка просроченности даты контакта */
  const isOverdue = (dateStr: string | null): boolean => {
    if (!dateStr) return false;
    return new Date(dateStr) < new Date();
  };

  return (
    <div className="pipeline-board">
      {columns.map(column => (
        <div
          key={column.id}
          className={`pipeline-column ${dragOverColumnId === column.id ? 'drag-over' : ''}`}
          onDragOver={(event) => handleDragOverColumn(event, column.id)}
          onDragLeave={() => setDragOverColumnId(null)}
          onDrop={() => handleDropOnColumn(column.id)}
        >
          <div className="column-header" style={{ borderTopColor: column.color }}>
            <div className="column-title">
              <span className="column-dot" style={{ backgroundColor: column.color }} />
              <span>{column.name}</span>
            </div>
            <span className="column-count">{column.cards.length}</span>
          </div>

          <div className="column-cards">
            {column.cards.map(card => (
              <div
                key={card.id}
                className={`pipeline-card ${draggingCardId === card.id ? 'dragging' : ''}`}
                draggable
                onDragStart={() => handleDragStart(card, column.id)}
                onDragEnd={handleDragEnd}
                onClick={() => onCardClick?.(card.property_id)}
              >
                {/* Секция объекта */}
                <div className="pipeline-card-property">
                  <div className="card-name">{getPropertySummary(card.property)}</div>
                  {card.property?.address && (
                    <div className="card-address">{card.property.address}</div>
                  )}
                  <div className="card-meta">
                    {card.property?.price && (
                      <span className="card-price">{formatPrice(card.property.price)} &#8381;</span>
                    )}
                    {card.property?.deal_type && (
                      <span className={`pipeline-card-deal-badge deal-${card.property.deal_type}`}>
                        {DEAL_TYPE_LABELS[card.property.deal_type]}
                      </span>
                    )}
                  </div>
                </div>

                {/* Секция контакта */}
                {card.contact && (
                  <div className="pipeline-card-contact">
                    <div className="contact-row">
                      <span className="material-icons contact-icon">person</span>
                      <span className="contact-name">{card.contact.name}</span>
                    </div>
                    {card.contact.phone && (
                      <div className="contact-row">
                        <span className="material-icons contact-icon">phone</span>
                        <span className="contact-phone">{card.contact.phone}</span>
                      </div>
                    )}
                  </div>
                )}

                {/* Дата следующего контакта */}
                {card.next_contact_at && (
                  <div className={`pipeline-card-next-contact ${isOverdue(card.next_contact_at) ? 'overdue' : ''}`}>
                    <span className="material-icons">schedule</span>
                    {getRelativeTime(card.next_contact_at)}
                  </div>
                )}

                {/* Быстрые действия */}
                {(onAddInteraction || onAddReminder) && (
                  <div className="pipeline-card-actions">
                    {onAddInteraction && (
                      <Tooltip content="Добавить взаимодействие" position="top">
                        <button
                          className="pipeline-action-btn"
                          onClick={(event) => { event.stopPropagation(); onAddInteraction(card.property_id, card.contact_id); }}
                        >
                          <span className="material-icons">forum</span>
                        </button>
                      </Tooltip>
                    )}
                    {onAddReminder && (
                      <Tooltip content="Создать напоминание" position="top">
                        <button
                          className="pipeline-action-btn"
                          onClick={(event) => { event.stopPropagation(); onAddReminder(card.property_id, card.contact_id); }}
                        >
                          <span className="material-icons">notifications</span>
                        </button>
                      </Tooltip>
                    )}
                  </div>
                )}
              </div>
            ))}

            {column.cards.length === 0 && (
              <div className="column-empty">Нет карточек</div>
            )}
          </div>
        </div>
      ))}

      {/* Диалог для ошибок */}
      {dialog && (
        <ConfirmDialog
          title={dialog.title}
          message={dialog.message}
          variant={dialog.variant}
          onConfirm={dialog.onConfirm}
          onCancel={() => setDialog(null)}
        />
      )}
    </div>
  );
}

export default Pipeline;
