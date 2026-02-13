import { useState, useCallback } from 'react';
import { clientsApi } from '../../services/api';
import { DatePicker } from '../../components/UI/DatePicker';
import { formatPhoneNumber, cleanPhoneNumber } from '../../utils/phoneMask';
import type { Client, ClientType, PipelineStage } from '../../types/client';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import './ClientForm.css';

interface ClientFormProps {
  client: Client | null;
  stages: PipelineStage[];
  onClose: () => void;
  onSaved: () => void;
}

/**
 * Конвертирует ISO дату в формат dd.mm.yyyy HH:mm для DatePicker
 */
function isoToDisplayDate(isoStr: string | null | undefined): string {
  if (!isoStr) return '';
  const date = new Date(isoStr);
  if (isNaN(date.getTime())) return '';
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${day}.${month}.${year} ${hours}:${minutes}`;
}

/**
 * Конвертирует dd.mm.yyyy HH:mm в ISO формат для API
 */
function displayDateToIso(dateStr: string): string | null {
  if (!dateStr) return null;
  // Формат: dd.mm.yyyy HH:mm
  const match = dateStr.match(/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})$/);
  if (!match) return null;
  const [, day, month, year, hours, minutes] = match;
  return `${year}-${month}-${day}T${hours}:${minutes}:00`;
}

export function ClientForm({ client, stages, onClose, onSaved }: ClientFormProps) {
  const isEditing = Boolean(client);

  const [name, setName] = useState(client?.name || '');
  const [phone, setPhone] = useState(client?.phone ? formatPhoneNumber(client.phone) : '');
  const [phoneSecondary, setPhoneSecondary] = useState(client?.phone_secondary ? formatPhoneNumber(client.phone_secondary) : '');
  const [email, setEmail] = useState(client?.email || '');
  const [telegramUsername, setTelegramUsername] = useState(client?.telegram_username || '');
  const [clientType, setClientType] = useState<ClientType>(client?.client_type || 'buyer');
  // При создании нового клиента выбираем первую стадию по умолчанию
  const sortedStages = [...stages].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
  const defaultStageId = client?.pipeline_stage?.id || (sortedStages.length > 0 ? sortedStages[0].id : '');
  const [stageId, setStageId] = useState<number | ''>(defaultStageId);
  const [sourceType, setSourceType] = useState(client?.source_type || '');
  const [sourceDetails, setSourceDetails] = useState(client?.source_details || '');
  const [budgetMin, setBudgetMin] = useState(client?.budget_min?.toString() || '');
  const [budgetMax, setBudgetMax] = useState(client?.budget_max?.toString() || '');
  const [comment, setComment] = useState(client?.comment || '');
  const [nextContactAt, setNextContactAt] = useState(isoToDisplayDate(client?.next_contact_at));

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** Обработчик ввода телефона с маской */
  const handlePhoneChange = useCallback((value: string, setter: (val: string) => void) => {
    setter(formatPhoneNumber(value));
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) {
      setError('Имя клиента обязательно');
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
        client_type: clientType,
        source_type: sourceType.trim() || null,
        source_details: sourceDetails.trim() || null,
        budget_min: budgetMin ? parseFloat(budgetMin) : null,
        budget_max: budgetMax ? parseFloat(budgetMax) : null,
        comment: comment.trim() || null,
        next_contact_at: displayDateToIso(nextContactAt) || null,
      };

      if (stageId) {
        data.pipeline_stage_id = stageId;
      }

      if (isEditing && client) {
        await clientsApi.update(client.id, data);
      } else {
        await clientsApi.create(data);
      }

      onSaved();
    } catch (err: any) {
      setError(err.response?.data?.message || 'Ошибка сохранения');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="modal-overlay">
      <div className="client-form-modal">
        <div className="modal-header">
          <h2>{isEditing ? 'Редактирование клиента' : 'Новый клиент'}</h2>
          <button className="close-btn" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <form onSubmit={handleSubmit} className="client-form">
          {error && <div className="form-error">{error}</div>}

          <div className="form-row">
            <div className="form-field full">
              <label>Имя *</label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="ФИО клиента"
                autoFocus
              />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field">
              <label>Тип</label>
              <select
                className="filter-select"
                value={clientType}
                onChange={(e) => setClientType(e.target.value as ClientType)}
              >
                {Object.entries(CLIENT_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
            <div className="form-field">
              <label>Стадия</label>
              <select
                className="filter-select"
                value={stageId}
                onChange={(e) => setStageId(e.target.value ? Number(e.target.value) : '')}
              >
                <option value="">— Без стадии —</option>
                {sortedStages.map(stage => (
                  <option key={stage.id} value={stage.id}>{stage.name}</option>
                ))}
              </select>
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
            <div className="form-field">
              <label>Источник</label>
              <input type="text" value={sourceType} onChange={(e) => setSourceType(e.target.value)} placeholder="avito, cian, звонок..." />
            </div>
            <div className="form-field">
              <label>Детали источника</label>
              <input type="text" value={sourceDetails} onChange={(e) => setSourceDetails(e.target.value)} placeholder="Ссылка или описание" />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field">
              <label>Бюджет от</label>
              <input type="number" value={budgetMin} onChange={(e) => setBudgetMin(e.target.value)} placeholder="0" />
            </div>
            <div className="form-field">
              <label>Бюджет до</label>
              <input type="number" value={budgetMax} onChange={(e) => setBudgetMax(e.target.value)} placeholder="0" />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field">
              <label>Следующий контакт</label>
              <DatePicker
                enableTime
                placeholder="дд.мм.гггг чч:мм"
                value={nextContactAt}
                onChange={setNextContactAt}
              />
            </div>
          </div>

          <div className="form-row">
            <div className="form-field full">
              <label>Комментарий</label>
              <textarea
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Заметки о клиенте..."
                rows={3}
              />
            </div>
          </div>

          <div className="form-actions">
            <button type="button" className="btn-cancel" onClick={onClose}>Отмена</button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Сохранение...' : (isEditing ? 'Сохранить' : 'Создать')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default ClientForm;
