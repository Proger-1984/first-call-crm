import { useState, useEffect, useCallback, useRef } from 'react';
import { clientsApi } from '../../services/api';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import type { PipelineColumn, ClientShort } from '../../types/client';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import './Pipeline.css';

interface PipelineProps {
  stages: any[];
  onClientClick: (clientId: number) => void;
  onRefresh: () => void;
  /** Ключ для принудительного обновления при создании/удалении клиента */
  refreshKey?: number;
}

export function Pipeline({ onClientClick, onRefresh, refreshKey }: PipelineProps) {
  const [columns, setColumns] = useState<PipelineColumn[]>([]);
  const [loading, setLoading] = useState(true);

  // Drag-n-drop
  const dragClientRef = useRef<{ clientId: number; sourceColumnId: number } | null>(null);
  const [dragOverColumnId, setDragOverColumnId] = useState<number | null>(null);
  const [draggingClientId, setDraggingClientId] = useState<number | null>(null);

  // Диалог для ошибок
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  /** Загрузка данных kanban */
  const loadPipeline = useCallback(async () => {
    setLoading(true);
    try {
      const response = await clientsApi.getPipeline();
      setColumns(response.data?.data?.pipeline || []);
    } catch {
      // Ошибка загрузки
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadPipeline();
  }, [loadPipeline, refreshKey]);

  /** Перемещение клиента на другую стадию (оптимистичное) */
  const moveClient = async (clientId: number, sourceColumnId: number, targetStageId: number) => {
    if (sourceColumnId === targetStageId) return;

    // Оптимистичное обновление: перемещаем клиента локально
    setColumns(prevColumns => {
      const newColumns = prevColumns.map(col => ({ ...col, clients: [...col.clients] }));
      const sourceCol = newColumns.find(c => c.id === sourceColumnId);
      const targetCol = newColumns.find(c => c.id === targetStageId);
      if (!sourceCol || !targetCol) return prevColumns;

      const clientIndex = sourceCol.clients.findIndex(c => c.id === clientId);
      if (clientIndex === -1) return prevColumns;

      const [movedClient] = sourceCol.clients.splice(clientIndex, 1);
      targetCol.clients.push(movedClient);
      return newColumns;
    });

    try {
      await clientsApi.moveStage(clientId, targetStageId);
      onRefresh();
    } catch {
      // Откат при ошибке
      loadPipeline();
      setDialog({ title: 'Ошибка', message: 'Не удалось переместить клиента', variant: 'danger', onConfirm: () => setDialog(null) });
    }
  };

  /** Drag start */
  const handleDragStart = (clientId: number, columnId: number) => {
    dragClientRef.current = { clientId, sourceColumnId: columnId };
    setDraggingClientId(clientId);
  };

  /** Drag over column */
  const handleDragOverColumn = (e: React.DragEvent, columnId: number) => {
    e.preventDefault();
    setDragOverColumnId(columnId);
  };

  /** Drop on column */
  const handleDropOnColumn = (targetColumnId: number) => {
    const dragData = dragClientRef.current;
    if (!dragData) return;

    moveClient(dragData.clientId, dragData.sourceColumnId, targetColumnId);
    dragClientRef.current = null;
    setDragOverColumnId(null);
    setDraggingClientId(null);
  };

  /** Drag end */
  const handleDragEnd = () => {
    dragClientRef.current = null;
    setDragOverColumnId(null);
    setDraggingClientId(null);
  };

  /** Форматирование бюджета */
  const formatBudget = (min: number | null, max: number | null): string => {
    if (!min && !max) return '';
    const formatNum = (n: number) => {
      if (n >= 1000000) return `${(n / 1000000).toFixed(1)}М`;
      if (n >= 1000) return `${Math.round(n / 1000)}т`;
      return String(n);
    };
    if (min && max) return `${formatNum(min)}–${formatNum(max)} ₽`;
    if (min) return `от ${formatNum(min)} ₽`;
    return `до ${formatNum(max!)} ₽`;
  };

  /** Форматирование даты контакта */
  const formatContactDate = (dateStr: string | null): string | null => {
    if (!dateStr) return null;
    const date = new Date(dateStr);
    const now = new Date();
    const diffDays = Math.floor((date.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
    if (diffDays < 0) return `${Math.abs(diffDays)}д. назад`;
    if (diffDays === 0) return 'Сегодня';
    if (diffDays === 1) return 'Завтра';
    return `через ${diffDays}д.`;
  };

  if (loading) {
    return (
      <div className="pipeline-loading">
        <div className="loading-spinner" />
        <p>Загрузка воронки...</p>
      </div>
    );
  }

  return (
    <div className="pipeline-board">
      {columns.map(column => (
        <div
          key={column.id}
          className={`pipeline-column ${dragOverColumnId === column.id ? 'drag-over' : ''}`}
          onDragOver={(e) => handleDragOverColumn(e, column.id)}
          onDragLeave={() => setDragOverColumnId(null)}
          onDrop={() => handleDropOnColumn(column.id)}
        >
          <div className="column-header" style={{ borderTopColor: column.color }}>
            <div className="column-title">
              <span className="column-dot" style={{ backgroundColor: column.color }} />
              <span>{column.name}</span>
            </div>
            <span className="column-count">{column.clients.length}</span>
          </div>
          <div className="column-cards">
            {column.clients.map(client => (
              <div
                key={client.id}
                className={`pipeline-card ${draggingClientId === client.id ? 'dragging' : ''}`}
                draggable
                onDragStart={() => handleDragStart(client.id, column.id)}
                onDragEnd={handleDragEnd}
                onClick={() => onClientClick(client.id)}
              >
                <div className="card-name">{client.name}</div>
                {client.phone && <div className="card-phone">{client.phone}</div>}
                <div className="card-meta">
                  <span className={`card-type type-${client.client_type}`}>
                    {CLIENT_TYPE_LABELS[client.client_type]}
                  </span>
                  {formatBudget(client.budget_min, client.budget_max) && (
                    <span className="card-budget">{formatBudget(client.budget_min, client.budget_max)}</span>
                  )}
                </div>
                {client.next_contact_at && (
                  <div className={`card-contact ${new Date(client.next_contact_at) < new Date() ? 'overdue' : ''}`}>
                    <span className="material-icons">schedule</span>
                    {formatContactDate(client.next_contact_at)}
                  </div>
                )}
              </div>
            ))}
            {column.clients.length === 0 && (
              <div className="column-empty">Нет клиентов</div>
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
