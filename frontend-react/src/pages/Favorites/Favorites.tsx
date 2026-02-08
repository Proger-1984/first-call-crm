import { useState, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { favoritesApi, type FavoriteListing, type FavoriteStatus } from '../../services/api';
import { SourceBadge } from '../../components/UI/Badge';
import { DatePicker } from '../../components/UI/DatePicker';
import { Tooltip, Pagination } from '../../components/UI';
import './Favorites.css';

// Маппинг source_id -> код источника
const sourceIdToCode: Record<number, 'avito' | 'yandex' | 'cian' | 'ula'> = {
  1: 'avito',
  2: 'yandex',
  3: 'cian',
  4: 'ula',
};

// Форматирование цены
function formatPrice(price: number | null | undefined): string {
  if (!price) return '—';
  return price.toLocaleString('ru-RU') + ' ₽';
}

// Форматирование телефона
function formatPhone(phone: string | null | undefined): string {
  if (!phone) return '—';
  return phone;
}

// Доли колонок таблицы (%). Порядок: Дата, Сердечко, Заголовок, Источник, Цена, Адрес, Контакт, Статус, Комментарий
const FAVORITES_COL_WIDTHS = ['8%', '4%', '18%', '7%', '8%', '24%', '10%', '10%', '11%'] as const;
type SortField = 'created_at' | 'source' | 'price' | 'status';

export function Favorites() {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [sortField, setSortField] = useState<SortField>('created_at');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');
  
  // Фильтры
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [commentSearch, setCommentSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<number | 'none' | ''>('');
  
  // Применённые фильтры (для запроса)
  const [appliedFilters, setAppliedFilters] = useState<{
    dateFrom: string;
    dateTo: string;
    comment: string;
    statusId?: number | 'none';
  }>({
    dateFrom: '',
    dateTo: '',
    comment: '',
  });
  
  const [editingCommentId, setEditingCommentId] = useState<number | null>(null);
  const [commentText, setCommentText] = useState('');
  
  // Управление статусами
  const [showStatusManager, setShowStatusManager] = useState(false);
  const [newStatusName, setNewStatusName] = useState('');
  const [newStatusColor, setNewStatusColor] = useState('#808080');
  const [editingStatusId, setEditingStatusId] = useState<number | null>(null);
  const [editingStatusName, setEditingStatusName] = useState('');
  const [editingStatusColor, setEditingStatusColor] = useState('');
  
  const queryClient = useQueryClient();

  // Загрузка статусов
  const { data: statusesResponse, refetch: refetchStatuses } = useQuery({
    queryKey: ['favoriteStatuses'],
    queryFn: () => favoritesApi.getStatuses(),
    staleTime: 0,
    gcTime: 0,
  });
  const statuses = statusesResponse?.data?.data?.statuses || [];

  // Загрузка избранных
  const { data: favoritesResponse, isLoading, error, refetch: refetchFavorites } = useQuery({
    queryKey: ['favorites', page, perPage, sortField, sortOrder, appliedFilters],
    queryFn: () => favoritesApi.getList({
      page,
      perPage,
      sortBy: sortField,
      order: sortOrder,
      dateFrom: appliedFilters.dateFrom || undefined,
      dateTo: appliedFilters.dateTo || undefined,
      comment: appliedFilters.comment || undefined,
      statusId: appliedFilters.statusId,
    }),
    staleTime: 0,
    gcTime: 0,
  });

  const favorites = favoritesResponse?.data?.data;
  const listings = favorites?.listings || [];
  const pagination = favorites?.pagination;
  const total = pagination?.total || 0;
  const totalPages = pagination?.total_pages || 1;

  // Мутация для удаления из избранного
  const toggleMutation = useMutation({
    mutationFn: (listingId: number) => favoritesApi.toggle(listingId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['favorites'] });
      queryClient.invalidateQueries({ queryKey: ['listings'] });
    },
  });

  // Мутация для обновления комментария
  const commentMutation = useMutation({
    mutationFn: ({ listingId, comment }: { listingId: number; comment: string | null }) => 
      favoritesApi.updateComment(listingId, comment),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['favorites'] });
      setEditingCommentId(null);
      setCommentText('');
    },
  });

  // Мутация для обновления статуса избранного
  const updateStatusMutation = useMutation({
    mutationFn: ({ listingId, statusId }: { listingId: number; statusId: number | null }) => 
      favoritesApi.updateStatus(listingId, statusId),
    onSuccess: (data, variables) => {
      // Обновляем данные локально без refetch, чтобы объявление не перемещалось
      queryClient.setQueryData(['favorites', page, perPage, sortField, sortOrder, appliedFilters], (oldData: any) => {
        if (!oldData?.data?.data?.listings) return oldData;
        
        const newStatus = data.data?.data?.status || null;
        const updatedListings = oldData.data.data.listings.map((listing: FavoriteListing) => 
          listing.id === variables.listingId 
            ? { ...listing, status: newStatus }
            : listing
        );
        
        return {
          ...oldData,
          data: {
            ...oldData.data,
            data: {
              ...oldData.data.data,
              listings: updatedListings
            }
          }
        };
      });
      // Обновляем счётчики статусов
      queryClient.invalidateQueries({ queryKey: ['favoriteStatuses'] });
    },
  });

  // Мутации для управления статусами
  const createStatusMutation = useMutation({
    mutationFn: ({ name, color }: { name: string; color: string }) => 
      favoritesApi.createStatus(name, color),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['favoriteStatuses'] });
      setNewStatusName('');
      setNewStatusColor('#808080');
    },
  });

  const updateStatusMutation2 = useMutation({
    mutationFn: ({ statusId, data }: { statusId: number; data: { name?: string; color?: string } }) => 
      favoritesApi.updateStatusInfo(statusId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['favoriteStatuses'] });
      queryClient.invalidateQueries({ queryKey: ['favorites'] });
      setEditingStatusId(null);
    },
  });

  const deleteStatusMutation = useMutation({
    mutationFn: (statusId: number) => favoritesApi.deleteStatus(statusId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['favoriteStatuses'] });
      queryClient.invalidateQueries({ queryKey: ['favorites'] });
    },
  });

  const handleRemoveFavorite = (listingId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    toggleMutation.mutate(listingId);
  };

  // Конвертация даты из dd.mm.yyyy в yyyy-mm-dd для API
  const convertDateForApi = (dateStr: string): string => {
    if (!dateStr) return '';
    const parts = dateStr.split('.');
    if (parts.length === 3) {
      return `${parts[2]}-${parts[1]}-${parts[0]}`;
    }
    return dateStr;
  };

  // Применить фильтры
  const handleApplyFilters = useCallback(() => {
    setAppliedFilters({
      dateFrom: convertDateForApi(dateFrom),
      dateTo: convertDateForApi(dateTo),
      comment: commentSearch,
      statusId: statusFilter || undefined,
    });
    setPage(1);
    // Принудительно обновляем данные
    refetchFavorites();
    refetchStatuses();
  }, [dateFrom, dateTo, commentSearch, statusFilter, refetchFavorites, refetchStatuses]);

  // Сбросить фильтры
  const handleResetFilters = useCallback(() => {
    setDateFrom('');
    setDateTo('');
    setCommentSearch('');
    setStatusFilter('');
    setAppliedFilters({
      dateFrom: '',
      dateTo: '',
      comment: '',
    });
    setPage(1);
  }, []);

  // Обработчики статусов
  const handleCreateStatus = useCallback(() => {
    if (newStatusName.trim()) {
      createStatusMutation.mutate({ name: newStatusName.trim(), color: newStatusColor });
    }
  }, [newStatusName, newStatusColor, createStatusMutation]);

  const handleStartEditStatus = useCallback((status: FavoriteStatus) => {
    setEditingStatusId(status.id);
    setEditingStatusName(status.name);
    setEditingStatusColor(status.color);
  }, []);

  const handleSaveStatus = useCallback(() => {
    if (editingStatusId && editingStatusName.trim()) {
      updateStatusMutation2.mutate({
        statusId: editingStatusId,
        data: { name: editingStatusName.trim(), color: editingStatusColor }
      });
    }
  }, [editingStatusId, editingStatusName, editingStatusColor, updateStatusMutation2]);

  const handleDeleteStatus = useCallback((statusId: number) => {
    if (confirm('Удалить статус? Объявления с этим статусом останутся без статуса.')) {
      deleteStatusMutation.mutate(statusId);
    }
  }, [deleteStatusMutation]);

  const handleChangeListingStatus = useCallback((listingId: number, statusId: number | null) => {
    updateStatusMutation.mutate({ listingId, statusId });
  }, [updateStatusMutation]);

  // Сортировка по колонке: при клике по той же колонке — переключаем порядок, иначе — новая колонка, по умолчанию desc
  const handleSort = useCallback((field: SortField) => {
    setSortField(prev => {
      if (prev === field) {
        setSortOrder(o => (o === 'desc' ? 'asc' : 'desc'));
        return field;
      }
      setSortOrder('desc');
      return field;
    });
    setPage(1);
  }, []);

  // Начать редактирование комментария
  const handleStartEditComment = useCallback((listing: FavoriteListing) => {
    setEditingCommentId(listing.id);
    setCommentText(listing.comment || '');
  }, []);

  // Сохранить комментарий
  const handleSaveComment = useCallback((listingId: number) => {
    const trimmedComment = commentText.trim();
    commentMutation.mutate({ 
      listingId, 
      comment: trimmedComment || null 
    });
  }, [commentText, commentMutation]);

  // Отменить редактирование
  const handleCancelEdit = useCallback(() => {
    setEditingCommentId(null);
    setCommentText('');
  }, []);

  // Пагинация
  const handlePageChange = (newPage: number) => {
    if (newPage >= 1 && newPage <= totalPages) {
      setPage(newPage);
    }
  };

  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
    setPage(1);
  };

  // Проверка, есть ли активные фильтры
  const hasActiveFilters = appliedFilters.dateFrom || appliedFilters.dateTo || appliedFilters.comment || appliedFilters.statusId;

  if (isLoading) {
    return (
      <div className="favorites-page">
        <div className="favorites-header">
          <h1>Избранное</h1>
        </div>
        <div className="card">
          <div className="loading-state">
            <span className="material-icons spinning">sync</span>
            <span>Загрузка...</span>
          </div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="favorites-page">
        <div className="favorites-header">
          <h1>Избранное</h1>
        </div>
        <div className="card">
          <div className="error-state">
            <span className="material-icons">error_outline</span>
            <span>Ошибка загрузки избранного</span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="favorites-page">
      <div className="favorites-header">
        <h1>Избранное</h1>
        {total > 0 && (
          <span className="favorites-count">{total} объявлений</span>
        )}
      </div>

      {/* Фильтры */}
      <div className="favorites-filters card">
        <div className="filters-row">
          <div className="filter-group">
            <label>Дата от</label>
            <DatePicker 
              placeholder="От" 
              value={dateFrom} 
              onChange={setDateFrom} 
            />
          </div>
          <div className="filter-group">
            <label>Дата до</label>
            <DatePicker 
              placeholder="До" 
              value={dateTo} 
              onChange={setDateTo} 
            />
          </div>
          <div className="filter-group">
            <label>Статус</label>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value === '' ? '' : e.target.value === 'none' ? 'none' : Number(e.target.value))}
              className="form-control"
            >
              <option value="">Все</option>
              <option value="none">Без статуса</option>
              {statuses.map((status) => (
                <option key={status.id} value={status.id}>
                  {status.name}
                </option>
              ))}
            </select>
          </div>
          <div className="filter-group filter-group-wide">
            <label>Поиск по комментарию</label>
            <input
              type="text"
              value={commentSearch}
              onChange={(e) => setCommentSearch(e.target.value)}
              placeholder="Введите текст..."
              className="filter-input"
              onKeyDown={(e) => e.key === 'Enter' && handleApplyFilters()}
            />
          </div>
          <div className="filter-actions">
            <button className="btn btn-primary" onClick={handleApplyFilters}>
              Применить
            </button>
            {hasActiveFilters && (
              <button className="btn btn-secondary" onClick={handleResetFilters}>
                Сбросить
              </button>
            )}
            <Tooltip content="Управление статусами" position="bottom">
              <button 
                className="btn btn-icon" 
                onClick={() => setShowStatusManager(!showStatusManager)}
              >
                <span className="material-icons">settings</span>
              </button>
            </Tooltip>
          </div>
        </div>
        
        {/* Управление статусами */}
        {showStatusManager && (
          <div className="status-manager">
            <div className="status-manager-header">
              <h3>Управление статусами</h3>
            </div>
            
            {/* Создание нового статуса */}
            <div className="status-form">
              <input
                type="text"
                value={newStatusName}
                onChange={(e) => setNewStatusName(e.target.value)}
                placeholder="Название статуса"
                className="filter-input"
                maxLength={50}
              />
              <Tooltip content="Цвет статуса" position="top">
                <input
                  type="color"
                  value={newStatusColor}
                  onChange={(e) => setNewStatusColor(e.target.value)}
                  className="color-input"
                />
              </Tooltip>
              <button 
                className="btn btn-primary btn-sm"
                onClick={handleCreateStatus}
                disabled={!newStatusName.trim() || createStatusMutation.isPending}
              >
                <span className="material-icons">add</span>
                Добавить
              </button>
            </div>
            
            {/* Список статусов */}
            <div className="status-list">
              {statuses.length === 0 ? (
                <div className="status-empty">Нет созданных статусов</div>
              ) : (
                statuses.map((status) => (
                  <div key={status.id} className="status-item">
                    {editingStatusId === status.id ? (
                      <>
                        <input
                          type="text"
                          value={editingStatusName}
                          onChange={(e) => setEditingStatusName(e.target.value)}
                          className="filter-input status-name-input"
                          maxLength={50}
                        />
                        <input
                          type="color"
                          value={editingStatusColor}
                          onChange={(e) => setEditingStatusColor(e.target.value)}
                          className="color-input"
                        />
                        <Tooltip content="Сохранить" position="top">
                          <button 
                            className="btn-icon-sm" 
                            onClick={handleSaveStatus}
                            disabled={updateStatusMutation2.isPending}
                          >
                            <span className="material-icons">check</span>
                          </button>
                        </Tooltip>
                        <Tooltip content="Отмена" position="top">
                          <button 
                            className="btn-icon-sm" 
                            onClick={() => setEditingStatusId(null)}
                          >
                            <span className="material-icons">close</span>
                          </button>
                        </Tooltip>
                      </>
                    ) : (
                      <>
                        <span 
                          className="status-badge" 
                          style={{ backgroundColor: status.color }}
                        >
                          {status.name}
                        </span>
                        <span className="status-count">
                          {status.favorites_count || 0}
                        </span>
                        <Tooltip content="Редактировать" position="top">
                          <button 
                            className="btn-icon-sm" 
                            onClick={() => handleStartEditStatus(status)}
                          >
                            <span className="material-icons">edit</span>
                          </button>
                        </Tooltip>
                        <Tooltip content="Удалить" position="top">
                          <button 
                            className="btn-icon-sm danger" 
                            onClick={() => handleDeleteStatus(status.id)}
                            disabled={deleteStatusMutation.isPending}
                          >
                            <span className="material-icons">delete</span>
                          </button>
                        </Tooltip>
                      </>
                    )}
                  </div>
                ))
              )}
            </div>
          </div>
        )}
      </div>
      
      {listings.length === 0 ? (
        <div className="card">
          <div className="empty-state">
            <span className="material-icons">favorite_border</span>
            <h3>{hasActiveFilters ? 'Ничего не найдено' : 'Нет избранных объявлений'}</h3>
            <p>{hasActiveFilters ? 'Попробуйте изменить параметры фильтра' : 'Добавляйте объявления в избранное, нажимая на сердечко в списке'}</p>
          </div>
        </div>
      ) : (
        <div className="card">
          <div className="table-container">
            <table className="favorites-table" style={{ width: '100%', tableLayout: 'fixed' }}>
              <thead>
                <tr>
                  <th style={{ width: FAVORITES_COL_WIDTHS[0] }} className="sortable" onClick={() => handleSort('created_at')} title="Сортировка">
                    Дата
                    <span className={`material-icons sort-icon ${sortField === 'created_at' ? 'sort-icon-active' : 'sort-icon-inactive'}`}>
                      {sortField === 'created_at'
                        ? (sortOrder === 'desc' ? 'arrow_downward' : 'arrow_upward')
                        : 'swap_vert'}
                    </span>
                  </th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[1] }} aria-label="Избранное" />
                  <th style={{ width: FAVORITES_COL_WIDTHS[2] }}>Заголовок</th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[3] }} className="sortable" onClick={() => handleSort('source')} title="Сортировка">
                    Источник
                    <span className={`material-icons sort-icon ${sortField === 'source' ? 'sort-icon-active' : 'sort-icon-inactive'}`}>
                      {sortField === 'source'
                        ? (sortOrder === 'desc' ? 'arrow_downward' : 'arrow_upward')
                        : 'swap_vert'}
                    </span>
                  </th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[4] }} className="sortable" onClick={() => handleSort('price')} title="Сортировка">
                    Цена
                    <span className={`material-icons sort-icon ${sortField === 'price' ? 'sort-icon-active' : 'sort-icon-inactive'}`}>
                      {sortField === 'price'
                        ? (sortOrder === 'desc' ? 'arrow_downward' : 'arrow_upward')
                        : 'swap_vert'}
                    </span>
                  </th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[5] }}>Адрес</th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[6] }}>Контакт</th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[7] }} className="sortable" onClick={() => handleSort('status')} title="Сортировка">
                    Статус
                    <span className={`material-icons sort-icon ${sortField === 'status' ? 'sort-icon-active' : 'sort-icon-inactive'}`}>
                      {sortField === 'status'
                        ? (sortOrder === 'desc' ? 'arrow_downward' : 'arrow_upward')
                        : 'swap_vert'}
                    </span>
                  </th>
                  <th style={{ width: FAVORITES_COL_WIDTHS[8] }}>Комментарий</th>
                </tr>
              </thead>
              <tbody>
                {listings.map((listing: FavoriteListing) => (
                  <FavoriteRow
                    key={listing.id}
                    colWidths={FAVORITES_COL_WIDTHS}
                    listing={listing}
                    onRemove={handleRemoveFavorite}
                    isRemoving={toggleMutation.isPending}
                    isEditingComment={editingCommentId === listing.id}
                    commentText={editingCommentId === listing.id ? commentText : (listing.comment || '')}
                    onCommentChange={setCommentText}
                    onStartEditComment={() => handleStartEditComment(listing)}
                    onSaveComment={() => handleSaveComment(listing.id)}
                    onCancelEdit={handleCancelEdit}
                    isSavingComment={commentMutation.isPending}
                    statuses={statuses}
                    onStatusChange={handleChangeListingStatus}
                    isUpdatingStatus={updateStatusMutation.isPending}
                  />
                ))}
              </tbody>
            </table>
          </div>

          {/* Пагинация */}
          <Pagination
            page={page}
            totalPages={totalPages}
            perPage={perPage}
            total={total}
            onPageChange={handlePageChange}
            onPerPageChange={handlePerPageChange}
          />
        </div>
      )}
    </div>
  );
}

