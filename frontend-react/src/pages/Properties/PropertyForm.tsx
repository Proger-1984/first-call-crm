import { useState, useCallback } from 'react';
import { propertiesApi } from '../../services/api';
import { formatPhoneNumber, cleanPhoneNumber } from '../../utils/phoneMask';
import type { Property, DealType } from '../../types/property';
import { DEAL_TYPE_LABELS } from '../../types/property';
import './PropertyForm.css';

/** Режим ввода: из базы объявлений или ручной */
type InputMode = 'listing' | 'manual';

interface PropertyFormProps {
  /** Модалка открыта */
  isOpen: boolean;
  /** Закрыть модалку */
  onClose: () => void;
  /** Колбэк после успешного сохранения */
  onSaved: () => void;
  /** Данные для редактирования (если передано — режим редактирования) */
  editData?: Property;
}

export function PropertyForm({ isOpen, onClose, onSaved, editData }: PropertyFormProps) {
  const isEditing = Boolean(editData);

  // Режим ввода (только при создании)
  const [inputMode, setInputMode] = useState<InputMode>('manual');

  // Поле для режима «Из базы»
  const [listingIdInput, setListingIdInput] = useState('');

  // Основные поля
  const [title, setTitle] = useState(editData?.title || '');
  const [address, setAddress] = useState(editData?.address || '');
  const [price, setPrice] = useState(editData?.price?.toString() || '');
  const [rooms, setRooms] = useState(editData?.rooms?.toString() || '');
  const [area, setArea] = useState(editData?.area?.toString() || '');
  const [floor, setFloor] = useState(editData?.floor?.toString() || '');
  const [floorsTotal, setFloorsTotal] = useState(editData?.floors_total?.toString() || '');
  const [dealType, setDealType] = useState<DealType>(editData?.deal_type || 'sale');
  const [url, setUrl] = useState(editData?.url || '');

  // Собственник
  const [ownerName, setOwnerName] = useState(editData?.owner_name || '');
  const [ownerPhone, setOwnerPhone] = useState(
    editData?.owner_phone ? formatPhoneNumber(editData.owner_phone) : ''
  );
  const [ownerPhoneSecondary, setOwnerPhoneSecondary] = useState(
    editData?.owner_phone_secondary ? formatPhoneNumber(editData.owner_phone_secondary) : ''
  );

  // Дополнительно
  const [sourceType, setSourceType] = useState(editData?.source_type || '');
  const [sourceDetails, setSourceDetails] = useState(editData?.source_details || '');
  const [comment, setComment] = useState(editData?.comment || '');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** Обработчик ввода телефона с маской */
  const handlePhoneChange = useCallback((value: string, setter: (val: string) => void) => {
    setter(formatPhoneNumber(value));
  }, []);

  /** Отправка формы */
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      // Режим «Из базы»: создаём объект из listing_id
      if (!isEditing && inputMode === 'listing') {
        const parsedListingId = parseInt(listingIdInput, 10);
        if (!parsedListingId || isNaN(parsedListingId)) {
          setError('Укажите корректный ID объявления');
          setSaving(false);
          return;
        }

        const data: Record<string, any> = {
          listing_id: parsedListingId,
          deal_type: dealType,
        };
        // Собственник — опционально
        if (ownerName.trim()) data.owner_name = ownerName.trim();
        if (ownerPhone) data.owner_phone = cleanPhoneNumber(ownerPhone);

        await propertiesApi.create(data);
        onSaved();
        return;
      }

      // Режим «Ручной ввод» — название обязательно
      if (!isEditing && inputMode === 'manual' && !title.trim()) {
        setError('Укажите название объекта');
        setSaving(false);
        return;
      }

      const data: Record<string, any> = {
        title: title.trim() || null,
        address: address.trim() || null,
        price: price ? parseFloat(price) : null,
        rooms: rooms ? parseInt(rooms, 10) : null,
        area: area ? parseFloat(area) : null,
        floor: floor ? parseInt(floor, 10) : null,
        floors_total: floorsTotal ? parseInt(floorsTotal, 10) : null,
        deal_type: dealType,
        url: url.trim() || null,
        owner_name: ownerName.trim() || null,
        owner_phone: ownerPhone ? cleanPhoneNumber(ownerPhone) : null,
        owner_phone_secondary: ownerPhoneSecondary ? cleanPhoneNumber(ownerPhoneSecondary) : null,
        source_type: sourceType.trim() || null,
        source_details: sourceDetails.trim() || null,
        comment: comment.trim() || null,
      };

      if (isEditing && editData) {
        await propertiesApi.update(editData.id, data);
      } else {
        await propertiesApi.create(data);
      }

      onSaved();
    } catch (err: any) {
      setError(err.response?.data?.message || 'Ошибка сохранения');
    } finally {
      setSaving(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="modal-overlay">
      <div className="property-form-modal">
        <div className="modal-header">
          <h2>{isEditing ? 'Редактирование объекта' : 'Новый объект'}</h2>
          <button className="close-btn" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        {/* Табы — только при создании */}
        {!isEditing && (
          <div className="form-tabs">
            <button
              type="button"
              className={`form-tab ${inputMode === 'listing' ? 'active' : ''}`}
              onClick={() => setInputMode('listing')}
            >
              Из базы
            </button>
            <button
              type="button"
              className={`form-tab ${inputMode === 'manual' ? 'active' : ''}`}
              onClick={() => setInputMode('manual')}
            >
              Ручной ввод
            </button>
          </div>
        )}

        <form onSubmit={handleSubmit} className="property-form">
          {error && <div className="form-error">{error}</div>}

          {/* --- Режим «Из базы» --- */}
          {!isEditing && inputMode === 'listing' && (
            <>
              <div className="form-row">
                <div className="form-field full">
                  <label>ID объявления *</label>
                  <input
                    type="number"
                    value={listingIdInput}
                    onChange={(e) => setListingIdInput(e.target.value)}
                    placeholder="Введите ID из базы объявлений"
                    autoFocus
                  />
                  <p className="listing-mode-hint">
                    Объект будет создан на основе данных объявления (адрес, цена, площадь и т.д.)
                  </p>
                </div>
              </div>

              <div className="form-row">
                <div className="form-field">
                  <label>Тип сделки</label>
                  <div className="radio-group">
                    {(Object.entries(DEAL_TYPE_LABELS) as [DealType, string][]).map(([value, label]) => (
                      <label key={value} className="radio-option">
                        <input
                          type="radio"
                          name="deal_type"
                          value={value}
                          checked={dealType === value}
                          onChange={() => setDealType(value)}
                        />
                        {label}
                      </label>
                    ))}
                  </div>
                </div>
              </div>

              <div className="form-section-label">Собственник (опционально)</div>

              <div className="form-row">
                <div className="form-field">
                  <label>Имя собственника</label>
                  <input
                    type="text"
                    value={ownerName}
                    onChange={(e) => setOwnerName(e.target.value)}
                    placeholder="ФИО"
                  />
                </div>
                <div className="form-field">
                  <label>Телефон</label>
                  <input
                    type="tel"
                    value={ownerPhone}
                    onChange={(e) => handlePhoneChange(e.target.value, setOwnerPhone)}
                    placeholder="+7 (___) ___-__-__"
                  />
                </div>
              </div>
            </>
          )}

          {/* --- Режим «Ручной ввод» или Редактирование --- */}
          {(isEditing || inputMode === 'manual') && (
            <>
              <div className="form-row">
                <div className="form-field full">
                  <label>Название *</label>
                  <input
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Например: 2-комн. квартира на Ленина"
                    autoFocus={!isEditing || inputMode === 'manual'}
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field full">
                  <label>Адрес</label>
                  <input
                    type="text"
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                    placeholder="Город, улица, дом"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field">
                  <label>Тип сделки</label>
                  <div className="radio-group">
                    {(Object.entries(DEAL_TYPE_LABELS) as [DealType, string][]).map(([value, label]) => (
                      <label key={value} className="radio-option">
                        <input
                          type="radio"
                          name="deal_type"
                          value={value}
                          checked={dealType === value}
                          onChange={() => setDealType(value)}
                        />
                        {label}
                      </label>
                    ))}
                  </div>
                </div>
                <div className="form-field">
                  <label>Цена</label>
                  <input
                    type="number"
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                    placeholder="0"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field">
                  <label>Комнаты</label>
                  <input
                    type="number"
                    value={rooms}
                    onChange={(e) => setRooms(e.target.value)}
                    placeholder="0"
                  />
                </div>
                <div className="form-field">
                  <label>Площадь, м²</label>
                  <input
                    type="text"
                    inputMode="decimal"
                    value={area}
                    onChange={(e) => {
                      const val = e.target.value.replace(',', '.');
                      if (val === '' || /^\d*\.?\d*$/.test(val)) setArea(val);
                    }}
                    placeholder="0"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field">
                  <label>Этаж</label>
                  <input
                    type="number"
                    value={floor}
                    onChange={(e) => setFloor(e.target.value)}
                    placeholder="0"
                  />
                </div>
                <div className="form-field">
                  <label>Этажность дома</label>
                  <input
                    type="number"
                    value={floorsTotal}
                    onChange={(e) => setFloorsTotal(e.target.value)}
                    placeholder="0"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field full">
                  <label>Ссылка (URL)</label>
                  <input
                    type="url"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    placeholder="https://..."
                  />
                </div>
              </div>

              <div className="form-section-label">Собственник</div>

              <div className="form-row">
                <div className="form-field full">
                  <label>Имя собственника</label>
                  <input
                    type="text"
                    value={ownerName}
                    onChange={(e) => setOwnerName(e.target.value)}
                    placeholder="ФИО"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field">
                  <label>Телефон</label>
                  <input
                    type="tel"
                    value={ownerPhone}
                    onChange={(e) => handlePhoneChange(e.target.value, setOwnerPhone)}
                    placeholder="+7 (___) ___-__-__"
                  />
                </div>
                <div className="form-field">
                  <label>Доп. телефон</label>
                  <input
                    type="tel"
                    value={ownerPhoneSecondary}
                    onChange={(e) => handlePhoneChange(e.target.value, setOwnerPhoneSecondary)}
                    placeholder="+7 (___) ___-__-__"
                  />
                </div>
              </div>

              <div className="form-section-label">Дополнительно</div>

              <div className="form-row">
                <div className="form-field">
                  <label>Источник</label>
                  <input
                    type="text"
                    value={sourceType}
                    onChange={(e) => setSourceType(e.target.value)}
                    placeholder="avito, cian, звонок..."
                  />
                </div>
                <div className="form-field">
                  <label>Детали источника</label>
                  <input
                    type="text"
                    value={sourceDetails}
                    onChange={(e) => setSourceDetails(e.target.value)}
                    placeholder="Ссылка или описание"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-field full">
                  <label>Комментарий</label>
                  <textarea
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    placeholder="Заметки об объекте..."
                    rows={3}
                  />
                </div>
              </div>
            </>
          )}

          <div className="form-actions">
            <button type="button" className="btn-cancel" onClick={onClose}>
              <span className="material-icons">close</span>
              Отмена
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              <span className="material-icons">{isEditing ? 'save' : 'add'}</span>
              {saving ? 'Сохранение...' : (isEditing ? 'Сохранить' : 'Создать')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default PropertyForm;
