import { useState } from 'react';
import { remindersApi } from '../../services/api';
import { DatePicker } from '../../components/UI/DatePicker';

interface ReminderFormProps {
  isOpen: boolean;
  propertyId: number;
  contactId: number;
  onClose: () => void;
  onSaved: () => void;
}

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

export function ReminderForm({ isOpen, propertyId, contactId, onClose, onSaved }: ReminderFormProps) {
  const [message, setMessage] = useState('');
  const [remindAt, setRemindAt] = useState(() => {
    // По умолчанию: завтра в 10:00
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(10, 0, 0, 0);
    return dateToDisplay(tomorrow);
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);

    if (!message.trim()) {
      setError('Введите текст напоминания');
      return;
    }

    const isoDate = displayToIso(remindAt);
    if (!isoDate) {
      setError('Выберите дату и время');
      return;
    }

    const remindDate = new Date(isoDate);
    if (remindDate <= new Date()) {
      setError('Дата напоминания должна быть в будущем');
      return;
    }

    setSaving(true);

    try {
      // Отправляем локальное время без конвертации в UTC,
      // бэкенд работает в Europe/Moscow и парсит как московское
      await remindersApi.create(propertyId, contactId, {
        remind_at: isoDate,
        message: message.trim(),
      });
      onSaved();
    } catch (err: any) {
      const errorMessage = err?.response?.data?.message || 'Ошибка создания напоминания';
      setError(errorMessage);
    } finally {
      setSaving(false);
    }
  };

  /** Быстрые кнопки: через 1 час, завтра, через 3 дня */
  const setQuickDate = (hours: number) => {
    const date = new Date();
    date.setTime(date.getTime() + hours * 60 * 60 * 1000);
    setRemindAt(dateToDisplay(date));
  };

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2>Новое напоминание</h2>
          <button className="modal-close" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="modal-body">
            {error && <div className="form-error">{error}</div>}

            {/* Дата и время */}
            <div className="form-group">
              <label>Когда напомнить</label>
              <DatePicker
                enableTime
                placeholder="дд.мм.гггг чч:мм"
                value={remindAt}
                onChange={setRemindAt}
              />
              <div className="quick-date-buttons">
                <button type="button" className="btn btn-outline btn-xs" onClick={() => setQuickDate(1)}>
                  Через 1 час
                </button>
                <button type="button" className="btn btn-outline btn-xs" onClick={() => setQuickDate(24)}>
                  Завтра
                </button>
                <button type="button" className="btn btn-outline btn-xs" onClick={() => setQuickDate(72)}>
                  Через 3 дня
                </button>
              </div>
            </div>

            {/* Текст */}
            <div className="form-group">
              <label>Текст напоминания</label>
              <textarea
                className="form-control"
                rows={3}
                placeholder="Перезвонить по поводу показа..."
                value={message}
                onChange={(e) => setMessage(e.target.value)}
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

export default ReminderForm;
