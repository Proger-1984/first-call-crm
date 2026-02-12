import { useState } from 'react';
import { clientsApi } from '../../services/api';
import type { Client, ClientType, PipelineStage } from '../../types/client';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import './ClientForm.css';

interface ClientFormProps {
  client: Client | null;
  stages: PipelineStage[];
  onClose: () => void;
  onSaved: () => void;
}

export function ClientForm({ client, stages, onClose, onSaved }: ClientFormProps) {
  const isEditing = Boolean(client);

  const [name, setName] = useState(client?.name || '');
  const [phone, setPhone] = useState(client?.phone || '');
  const [phoneSecondary, setPhoneSecondary] = useState(client?.phone_secondary || '');
  const [email, setEmail] = useState(client?.email || '');
  const [telegramUsername, setTelegramUsername] = useState(client?.telegram_username || '');
  const [clientType, setClientType] = useState<ClientType>(client?.client_type || 'buyer');
  const [stageId, setStageId] = useState<number | ''>(client?.pipeline_stage?.id || '');
  const [sourceType, setSourceType] = useState(client?.source_type || '');
  const [sourceDetails, setSourceDetails] = useState(client?.source_details || '');
  const [budgetMin, setBudgetMin] = useState(client?.budget_min?.toString() || '');
  const [budgetMax, setBudgetMax] = useState(client?.budget_max?.toString() || '');
  const [comment, setComment] = useState(client?.comment || '');
  const [nextContactAt, setNextContactAt] = useState(
    client?.next_contact_at ? new Date(client.next_contact_at).toISOString().slice(0, 16) : ''
  );

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
        phone: phone.trim() || null,
        phone_secondary: phoneSecondary.trim() || null,
        email: email.trim() || null,
        telegram_username: telegramUsername.trim() || null,
        client_type: clientType,
        source_type: sourceType.trim() || null,
        source_details: sourceDetails.trim() || null,
        budget_min: budgetMin ? parseFloat(budgetMin) : null,
        budget_max: budgetMax ? parseFloat(budgetMax) : null,
        comment: comment.trim() || null,
        next_contact_at: nextContactAt || null,
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
    <div className="modal-overlay" onClick={onClose}>
      <div className="client-form-modal" onClick={(e) => e.stopPropagation()}>
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
              <select value={clientType} onChange={(e) => setClientType(e.target.value as ClientType)}>
                {Object.entries(CLIENT_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
            <div className="form-field">
              <label>Стадия</label>
              <select value={stageId} onChange={(e) => setStageId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">Первая стадия</option>
                {stages.map(stage => (
                  <option key={stage.id} value={stage.id}>{stage.name}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="form-row">
            <div className="form-field">
              <label>Телефон</label>
              <input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+7..." />
            </div>
            <div className="form-field">
              <label>Доп. телефон</label>
              <input type="tel" value={phoneSecondary} onChange={(e) => setPhoneSecondary(e.target.value)} placeholder="+7..." />
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
              <input type="datetime-local" value={nextContactAt} onChange={(e) => setNextContactAt(e.target.value)} />
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
