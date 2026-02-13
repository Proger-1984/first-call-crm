import { useState } from 'react';
import { interactionsApi } from '../../services/api';
import { DatePicker } from '../../components/UI/DatePicker';
import type { InteractionType } from '../../types/property';
import { INTERACTION_TYPE_LABELS, INTERACTION_TYPE_ICONS } from '../../types/property';
import './InteractionTimeline.css';

interface InteractionFormProps {
  isOpen: boolean;
  propertyId: number;
  contactId: number;
  onClose: () => void;
  onSaved: () => void;
}

/** Типы, доступные для ручного создания */
const MANUAL_TYPES: InteractionType[] = ['call', 'meeting', 'showing', 'message', 'note'];

/** Конвертирует дату в формат dd.mm.yyyy HH:mm для DatePicker */
function dateToDisplay(date: Date): string {
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${day}.${month}.${year} ${hours}:${minutes}`;
}

/** Конвертирует dd.mm.yyyy HH:mm в ISO формат для API */
function displayToIso(dateStr: string): string | null {
  if (!dateStr) return null;
  const match = dateStr.match(/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})$/);
  if (!match) return null;
  const [, day, month, year, hours, minutes] = match;
  return `${year}-${month}-${day}T${hours}:${minutes}:00`;
}

export function InteractionForm({ isOpen, propertyId, contactId, onClose, onSaved }: InteractionFormProps) {
  const [type, setType] = useState<InteractionType>('call');
  const [description, setDescription] = useState('');
  const [interactionDate, setInteractionDate] = useState(() => dateToDisplay(new Date()));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSaving(true);

    try {
      const isoDate = displayToIso(interactionDate);
      await interactionsApi.create(propertyId, contactId, {
        type,
        description: description.trim() || undefined,
        interaction_at: isoDate || undefined,
      });
      onSaved();
    } catch (err: any) {
      const message = err?.response?.data?.message || 'Ошибка создания взаимодействия';
      setError(message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2>Новое взаимодействие</h2>
          <button className="modal-close" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="modal-body">
            {error && <div className="form-error">{error}</div>}

            {/* Тип взаимодействия */}
            <div className="form-group">
              <label>Тип</label>
              <div className="interaction-type-grid">
                {MANUAL_TYPES.map(typeOption => (
                  <button
                    key={typeOption}
                    type="button"
                    className={`interaction-type-btn ${type === typeOption ? 'active' : ''}`}
                    data-type={typeOption}
                    onClick={() => setType(typeOption)}
                  >
                    <span className="material-icons">
                      {INTERACTION_TYPE_ICONS[typeOption]}
                    </span>
                    <span>{INTERACTION_TYPE_LABELS[typeOption]}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Дата/время */}
            <div className="form-group">
              <label>Дата и время</label>
              <DatePicker
                enableTime
                placeholder="дд.мм.гггг чч:мм"
                value={interactionDate}
                onChange={setInteractionDate}
              />
            </div>

            {/* Описание */}
            <div className="form-group">
              <label>Описание</label>
              <textarea
                className="form-control"
                rows={4}
                placeholder="Описание взаимодействия..."
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>
          </div>

          <div className="modal-footer">
            <button type="button" className="btn btn-secondary" onClick={onClose}>
              Отмена
            </button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving ? 'Сохранение...' : 'Создать'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default InteractionForm;
