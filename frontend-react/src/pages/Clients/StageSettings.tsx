import { useState, useEffect, useCallback } from 'react';
import { clientsApi } from '../../services/api';
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
      alert(err.response?.data?.message || 'Ошибка создания');
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
      alert(err.response?.data?.message || 'Ошибка обновления');
    }
  };

  /** Удалить стадию */
  const handleDelete = async (stageId: number) => {
    if (!confirm('Удалить стадию?')) return;
    try {
      await clientsApi.deleteStage(stageId);
      loadStages();
    } catch (err: any) {
      alert(err.response?.data?.message || 'Ошибка удаления');
    }
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

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="stage-settings-modal" onClick={(e) => e.stopPropagation()}>
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
                  <div key={stage.id} className="stage-row">
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
                          <span className="stage-color-dot" style={{ backgroundColor: stage.color }} />
                          <span className="stage-name">{stage.name}</span>
                          {stage.is_system && <span className="system-badge">Системная</span>}
                          {stage.is_final && <span className="final-badge">Финал</span>}
                          <span className="clients-count">{stage.clients_count}</span>
                        </div>
                        <div className="stage-actions">
                          <button
                            className="icon-btn"
                            onClick={() => moveStage(index, 'up')}
                            disabled={index === 0}
                            title="Вверх"
                          >
                            <span className="material-icons">arrow_upward</span>
                          </button>
                          <button
                            className="icon-btn"
                            onClick={() => moveStage(index, 'down')}
                            disabled={index === stages.length - 1}
                            title="Вниз"
                          >
                            <span className="material-icons">arrow_downward</span>
                          </button>
                          <button className="icon-btn" onClick={() => startEdit(stage)} title="Редактировать">
                            <span className="material-icons">edit</span>
                          </button>
                          {!stage.is_system && (
                            <button
                              className="icon-btn danger"
                              onClick={() => handleDelete(stage.id)}
                              title="Удалить"
                              disabled={stage.clients_count > 0}
                            >
                              <span className="material-icons">delete_outline</span>
                            </button>
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
                <button className="btn-primary" onClick={handleCreate} disabled={creating || !newName.trim()}>
                  Добавить
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

export default StageSettings;
