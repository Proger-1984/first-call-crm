import { useState, useCallback } from 'react';
import { contactsApi } from '../../services/api';
import { formatPhoneNumber, cleanPhoneNumber } from '../../utils/phoneMask';
import type { Contact } from '../../types/property';
import './ContactForm.css';

interface ContactFormProps {
  /** Модалка открыта */
  isOpen: boolean;
  /** Закрыть модалку */
  onClose: () => void;
  /** Колбэк после успешного сохранения (для создания передаёт ID) */
  onSaved: (createdContactId?: number) => void;
  /** Данные для редактирования (если undefined — создание) */
  editData?: Contact;
}

export function ContactForm({ isOpen, onClose, onSaved, editData }: ContactFormProps) {
  const isEditing = Boolean(editData);

  const [name, setName] = useState(editData?.name || '');
  const [phone, setPhone] = useState(editData?.phone ? formatPhoneNumber(editData.phone) : '');
  const [phoneSecondary, setPhoneSecondary] = useState(editData?.phone_secondary ? formatPhoneNumber(editData.phone_secondary) : '');
  const [email, setEmail] = useState(editData?.email || '');
  const [telegramUsername, setTelegramUsername] = useState(editData?.telegram_username || '');
  const [comment, setComment] = useState(editData?.comment || '');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** Обработчик ввода телефона с маской */
  const handlePhoneChange = useCallback((value: string, setter: (val: string) => void) => {
    setter(formatPhoneNumber(value));
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) {
      setError('Имя контакта обязательно');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const data: Record<string, any> = {
        name: name.trim(),
        phone: phone ? cleanPhoneNumber(phone) : null,
        phone_secondary: phoneSecondary ? cleanPhoneNumber(phoneSecondary) : null,
        email: email.trim() || null,
        telegram_username: telegramUsername.trim() || null,
        comment: comment.trim() || null,
      };

      let createdContactId: number | undefined;
      if (isEditing && editData) {
        await contactsApi.update(editData.id, data);
      } else {
        const response = await contactsApi.create(data);
        createdContactId = response.data?.data?.contact?.id;
      }

      onSaved(createdContactId);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Ошибка сохранения');
    } finally {
      setSaving(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="modal-overlay">
      <div className="contact-form-modal">
        <div className="modal-header">
          <h2>{isEditing ? 'Редактирование контакта' : 'Новый контакт'}</h2>
          <button className="close-btn" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <form onSubmit={handleSubmit} className="contact-form">
          {error && <div className="form-error">{error}</div>}

          <div className="form-row">
            <div className="form-field full">
              <label>Имя *</label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="ФИО контакта"
                autoFocus
              />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field">
              <label>Телефон</label>
              <input
                type="tel"
                value={phone}
                onChange={(e) => handlePhoneChange(e.target.value, setPhone)}
                placeholder="+7 (___) ___-__-__"
              />
            </div>
            <div className="form-field">
              <label>Доп. телефон</label>
              <input
                type="tel"
                value={phoneSecondary}
                onChange={(e) => handlePhoneChange(e.target.value, setPhoneSecondary)}
                placeholder="+7 (___) ___-__-__"
              />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field">
              <label>Email</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="email@example.com" />
            </div>
            <div className="form-field">
              <label>Telegram</label>
              <input type="text" value={telegramUsername} onChange={(e) => setTelegramUsername(e.target.value)} placeholder="username" />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field full">
              <label>Комментарий</label>
              <textarea
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Заметки о контакте..."
                rows={3}
              />
            </div>
          </div>

          <div className="contact-form-footer">
            <button type="button" className="contact-form-btn secondary" onClick={onClose}>Отмена</button>
            <button type="submit" className="contact-form-btn primary" disabled={saving}>
              {saving ? 'Сохранение...' : (isEditing ? 'Сохранить' : 'Создать')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default ContactForm;
