import { useState, useEffect, useRef, useCallback } from 'react';
import { contactsApi } from '../../services/api';
import type { Contact } from '../../types/property';
import './ContactPicker.css';

interface ContactPickerProps {
  /** Модалка открыта */
  isOpen: boolean;
  /** Закрытие модалки */
  onClose: () => void;
  /** Выбор существующего контакта (может быть async) */
  onSelect: (contactId: number) => void | Promise<void>;
  /** Создание нового контакта */
  onCreateNew: () => void;
}

/** Результат поиска контактов */
interface SearchResult {
  contacts: Contact[];
  loading: boolean;
  error: string | null;
}

export function ContactPicker({ isOpen, onClose, onSelect, onCreateNew }: ContactPickerProps) {
  const [query, setQuery] = useState('');
  const [searchResult, setSearchResult] = useState<SearchResult>({
    contacts: [],
    loading: false,
    error: null,
  });

  // Ref для debounce таймера
  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Ref для input (автофокус)
  const inputRef = useRef<HTMLInputElement>(null);

  /** Поиск контактов по запросу */
  const searchContacts = useCallback(async (searchQuery: string) => {
    if (searchQuery.length < 1) {
      setSearchResult({ contacts: [], loading: false, error: null });
      return;
    }

    setSearchResult(prev => ({ ...prev, loading: true, error: null }));

    try {
      const response = await contactsApi.search(searchQuery);
      const contacts = response.data?.data?.contacts || [];
      setSearchResult({ contacts, loading: false, error: null });
    } catch {
      setSearchResult({ contacts: [], loading: false, error: 'Ошибка поиска контактов' });
    }
  }, []);

  /** Debounce-поиск при изменении запроса */
  useEffect(() => {
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
    }

    if (query.length < 1) {
      setSearchResult({ contacts: [], loading: false, error: null });
      return;
    }

    debounceTimerRef.current = setTimeout(() => {
      searchContacts(query);
    }, 300);

    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }
    };
  }, [query, searchContacts]);

  /** Автофокус при открытии */
  useEffect(() => {
    if (isOpen && inputRef.current) {
      // Небольшая задержка для завершения анимации
      const timer = setTimeout(() => {
        inputRef.current?.focus();
      }, 100);
      return () => clearTimeout(timer);
    }
  }, [isOpen]);

  /** Сброс при закрытии */
  useEffect(() => {
    if (!isOpen) {
      setQuery('');
      setSearchResult({ contacts: [], loading: false, error: null });
    }
  }, [isOpen]);

  /** Закрытие по Escape */
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && isOpen) {
        onClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  /** Закрытие по клику на overlay */
  const handleOverlayClick = (event: React.MouseEvent) => {
    if (event.target === event.currentTarget) {
      onClose();
    }
  };

  // Состояние привязки (блокируем кнопки пока идёт запрос)
  const [isAttaching, setIsAttaching] = useState(false);

  /** Выбор контакта — ждём завершения async onSelect, не закрываем сами */
  const handleSelect = async (contactId: number) => {
    setIsAttaching(true);
    try {
      await onSelect(contactId);
    } finally {
      setIsAttaching(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="contact-picker-overlay" onClick={handleOverlayClick}>
      <div className="contact-picker-modal">
        {/* Заголовок */}
        <div className="modal-header">
          <h2>Привязать контакт</h2>
          <button className="close-btn" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        {/* Поле поиска */}
        <div className="contact-picker-search-wrapper">
          <span className="material-icons search-icon">search</span>
          <input
            ref={inputRef}
            type="text"
            className="contact-picker-search"
            placeholder="Поиск по имени, телефону, email..."
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          {query && (
            <button className="search-clear-btn" onClick={() => setQuery('')}>
              <span className="material-icons">close</span>
            </button>
          )}
        </div>

        {/* Результаты поиска */}
        <div className="contact-picker-results">
          {searchResult.loading && (
            <div className="contact-picker-loading">
              <div className="loading-spinner" />
              <span>Поиск...</span>
            </div>
          )}

          {searchResult.error && (
            <div className="contact-picker-empty">{searchResult.error}</div>
          )}

          {!searchResult.loading && !searchResult.error && query.length >= 1 && searchResult.contacts.length === 0 && (
            <div className="contact-picker-empty">
              Контакты не найдены
            </div>
          )}

          {!searchResult.loading && searchResult.contacts.length > 0 && (
            searchResult.contacts.map(contact => (
              <div key={contact.id} className="contact-picker-item">
                <div className="contact-picker-item-info">
                  <div className="contact-picker-item-name">
                    <span className="material-icons contact-picker-item-icon">person</span>
                    {contact.name}
                  </div>
                  {contact.phone && (
                    <div className="contact-picker-item-phone">{contact.phone}</div>
                  )}
                  {contact.email && (
                    <div className="contact-picker-item-email">{contact.email}</div>
                  )}
                </div>
                <button
                  className="btn btn-sm btn-primary"
                  onClick={() => handleSelect(contact.id)}
                  disabled={isAttaching}
                >
                  {isAttaching ? 'Привязка...' : 'Привязать'}
                </button>
              </div>
            ))
          )}

          {!searchResult.loading && query.length < 1 && (
            <div className="contact-picker-empty">
              Введите имя, телефон или email для поиска
            </div>
          )}
        </div>

        {/* Футер: создать нового */}
        <div className="contact-picker-footer">
          <button className="btn btn-outline" onClick={onCreateNew}>
            <span className="material-icons">person_add</span>
            Создать нового контакта
          </button>
        </div>
      </div>
    </div>
  );
}

export default ContactPicker;
