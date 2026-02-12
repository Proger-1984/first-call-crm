import { useState, useEffect, useCallback } from 'react';
import { clientsApi } from '../../services/api';
import type { PipelineColumn, ClientShort, ClientType } from '../../types/client';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import './Pipeline.css';

interface PipelineProps {
  stages: any[];
  onClientClick: (clientId: number) => void;
  onRefresh: () => void;
}

export function Pipeline({ onClientClick, onRefresh }: PipelineProps) {
  const [columns, setColumns] = useState<PipelineColumn[]>([]);
  const [loading, setLoading] = useState(true);
  const [movingClientId, setMovingClientId] = useState<number | null>(null);

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
  }, [loadPipeline]);

  /** Перемещение клиента на другую стадию */
  const moveClient = async (clientId: number, targetStageId: number) => {
    setMovingClientId(clientId);
    try {
      await clientsApi.moveStage(clientId, targetStageId);
      loadPipeline();
      onRefresh();
    } catch {
      alert('Ошибка перемещения клиента');
    } finally {
      setMovingClientId(null);
    }
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
        <div key={column.id} className="pipeline-column">
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
                className={`pipeline-card ${movingClientId === client.id ? 'moving' : ''}`}
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
                {/* Кнопки перемещения */}
                <div className="card-move-actions" onClick={(e) => e.stopPropagation()}>
                  {columns.filter(c => c.id !== column.id).slice(0, 3).map(targetCol => (
                    <button
                      key={targetCol.id}
                      className="move-btn"
                      title={`Переместить в "${targetCol.name}"`}
                      onClick={() => moveClient(client.id, targetCol.id)}
                      style={{ borderColor: targetCol.color, color: targetCol.color }}
                    >
                      {targetCol.name.substring(0, 3)}
                    </button>
                  ))}
                </div>
              </div>
            ))}
            {column.clients.length === 0 && (
              <div className="column-empty">Нет клиентов</div>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

export default Pipeline;
