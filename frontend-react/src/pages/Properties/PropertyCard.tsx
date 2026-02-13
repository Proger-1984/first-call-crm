import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { propertiesApi } from '../../services/api';
import { Tooltip } from '../../components/UI/Tooltip';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { ContactPicker } from './ContactPicker';
import { PropertyForm } from './PropertyForm';
import { ContactForm } from '../Contacts/ContactForm';
import type { Property, ObjectClientItem } from '../../types/property';
import { DEAL_TYPE_LABELS } from '../../types/property';
import './PropertyCard.css';

export function PropertyCard() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const propertyId = Number(id);

  const [property, setProperty] = useState<Property | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showEditForm, setShowEditForm] = useState(false);
  const [showContactPicker, setShowContactPicker] = useState(false);
  const [showCreateContactForm, setShowCreateContactForm] = useState(false);

  // Диалог подтверждения
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  /** Загрузка объекта */
  const loadProperty = useCallback(async () => {
    if (!propertyId) return;
    setLoading(true);
    setError(null);
    try {
      const response = await propertiesApi.getById(propertyId);
      setProperty(response.data?.data?.property || null);
    } catch {
      setError('Ошибка загрузки объекта');
    } finally {
      setLoading(false);
    }
  }, [propertyId]);

  useEffect(() => {
    loadProperty();
  }, [loadProperty]);

  /** Показать ошибку через диалог */
  const showError = (message: string) => {
    setDialog({ title: 'Ошибка', message, variant: 'danger', onConfirm: () => setDialog(null) });
  };

  /** Отвязать контакт */
  const handleDetachContact = async (contactId: number) => {
    try {
      await propertiesApi.detachContact(propertyId, contactId);
      loadProperty();
    } catch {
      showError('Ошибка отвязки контакта');
    }
  };

  /** Архивировать / разархивировать */
  const handleArchive = async () => {
    if (!property) return;
    const archive = !property.is_archived;
    try {
      await propertiesApi.archive(propertyId, archive);
      loadProperty();
    } catch {
      showError('Не удалось выполнить операцию');
    }
  };

  /** Удалить объект */
  const handleDelete = () => {
    setDialog({
      title: 'Удаление объекта',
      message: 'Удалить объект? Это действие нельзя отменить.',
      confirmText: 'Удалить',
      cancelText: 'Отмена',
      variant: 'danger',
      onConfirm: async () => {
        setDialog(null);
        try {
          await propertiesApi.delete(propertyId);
          navigate('/properties');
        } catch {
          showError('Ошибка удаления');
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

  /** Форматирование площади */
  const formatArea = (value: number | null): string => {
    if (!value) return '\u2014';
    return `${value} \u043C\u00B2`;
  };

  /** Форматирование этажа */
  const formatFloor = (floor: number | null, floorsTotal: number | null): string => {
    if (!floor) return '\u2014';
    if (floorsTotal) return `${floor} / ${floorsTotal}`;
    return String(floor);
  };

  // --- Состояния загрузки ---

  if (loading) {
    return (
      <div className="property-card-page">
        <div className="loading-state">
          <div className="loading-spinner" />
          <p>Загрузка...</p>
        </div>
      </div>
    );
  }

  if (error || !property) {
    return (
      <div className="property-card-page">
        <div className="error-state">
          <span className="material-icons">error_outline</span>
          <p>{error || 'Объект не найден'}</p>
          <button onClick={() => navigate('/properties')}>К списку</button>
        </div>
      </div>
    );
  }

  return (
    <div className="property-card-page">
      {/* Шапка */}
      <div className="card-top-bar">
        <button className="back-btn" onClick={() => navigate('/properties')}>
          <span className="material-icons">arrow_back</span>
          Объекты
        </button>
        <div className="card-top-actions">
          <Tooltip content="Редактировать" position="bottom">
            <button className="action-btn" onClick={() => setShowEditForm(true)}>
              <span className="material-icons">edit</span>
            </button>
          </Tooltip>
          <Tooltip content={property.is_archived ? 'Восстановить' : 'В архив'} position="bottom">
            <button className="action-btn" onClick={handleArchive}>
              <span className="material-icons">{property.is_archived ? 'unarchive' : 'archive'}</span>
            </button>
          </Tooltip>
          <Tooltip content="Удалить" position="bottom">
            <button className="action-btn danger" onClick={handleDelete}>
              <span className="material-icons">delete_outline</span>
            </button>
          </Tooltip>
        </div>
      </div>

      <div className="card-layout">
        {/* Основная информация */}
        <div className="card-main">
          {/* Заголовок: адрес + бейдж типа сделки */}
          <div className="card-header-section">
            <h1 className="property-card-title">
              {property.address || property.title || `Объект #${property.id}`}
            </h1>
            <div className="card-badges">
              <span className={`deal-badge-lg deal-${property.deal_type}`}>
                {DEAL_TYPE_LABELS[property.deal_type]}
              </span>
              {property.is_archived && <span className="archived-badge">Архив</span>}
            </div>
          </div>

          {/* Характеристики */}
          <div className="card-section">
            <h3>Характеристики</h3>
            <div className="property-info-grid">
              <div className="property-info-item">
                <span className="property-info-label">Цена</span>
                <span className="property-info-value">{formatPrice(property.price)}</span>
              </div>
              <div className="property-info-item">
                <span className="property-info-label">Площадь</span>
                <span className="property-info-value">{formatArea(property.area)}</span>
              </div>
              <div className="property-info-item">
                <span className="property-info-label">Этаж</span>
                <span className="property-info-value">{formatFloor(property.floor, property.floors_total)}</span>
              </div>
              <div className="property-info-item">
                <span className="property-info-label">Комнаты</span>
                <span className="property-info-value">{property.rooms ?? '\u2014'}</span>
              </div>
            </div>
          </div>

          {/* Описание */}
          {property.description && (
            <div className="card-section">
              <h3>Описание</h3>
              <p className="property-description">{property.description}</p>
            </div>
          )}

          {/* Комментарий агента */}
          {property.comment && (
            <div className="card-section">
              <h3>Комментарий агента</h3>
              <p className="card-comment">{property.comment}</p>
            </div>
          )}

          {/* Привязанные клиенты */}
          <div className="property-clients-section">
            <div className="section-header">
              <h3>Привязанные клиенты ({property.contacts_count || 0})</h3>
              <button className="btn-primary btn-sm" onClick={() => setShowContactPicker(true)}>
                <span className="material-icons">add</span>
                Добавить клиента
              </button>
            </div>
            {property.object_clients && property.object_clients.length > 0 ? (
              <table className="property-clients-table">
                <thead>
                  <tr>
                    <th>Имя</th>
                    <th>Телефон</th>
                    <th>Стадия</th>
                    <th>След. контакт</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {property.object_clients.map((oc: ObjectClientItem) => (
                    <tr key={oc.id}>
                      <td>{oc.contact?.name || '\u2014'}</td>
                      <td>
                        {oc.contact?.phone ? (
                          <a href={`tel:${oc.contact.phone}`}>{oc.contact.phone}</a>
                        ) : '\u2014'}
                      </td>
                      <td>
                        {oc.pipeline_stage ? (
                          <span
                            className="client-stage-badge"
                            style={{ backgroundColor: oc.pipeline_stage.color }}
                          >
                            {oc.pipeline_stage.name}
                          </span>
                        ) : '\u2014'}
                      </td>
                      <td>
                        {oc.next_contact_at ? (
                          <span className={`client-next-contact ${new Date(oc.next_contact_at) < new Date() ? 'overdue' : ''}`}>
                            {formatDate(oc.next_contact_at)}
                          </span>
                        ) : '\u2014'}
                      </td>
                      <td>
                        <Tooltip content="Отвязать" position="left">
                          <button
                            className="remove-btn"
                            onClick={() => handleDetachContact(oc.contact_id)}
                          >
                            <span className="material-icons">close</span>
                          </button>
                        </Tooltip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="empty-text">Нет привязанных клиентов</p>
            )}
          </div>
        </div>

        {/* Боковая панель */}
        <div className="card-sidebar">
          {/* Собственник */}
          <div className="property-owner-section">
            <h4>Собственник</h4>
            {property.owner_name ? (
              <>
                <p className="owner-name">{property.owner_name}</p>
                <div className="owner-contacts">
                  {property.owner_phone && (
                    <div className="owner-contact-item">
                      <span className="material-icons">phone</span>
                      <a href={`tel:${property.owner_phone}`}>{property.owner_phone}</a>
                    </div>
                  )}
                  {property.owner_phone_secondary && (
                    <div className="owner-contact-item">
                      <span className="material-icons">phone</span>
                      <a href={`tel:${property.owner_phone_secondary}`}>{property.owner_phone_secondary}</a>
                    </div>
                  )}
                </div>
              </>
            ) : (
              <p className="empty-text">Не указан</p>
            )}
          </div>

          {/* Источник */}
          <div className="sidebar-section">
            <h4>Источник</h4>
            {property.source_type ? (
              <p>{property.source_type}</p>
            ) : (
              <p className="empty-text">Не указан</p>
            )}
            {property.source_details && <p className="source-details">{property.source_details}</p>}
          </div>

          {/* Ссылка на объявление */}
          {property.listing_id && (
            <div className="sidebar-section">
              <h4>Объявление</h4>
              {property.listing?.url ? (
                <a
                  className="listing-link"
                  href={property.listing.url}
                  target="_blank"
                  rel="noreferrer"
                >
                  <span className="material-icons">open_in_new</span>
                  Перейти к объявлению
                </a>
              ) : (
                <p>ID: {property.listing_id}</p>
              )}
            </div>
          )}

          {/* Даты */}
          <div className="sidebar-section">
            <h4>Даты</h4>
            <div className="dates-list">
              <div className="date-item">
                <span>Создан:</span>
                <span>{formatDate(property.created_at)}</span>
              </div>
              <div className="date-item">
                <span>Обновлён:</span>
                <span>{formatDate(property.updated_at)}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Модальные окна */}
      {showEditForm && (
        <PropertyForm
          isOpen={showEditForm}
          onClose={() => setShowEditForm(false)}
          onSaved={() => { setShowEditForm(false); loadProperty(); }}
          editData={property}
        />
      )}

      <ContactPicker
        isOpen={showContactPicker}
        onClose={() => setShowContactPicker(false)}
        onSelect={async (contactId: number) => {
          try {
            await propertiesApi.attachContact(propertyId, contactId);
            setShowContactPicker(false);
            loadProperty();
          } catch (err: any) {
            const message = err?.response?.data?.message || 'Ошибка привязки контакта';
            showError(message);
          }
        }}
        onCreateNew={() => {
          setShowContactPicker(false);
          setShowCreateContactForm(true);
        }}
      />

      {/* Форма создания нового контакта (из ContactPicker) */}
      {showCreateContactForm && (
        <ContactForm
          isOpen={showCreateContactForm}
          onClose={() => setShowCreateContactForm(false)}
          onSaved={async (createdContactId?: number) => {
            setShowCreateContactForm(false);
            if (createdContactId) {
              try {
                await propertiesApi.attachContact(propertyId, createdContactId);
              } catch {
                // Контакт создан, привязку можно сделать вручную
              }
            }
            loadProperty();
          }}
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

export default PropertyCard;
