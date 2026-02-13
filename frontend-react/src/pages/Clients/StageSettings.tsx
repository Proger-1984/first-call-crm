import { useState, useEffect, useCallback, useRef } from 'react';
import { clientsApi } from '../../services/api';
import { Tooltip } from '../../components/UI/Tooltip';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import type { PipelineStage } from '../../types/client';
import './StageSettings.css';

interface StageSettingsProps {
  onClose: () => void;
  onSaved: () => void;
}

export function StageSettings({ onClose, onSaved }: StageSettingsProps) {
  const [stages, setStages] = useState<PipelineStage[]>([]);
  const [loading, setLoading] = useState(true);

  // Форма создания
  const [newName, setNewName] = useState('');
  const [newColor, setNewColor] = useState('#808080');
  const [creating, setCreating] = useState(false);

  // Редактирование
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editName, setEditName] = useState('');
  const [editColor, setEditColor] = useState('');

  // Drag-n-drop
  const dragIndexRef = useRef<number | null>(null);
  const [dragOverIndex, setDragOverIndex] = useState<number | null>(null);

  // Диалог подтверждения
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  const loadStages = useCallback(async () => {
    setLoading(true);
    try {
      const response = await clientsApi.getStages();
      setStages(response.data?.data?.stages || []);
    } catch {
      // Ошибка
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadStages();
  }, [loadStages]);

  /** Показать ошибку через диалог */
  const showError = (message: string) => {
    setDialog({ title: 'Ошибка', message, variant: 'danger', onConfirm: () => setDialog(null) });
  };

  /** Создать стадию */
  const handleCreate = async () => {
    if (!newName.trim()) return;
    setCreating(true);
    try {
      await clientsApi.createStage(newName.trim(), newColor);
      setNewName('');
      setNewColor('#808080');
      loadStages();
    } catch (err: any) {
      showError(err.response?.data?.message || 'Ошибка создания');
    } finally {
      setCreating(false);
    }
  };

  /** Начать редактирование */
  const startEdit = (stage: PipelineStage) => {
    setEditingId(stage.id);
    setEditName(stage.name);
    setEditColor(stage.color);
  };

  /** Сохранить редактирование */
  const saveEdit = async () => {
    if (!editingId || !editName.trim()) return;
    try {
      await clientsApi.updateStage(editingId, { name: editName.trim(), color: editColor });
      setEditingId(null);
      loadStages();
    } catch (err: any) {
      showError(err.response?.data?.message || 'Ошибка обновления');
    }
  };

  /** Удалить стадию */
  const handleDelete = (stage: PipelineStage) => {
    if (stage.clients_count > 0) {
      setDialog({
        title: 'Невозможно удалить',
        message: `На стадии "${stage.name}" находятся ${stage.clients_count} клиентов. Переместите их на другую стадию.`,
        variant: 'warning',
        onConfirm: () => setDialog(null),
      });
      return;
    }
    setDialog({
      title: 'Удаление стадии',
      message: `Удалить стадию "${stage.name}"?`,
      confirmText: 'Удалить',
      cancelText: 'Отмена',
      variant: 'danger',
      onConfirm: async () => {
        setDialog(null);
        try {
          await clientsApi.deleteStage(stage.id);
          loadStages();
        } catch (err: any) {
          showError(err.response?.data?.message || 'Ошибка удаления');
        }
      },
    });
  };

  /** Переместить вверх/вниз */
  const moveStage = async (index: number, direction: 'up' | 'down') => {
    const newStages = [...stages];
    const swapIndex = direction === 'up' ? index - 1 : index + 1;
    if (swapIndex < 0 || swapIndex >= newStages.length) return;

    [newStages[index], newStages[swapIndex]] = [newStages[swapIndex], newStages[index]];
    setStages(newStages);

    try {
      await clientsApi.reorderStages(newStages.map(s => s.id));
    } catch {
      loadStages(); // Откат
    }
  };

  /** Drag-n-drop: начало перетаскивания */
  const handleDragStart = (index: number) => {
    dragIndexRef.current = index;
  };

  /** Drag-n-drop: наведение на элемент */
  const handleDragOver = (e: React.DragEvent, index: number) => {
    e.preventDefault();
    if (dragIndexRef.current === null || dragIndexRef.current === index) return;
    setDragOverIndex(index);
  };

  /** Drag-n-drop: бросок */
  const handleDrop = async (targetIndex: number) => {
    const sourceIndex = dragIndexRef.current;
    if (sourceIndex === null || sourceIndex === targetIndex) return;

    const newStages = [...stages];
    const [moved] = newStages.splice(sourceIndex, 1);
    newStages.splice(targetIndex, 0, moved);
    setStages(newStages);

    dragIndexRef.current = null;
    setDragOverIndex(null);

    try {
      await clientsApi.reorderStages(newStages.map(s => s.id));
    } catch {
      loadStages(); // Откат
    }
  };

  /** Drag-n-drop: конец перетаскивания */
  const handleDragEnd = () => {
    dragIndexRef.current = null;
    setDragOverIndex(null);
  };

  return (
    <div className="modal-overlay">
      <div className="stage-settings-modal">
        <div className="modal-header">
          <h2>Настройка стадий воронки</h2>
          <button className="close-btn" onClick={() => { onSaved(); onClose(); }}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <div className="stage-settings-content">
          {loading ? (
            <div className="loading-state">
              <div className="loading-spinner" />
            </div>
          ) : (
            <>
              {/* Список стадий */}
              <div className="stages-list">
                {stages.map((stage, index) => (
                  <div
                    key={stage.id}
                    className={`stage-row ${dragIndexRef.current === index ? 'stage-dragging' : ''} ${dragOverIndex === index ? 'stage-drag-over' : ''}`}
                    draggable
                    onDragStart={() => handleDragStart(index)}
                    onDragOver={(e) => handleDragOver(e, index)}
                    onDrop={() => handleDrop(index)}
                    onDragEnd={handleDragEnd}
                  >
                    {editingId === stage.id ? (
                      <div className="stage-edit-form">
                        <input
                          type="color"
                          value={editColor}
                          onChange={(e) => setEditColor(e.target.value)}
                          className="color-picker"
                        />
                        <input
                          type="text"
                          value={editName}
                          onChange={(e) => setEditName(e.target.value)}
                          className="name-input"
                          onKeyDown={(e) => e.key === 'Enter' && saveEdit()}
                        />
                        <button className="save-btn" onClick={saveEdit}>
                          <span className="material-icons">check</span>
                        </button>
                        <button className="cancel-edit-btn" onClick={() => setEditingId(null)}>
                          <span className="material-icons">close</span>
                        </button>
                      </div>
                    ) : (
                      <>
                        <div className="stage-info">
                          <span className="drag-handle material-icons">drag_indicator</span>
                          <span className="stage-color-dot" style={{ backgroundColor: stage.color }} />
                          <span className="stage-name">{stage.name}</span>
                          {stage.is_system && <span className="system-badge">Системная</span>}
                          {stage.is_final && <span className="final-badge">Финал</span>}
                          {stage.clients_count > 0 && (
                            <Tooltip content={`Клиентов на стадии: ${stage.clients_count}`} position="top">
                              <span className="clients-count-badge">{stage.clients_count} кл.</span>
                            </Tooltip>
                          )}
                        </div>
                        <div className="stage-actions">
                          <Tooltip content="Вверх" position="top">
                            <button
                              className="icon-btn"
                              onClick={() => moveStage(index, 'up')}
                              disabled={index === 0}
                            >
                              <span className="material-icons">arrow_upward</span>
                            </button>
                          </Tooltip>
                          <Tooltip content="Вниз" position="top">
                            <button
                              className="icon-btn"
                              onClick={() => moveStage(index, 'down')}
                              disabled={index === stages.length - 1}
                            >
                              <span className="material-icons">arrow_downward</span>
                            </button>
                          </Tooltip>
                          <Tooltip content="Редактировать" position="top">
                            <button className="icon-btn" onClick={() => startEdit(stage)}>
                              <span className="material-icons">edit</span>
                            </button>
                          </Tooltip>
                          {!stage.is_system && (
                            <Tooltip content={stage.clients_count > 0 ? `Невозможно: ${stage.clients_count} кл.` : 'Удалить'} position="top">
                              <button
                                className="icon-btn danger"
                                onClick={() => handleDelete(stage)}
                                disabled={stage.clients_count > 0}
                              >
                                <span className="material-icons">delete_outline</span>
                              </button>
                            </Tooltip>
                          )}
                        </div>
                      </>
                    )}
                  </div>
                ))}
              </div>

              {/* Форма создания */}
              <div className="create-stage-form">
                <input
                  type="color"
                  value={newColor}
                  onChange={(e) => setNewColor(e.target.value)}
                  className="color-picker"
                />
                <input
                  type="text"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  placeholder="Новая стадия..."
                  className="name-input"
                  onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
                />
                <button className="btn btn-primary" onClick={handleCreate} disabled={creating || !newName.trim()}>
                  Добавить
                </button>
              </div>
            </>
          )}
        </div>
      </div>

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

export default StageSettings;
