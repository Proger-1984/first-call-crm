import { useState, useEffect, useCallback } from 'react';
import { remindersApi } from '../../services/api';
import type { Reminder } from '../../types/property';
import './ReminderList.css';

interface ReminderListProps {
  /** ID объекта — если указан вместе с contactId, показывает напоминания по связке */
  propertyId?: number;
  /** ID контакта — если указан вместе с propertyId, показывает напоминания по связке */
  contactId?: number;
  /** Колбэк для создания нового напоминания */
  onAdd?: () => void;
  /** Ключ для принудительного обновления */
  refreshKey?: number;
}

export function ReminderList({ propertyId, contactId, onAdd, refreshKey = 0 }: ReminderListProps) {
  const [reminders, setReminders] = useState<Reminder[]>([]);
  const [loading, setLoading] = useState(true);

  const loadReminders = useCallback(async () => {
    setLoading(true);
    try {
      let response;
      if (propertyId && contactId) {
        response = await remindersApi.getByObjectClient(propertyId, contactId);
      } else {
        response = await remindersApi.getAll();
      }
      setReminders(response.data?.data?.reminders || []);
    } catch {
      // Не критично
    } finally {
      setLoading(false);
    }
  }, [propertyId, contactId]);

  useEffect(() => {
    loadReminders();
  }, [loadReminders, refreshKey]);

  const handleDelete = async (reminderId: number) => {
    try {
      await remindersApi.delete(reminderId);
      setReminders(prev => prev.filter(r => r.id !== reminderId));
    } catch {
      // Не критично
    }
  };

  /** Форматирование даты с относительным временем */
  const formatRemindAt = (dateStr: string): string => {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = date.getTime() - now.getTime();
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    let relative = '';
    if (diffMs < 0) {
      relative = ' (просрочено)';
    } else if (diffHours < 1) {
      const diffMin = Math.floor(diffMs / (1000 * 60));
      relative = ` (через ${diffMin} мин)`;
    } else if (diffHours < 24) {
      relative = ` (через ${diffHours} ч)`;
    } else if (diffDays < 7) {
      relative = ` (через ${diffDays} дн)`;
    }

    return date.toLocaleDateString('ru-RU', {
      day: '2-digit', month: '2-digit',
      hour: '2-digit', minute: '2-digit',
    }) + relative;
  };

  if (loading) {
    return (
      <div className="reminder-list-section">
        <div className="section-header">
          <h3>
            <span className="material-icons">notifications</span>
            Напоминания
          </h3>
        </div>
        <p className="reminder-loading">Загрузка...</p>
      </div>
    );
  }

  return (
    <div className="reminder-list-section">
      <div className="section-header">
        <h3>
          <span className="material-icons">notifications</span>
          Напоминания ({reminders.length})
        </h3>
        {onAdd && (
          <button className="btn btn-primary btn-sm" onClick={onAdd}>
            <span className="material-icons">add_alert</span>
            Напомнить
          </button>
        )}
      </div>

      {reminders.length === 0 ? (
        <p className="empty-text">Нет активных напоминаний</p>
      ) : (
        <div className="reminder-items">
          {reminders.map(reminder => {
            const isOverdue = new Date(reminder.remind_at) < new Date();
            return (
              <div key={reminder.id} className={`reminder-item ${isOverdue ? 'overdue' : ''}`}>
                <div className="reminder-icon">
                  <span className="material-icons">
                    {isOverdue ? 'notification_important' : 'notifications_active'}
                  </span>
                </div>
                <div className="reminder-content">
                  <div className="reminder-message">{reminder.message}</div>
                  <div className="reminder-meta">
                    <span className="reminder-time">{formatRemindAt(reminder.remind_at)}</span>
                    {reminder.contact && (
                      <span className="reminder-contact">
                        {reminder.contact.name}
                      </span>
                    )}
                  </div>
                </div>
                <button
                  className="reminder-delete"
                  onClick={() => handleDelete(reminder.id)}
                  title="Удалить напоминание"
                >
                  <span className="material-icons">close</span>
                </button>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

export default ReminderList;
