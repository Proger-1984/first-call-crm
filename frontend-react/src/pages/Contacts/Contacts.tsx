import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { contactsApi } from '../../services/api';
import { Pagination } from '../../components/UI/Pagination';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import { ContactForm } from './ContactForm';
import type { Contact, ContactFilters } from '../../types/property';
import './Contacts.css';

/** Конфигурация сортируемых колонок */
type SortField = 'created_at' | 'name' | 'phone';

const SORT_COLUMNS: { field: SortField; label: string }[] = [
  { field: 'name', label: 'Имя' },
  { field: 'phone', label: 'Телефон' },
  { field: 'created_at', label: 'Создан' },
];

interface PaginationInfo {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

/** Таймер для debounce-поиска */
const SEARCH_DEBOUNCE_MS = 400;

export function Contacts() {
  const navigate = useNavigate();

  // Список контактов
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [pagination, setPagination] = useState<PaginationInfo>({ page: 1, per_page: 20, total: 0, total_pages: 0 });
  const [loading, setLoading] = useState(true);
  const [isRefetching, setIsRefetching] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Флаг первой загрузки
  const isFirstLoad = useRef(true);

  // Фильтры
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [showArchived, setShowArchived] = useState(false);
  const [sortField, setSortField] = useState<SortField>('created_at');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');
  const [perPage, setPerPage] = useState(20);

  // Форма создания/редактирования
  const [showForm, setShowForm] = useState(false);
  const [editingContact, setEditingContact] = useState<Contact | undefined>(undefined);

  // Диалог подтверждения
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  /** Debounce для поиска */
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  /** Загрузка контактов */
  const loadContacts = useCallback(async (page = 1) => {
    if (isFirstLoad.current) {
      setLoading(true);
    } else {
      setIsRefetching(true);
    }
    setError(null);

    try {
      const params: ContactFilters = {
        page,
        per_page: perPage,
        sort: sortField,
        order: sortOrder,
      };

      if (debouncedSearch) params.search = debouncedSearch;
      if (showArchived) params.is_archived = true;

      const response = await contactsApi.getAll(params);
      const data = response.data?.data;
      setContacts(data?.contacts || []);
      setPagination(data?.pagination || { page: 1, per_page: perPage, total: 0, total_pages: 0 });
      isFirstLoad.current = false;
    } catch {
      setError('Ошибка загрузки контактов');
    } finally {
      setLoading(false);
      setIsRefetching(false);
    }
  }, [debouncedSearch, showArchived, sortField, sortOrder, perPage]);

  useEffect(() => {
    loadContacts();
  }, [loadContacts]);

  /** Открыть карточку контакта */
  const openContact = (contactId: number) => {
    navigate(`/contacts/${contactId}`);
  };

  /** Создать контакт */
  const handleCreate = () => {
    setEditingContact(undefined);
    setShowForm(true);
  };

  /** После сохранения формы */
  const handleFormSaved = () => {
    setShowForm(false);
    setEditingContact(undefined);
    loadContacts();
  };

  /** Удалить контакт */
  const handleDelete = (contactId: number) => {
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
          loadContacts();
        } catch {
          setDialog({ title: 'Ошибка', message: 'Не удалось удалить контакт', variant: 'danger', onConfirm: () => setDialog(null) });
        }
      },
    });
  };

  /** Обработчик сортировки */
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortOrder('desc');
    }
  };

  /** Иконка сортировки */
  const getSortIcon = (field: SortField): string => {
    if (sortField !== field) return 'unfold_more';
    return sortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward';
  };

  /** Смена страницы */
  const handlePageChange = (page: number) => {
    loadContacts(page);
  };

  /** Смена perPage */
  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
  };

  /** Форматирование даты */
  const formatDate = (dateStr: string | null): string => {
    if (!dateStr) return '\u2014';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
  };

  return (
    <div className="contacts-page">
      {/* Заголовок */}
      <div className="contacts-header">
        <h1>Контакты</h1>
        <button className="btn btn-primary" onClick={handleCreate}>
          <span className="material-icons">person_add</span>
          Новый контакт
        </button>
      </div>

      {/* Фильтры */}
      <div className="card contacts-filters">
        <div className="contacts-filters-row">
          {/* Поиск */}
          <div className="contacts-filter-group">
            <label>Поиск</label>
            <div className="contacts-filter-with-clear">
              <input
                type="text"
                className="contacts-filter-input"
                placeholder="Имя, телефон, email..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button
                  type="button"
                  className="contacts-clear-btn"
                  onClick={() => setSearchQuery('')}
                  title="Очистить поиск"
                >
                  <span className="material-icons">close</span>
                </button>
              )}
            </div>
          </div>

          {/* Чекбокс "Показать архив" */}
          <div className="contacts-filter-group">
            <label>&nbsp;</label>
            <label className="contacts-checkbox-label">
              <input
                type="checkbox"
                checked={showArchived}
                onChange={(e) => setShowArchived(e.target.checked)}
              />
              Показать архив
            </label>
          </div>

          {/* Кнопки */}
          <div className="contacts-filter-group">
            <label>&nbsp;</label>
            <div className="contacts-filter-actions">
              <button
                type="button"
                className="btn btn-primary"
                onClick={() => loadContacts()}
              >
                Применить
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => {
                  setSearchQuery('');
                  setShowArchived(false);
                  setSortField('created_at');
                  setSortOrder('desc');
                }}
              >
                Сбросить
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Контент */}
      {loading && isFirstLoad.current ? (
        <div className="loading-state">
          <div className="loading-spinner" />
          <p>Загрузка контактов...</p>
        </div>
      ) : error ? (
        <div className="error-state">
          <span className="material-icons">error_outline</span>
          <p>{error}</p>
          <button onClick={() => loadContacts()}>Повторить</button>
        </div>
      ) : contacts.length === 0 && !isRefetching ? (
        <div className="empty-state">
          <span className="material-icons">contacts</span>
          <p>{showArchived ? 'Архив пуст' : 'Контактов пока нет'}</p>
          {!showArchived && (
            <button className="btn btn-primary" onClick={handleCreate}>
              <span className="material-icons">person_add</span>
              Добавить первый контакт
            </button>
          )}
        </div>
      ) : (
        <>
          {/* Таблица контактов */}
          <div className="contacts-table-wrapper">
            {isRefetching && <div className="table-refetch-overlay" />}
            <table className="contacts-table">
              <thead>
                <tr>
                  <th
                    className={`sortable ${sortField === 'name' ? 'sorted' : ''}`}
                    onClick={() => handleSort('name')}
                  >
                    Имя
                    <span className="material-icons sort-icon">{getSortIcon('name')}</span>
                  </th>
                  <th
                    className={`sortable ${sortField === 'phone' ? 'sorted' : ''}`}
                    onClick={() => handleSort('phone')}
                  >
                    Телефон
                    <span className="material-icons sort-icon">{getSortIcon('phone')}</span>
                  </th>
                  <th>Email</th>
                  <th>Telegram</th>
                  <th>Объектов</th>
                  <th
                    className={`sortable ${sortField === 'created_at' ? 'sorted' : ''}`}
                    onClick={() => handleSort('created_at')}
                  >
                    Создан
                    <span className="material-icons sort-icon">{getSortIcon('created_at')}</span>
                  </th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {contacts.map(contact => (
                  <tr
                    key={contact.id}
                    className={contact.is_archived ? 'archived' : ''}
                    onClick={() => openContact(contact.id)}
                  >
                    <td className="cell-name">{contact.name}</td>
                    <td className="cell-phone">
                      {contact.phone ? (
                        <a
                          href={`tel:${contact.phone}`}
                          onClick={(e) => e.stopPropagation()}
                          className="phone-link"
                        >
                          {contact.phone}
                        </a>
                      ) : '\u2014'}
                    </td>
                    <td className="cell-email">
                      {contact.email ? (
                        <a
                          href={`mailto:${contact.email}`}
                          onClick={(e) => e.stopPropagation()}
                          className="email-link"
                        >
                          {contact.email}
                        </a>
                      ) : '\u2014'}
                    </td>
                    <td className="cell-telegram">
                      {contact.telegram_username ? (
                        <a
                          href={`https://t.me/${contact.telegram_username}`}
                          target="_blank"
                          rel="noreferrer"
                          onClick={(e) => e.stopPropagation()}
                          className="telegram-link"
                        >
                          @{contact.telegram_username}
                        </a>
                      ) : '\u2014'}
                    </td>
                    <td className="cell-count">{contact.properties_count ?? 0}</td>
                    <td className="cell-date">{formatDate(contact.created_at)}</td>
                    <td className="cell-actions" onClick={(e) => e.stopPropagation()}>
                      <div className="cell-actions-inner">
                        <button
                          className="action-btn danger"
                          title="Удалить"
                          onClick={() => handleDelete(contact.id)}
                        >
                          <span className="material-icons">delete_outline</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Пагинация */}
          {pagination.total_pages > 0 && (
            <Pagination
              page={pagination.page}
              totalPages={pagination.total_pages}
              perPage={perPage}
              total={pagination.total}
              onPageChange={handlePageChange}
              onPerPageChange={handlePerPageChange}
            />
          )}
        </>
      )}

      {/* Модальная форма создания/редактирования */}
      {showForm && (
        <ContactForm
          isOpen={showForm}
          onClose={() => { setShowForm(false); setEditingContact(undefined); }}
          onSaved={handleFormSaved}
          editData={editingContact}
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

export default Contacts;
