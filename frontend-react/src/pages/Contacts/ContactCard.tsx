import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { contactsApi } from '../../services/api';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { ContactForm } from './ContactForm';
import type { Contact, ObjectClientItem } from '../../types/property';
import { DEAL_TYPE_LABELS } from '../../types/property';
import './ContactCard.css';

export function ContactCard() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const contactId = Number(id);

  const [contact, setContact] = useState<Contact | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showEditForm, setShowEditForm] = useState(false);

  // Диалог подтверждения
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  /** Загрузка контакта */
  const loadContact = useCallback(async () => {
    if (!contactId) return;
    setLoading(true);
    setError(null);
    try {
      const response = await contactsApi.getById(contactId);
      setContact(response.data?.data?.contact || null);
    } catch {
      setError('Ошибка загрузки контакта');
    } finally {
      setLoading(false);
    }
  }, [contactId]);

  useEffect(() => {
    loadContact();
  }, [loadContact]);

  /** Показать ошибку через диалог */
  const showErrorDialog = (message: string) => {
    setDialog({ title: 'Ошибка', message, variant: 'danger', onConfirm: () => setDialog(null) });
  };

  /** Удалить контакт */
  const handleDelete = () => {
    setDialog({
      title: 'Удаление контакта',
      message: 'Удалить контакт? Это действие нельзя отменить.',
      confirmText: 'Удалить',
      cancelText: 'Отмена',
      variant: 'danger',
      onConfirm: async () => {
        setDialog(null);
        try {
          await contactsApi.delete(contactId);
          navigate('/contacts');
        } catch {
          showErrorDialog('Ошибка удаления');
        }
      },
    });
  };

  /** Форматирование даты */
  const formatDate = (dateStr: string | null): string => {
    if (!dateStr) return '\u2014';
    return new Date(dateStr).toLocaleDateString('ru-RU', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  };

  /** Форматирование цены */
  const formatPrice = (value: number | null): string => {
    if (!value) return '\u2014';
    return new Intl.NumberFormat('ru-RU').format(value) + ' \u20BD';
  };

  if (loading) {
    return (
      <div className="contact-card-page">
        <div className="loading-state">
          <div className="loading-spinner" />
          <p>Загрузка...</p>
        </div>
      </div>
    );
  }

  if (error || !contact) {
    return (
      <div className="contact-card-page">
        <div className="error-state">
          <span className="material-icons">error_outline</span>
          <p>{error || 'Контакт не найден'}</p>
          <button onClick={() => navigate('/contacts')}>К списку</button>
        </div>
      </div>
    );
  }

  return (
    <div className="contact-card-page">
      {/* Шапка */}
      <div className="card-top-bar">
        <button className="back-btn" onClick={() => navigate('/contacts')}>
          <span className="material-icons">arrow_back</span>
          Контакты
        </button>
        <div className="card-top-actions">
          <button className="action-btn" title="Редактировать" onClick={() => setShowEditForm(true)}>
            <span className="material-icons">edit</span>
          </button>
          <button className="action-btn danger" title="Удалить" onClick={handleDelete}>
            <span className="material-icons">delete_outline</span>
          </button>
        </div>
      </div>

      <div className="contact-card-content">
        {/* Основная секция */}
        <div className="contact-card-main">
          <div className="contact-card-header-section">
            <h1 className="contact-card-name">{contact.name}</h1>
            {contact.is_archived && <span className="archived-badge">Архив</span>}
          </div>

          {/* Комментарий */}
          {contact.comment && (
            <div className="contact-info-section">
              <h3>Комментарий</h3>
              <p className="contact-comment-text">{contact.comment}</p>
            </div>
          )}

          {/* Привязанные объекты */}
          <div className="contact-properties-section">
            <h3>Привязанные объекты ({contact.object_clients?.length || 0})</h3>
            {contact.object_clients && contact.object_clients.length > 0 ? (
              <table className="contact-properties-table">
                <thead>
                  <tr>
                    <th>Адрес</th>
                    <th>Цена</th>
                    <th>Тип сделки</th>
                    <th>Стадия</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {contact.object_clients.map((oc: ObjectClientItem) => (
                    <tr key={oc.id}>
                      <td>{oc.property?.address || oc.property?.title || '\u2014'}</td>
                      <td>{formatPrice(oc.property?.price ?? null)}</td>
                      <td>
                        {oc.property?.deal_type
                          ? DEAL_TYPE_LABELS[oc.property.deal_type]
                          : '\u2014'}
                      </td>
                      <td>
                        {oc.pipeline_stage ? (
                          <span
                            className="stage-badge"
                            style={{
                              backgroundColor: oc.pipeline_stage.color + '20',
                              color: oc.pipeline_stage.color,
                              borderColor: oc.pipeline_stage.color,
                            }}
                          >
                            {oc.pipeline_stage.name}
                          </span>
                        ) : '\u2014'}
                      </td>
                      <td>
                        {oc.property_id && (
                          <button
                            className="btn-link"
                            onClick={() => navigate(`/properties/${oc.property_id}`)}
                          >
                            Перейти
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="empty-text">Нет привязанных объектов</p>
            )}
          </div>
        </div>

        {/* Боковая панель */}
        <div className="contact-card-sidebar">
          <div className="sidebar-section">
            <h4>Контакты</h4>
            <div className="contact-list">
              {contact.phone && (
                <div className="contact-item">
                  <span className="material-icons">phone</span>
                  <a href={`tel:${contact.phone}`}>{contact.phone}</a>
                </div>
              )}
              {contact.phone_secondary && (
                <div className="contact-item">
                  <span className="material-icons">phone</span>
                  <a href={`tel:${contact.phone_secondary}`}>{contact.phone_secondary}</a>
                </div>
              )}
              {contact.email && (
                <div className="contact-item">
                  <span className="material-icons">email</span>
                  <a href={`mailto:${contact.email}`}>{contact.email}</a>
                </div>
              )}
              {contact.telegram_username && (
                <div className="contact-item">
                  <span className="material-icons">telegram</span>
                  <a href={`https://t.me/${contact.telegram_username}`} target="_blank" rel="noreferrer">
                    @{contact.telegram_username}
                  </a>
                </div>
              )}
              {!contact.phone && !contact.email && !contact.telegram_username && (
                <p className="empty-text">Нет контактных данных</p>
              )}
            </div>
          </div>

          <div className="sidebar-section">
            <h4>Даты</h4>
            <div className="dates-list">
              <div className="date-item">
                <span>Создан:</span>
                <span>{formatDate(contact.created_at)}</span>
              </div>
              <div className="date-item">
                <span>Обновлён:</span>
                <span>{formatDate(contact.updated_at)}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Модальное окно редактирования */}
      {showEditForm && (
        <ContactForm
          isOpen={showEditForm}
          onClose={() => setShowEditForm(false)}
          onSaved={() => { setShowEditForm(false); loadContact(); }}
          editData={contact}
        />
      )}

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

export default ContactCard;