// Компонент строки таблицы. Порядок ячеек строго совпадает с заголовками.
interface FavoriteRowProps {
  colWidths: readonly string[];
  listing: FavoriteListing;
  onRemove: (id: number, e: React.MouseEvent) => void;
  isRemoving: boolean;
  isEditingComment: boolean;
  commentText: string;
  onCommentChange: (text: string) => void;
  onStartEditComment: () => void;
  onSaveComment: () => void;
  onCancelEdit: () => void;
  isSavingComment: boolean;
  statuses: FavoriteStatus[];
  onStatusChange: (listingId: number, statusId: number | null) => void;
  isUpdatingStatus: boolean;
}

function FavoriteRow({
  colWidths,
  listing,
  onRemove,
  isRemoving,
  isEditingComment,
  commentText,
  onCommentChange,
  onStartEditComment,
  onSaveComment,
  onCancelEdit,
  isSavingComment,
  statuses,
  onStatusChange,
  isUpdatingStatus,
}: FavoriteRowProps) {
  const sourceCode = listing.source?.id ? sourceIdToCode[listing.source.id] : 'avito';
  const dateObj = listing.created_at ? new Date(listing.created_at) : null;
  const date = dateObj ? dateObj.toLocaleDateString('ru-RU') : '—';
  const time = dateObj ? dateObj.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '';
  const title = listing.title || 'Без названия';
  const category = listing.category?.name ? `(${listing.category.name})` : '';
  const metro = listing.metro?.[0];
  const metroColor = metro?.color ? `#${metro.color.replace('#', '')}` : undefined;

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      onSaveComment();
    } else if (e.key === 'Escape') {
      onCancelEdit();
    }
  };

  return (
    <tr>
      {/* 1. Дата */}
      <td style={{ width: colWidths[0] }} className="favorites-cell-date">
        <div className="date">{date}</div>
        <div className="time">{time}</div>
      </td>
      {/* 2. Сердечко (удалить из избранного) */}
      <td style={{ width: colWidths[1] }} className="favorites-cell-fav">
        <Tooltip content="Удалить из избранного" position="right">
          <div
            className="listing-action favorite active"
            onClick={(e) => onRemove(listing.id, e)}
          >
            <span className="material-icons">{isRemoving ? 'sync' : 'favorite'}</span>
          </div>
        </Tooltip>
      </td>
      {/* 3. Заголовок */}
      <td style={{ width: colWidths[2] }} className="favorites-cell-title">
        <div className="listing-preview">
          <div className="listing-info">
            <h4>
              {listing.url ? (
                <a href={listing.url} target="_blank" rel="noopener noreferrer">
                  {title}
                </a>
              ) : (
                title
              )}
            </h4>
          </div>
          <div className="listing-meta">{category}</div>
        </div>
      </td>
      {/* 4. Источник */}
      <td style={{ width: colWidths[3] }} className="favorites-cell-source">
        <SourceBadge source={sourceCode} />
      </td>
      {/* 5. Цена */}
      <td style={{ width: colWidths[4] }} className="favorites-cell-price">
        <strong className="price">{formatPrice(listing.price)}</strong>
      </td>
      {/* 6. Адрес */}
      <td style={{ width: colWidths[5] }} className="favorites-cell-address">
        <div className="address-content">
          <span className="address-text">{listing.address || '—'}</span>
          {metro && (
            <div className="metro-info">
              <span className="metro-line-dot" style={{ backgroundColor: metroColor }} />
              <span className="metro-name">{metro.name}</span>
            </div>
          )}
        </div>
      </td>
      {/* 7. Контакт (телефон) */}
      <td style={{ width: colWidths[6] }} className="favorites-cell-contact">
        {listing.phone ? (
          <span className="phone-text">{formatPhone(listing.phone)}</span>
        ) : (
          <span className="phone-empty">—</span>
        )}
      </td>
      {/* 8. Статус (селект) — фон селекта в цвете статуса */}
      <td style={{ width: colWidths[7] }} className="favorites-cell-status">
        <select
          className="form-control status-select"
          value={listing.status?.id || ''}
          onChange={(e) => onStatusChange(listing.id, e.target.value ? Number(e.target.value) : null)}
          disabled={isUpdatingStatus}
          style={
            listing.status
              ? { borderColor: listing.status.color }
              : undefined
          }
        >
          <option value="">—</option>
          {statuses.map((status) => (
            <option key={status.id} value={status.id}>
              {status.name}
            </option>
          ))}
        </select>
      </td>
      {/* 9. Комментарий */}
      <td style={{ width: colWidths[8] }} className="favorites-cell-comment">
        {isEditingComment ? (
          <div className="comment-edit">
            <textarea
              className="comment-input"
              value={commentText}
              onChange={(e) => onCommentChange(e.target.value)}
              onKeyDown={handleKeyDown}
              maxLength={250}
              placeholder="Введите комментарий..."
              autoFocus
            />
            <div className="comment-actions">
              <span className="comment-counter">{commentText.length}/250</span>
              {commentText.length > 0 && (
                <Tooltip content="Очистить" position="top">
                  <button
                    className="comment-btn clear"
                    onClick={() => onCommentChange('')}
                  >
                    <span className="material-icons">backspace</span>
                  </button>
                </Tooltip>
              )}
              <Tooltip content="Сохранить (Enter)" position="top">
                <button
                  className="comment-btn save"
                  onClick={onSaveComment}
                  disabled={isSavingComment}
                >
                  <span className="material-icons">{isSavingComment ? 'sync' : 'check'}</span>
                </button>
              </Tooltip>
              <Tooltip content="Отмена (Esc)" position="top">
                <button className="comment-btn cancel" onClick={onCancelEdit}>
                  <span className="material-icons">close</span>
                </button>
              </Tooltip>
            </div>
          </div>
        ) : (
          <div className="comment-display" onClick={onStartEditComment}>
            {listing.comment ? (
              <span className="comment-text">{listing.comment}</span>
            ) : (
              <span className="comment-placeholder">Добавить...</span>
            )}
            <span className="material-icons comment-edit-icon">edit</span>
          </div>
        )}
      </td>
    </tr>
  );
}
