import { useState, useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { adminUsersApi } from '../../services/api';
import type { AdminUser, AdminUsersParams } from '../../services/api';
import { useAuthStore } from '../../stores/authStore';
import { Tooltip } from '../../components/UI/Tooltip';
import { Pagination } from '../../components/UI/Pagination';
import './AdminUsers.css';

export function AdminUsers() {
  const navigate = useNavigate();
  const { user: currentUser } = useAuthStore();
  
  // Проверка прав админа
  if (currentUser?.role !== 'admin') {
    navigate('/');
    return null;
  }

  // Состояние фильтров
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState<'user' | 'admin' | ''>('');
  const [subscriptionFilter, setSubscriptionFilter] = useState<'true' | 'false' | ''>('');
  const [sortField, setSortField] = useState('created_at');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

  // Toast
  const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const showToast = (type: 'success' | 'error', message: string) => {
    setToast({ type, message });
    setTimeout(() => setToast(null), 5000);
  };

  // Запрос списка пользователей
  const { data: usersResponse, isLoading, refetch } = useQuery({
    queryKey: ['admin-users', page, perPage, appliedSearch, roleFilter, subscriptionFilter, sortField, sortDirection],
    queryFn: () => {
      const params: AdminUsersParams = {
        page,
        per_page: perPage,
        sort: sortField,
        order: sortDirection,
      };
      if (appliedSearch) params.search = appliedSearch;
      if (roleFilter) params.role = roleFilter;
      if (subscriptionFilter) params.has_subscription = subscriptionFilter === 'true';
      return adminUsersApi.getUsers(params);
    },
    staleTime: 0,
    gcTime: 0,
  });

  const users = usersResponse?.data?.data?.users || [];
  const pagination = usersResponse?.data?.data?.pagination || { page: 1, per_page: 20, total: 0, total_pages: 1 };

  // Применить поиск
  const handleSearch = () => {
    setPage(1);
    setAppliedSearch(search);
    // Принудительно обновляем данные
    refetch();
  };

  // Сброс фильтров
  const handleReset = () => {
    setSearch('');
    setAppliedSearch('');
    setRoleFilter('');
    setSubscriptionFilter('');
    setPage(1);
  };

  // Сортировка
  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortDirection(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('desc');
    }
  };

  // Имперсонация
  const handleImpersonate = useCallback(async (user: AdminUser) => {
    if (user.id === currentUser?.id) {
      showToast('error', 'Нельзя войти под своим аккаунтом');
      return;
    }

    try {
      const response = await adminUsersApi.impersonate(user.id);
      const { access_token, user: targetUser, impersonated_by } = response.data.data;
      
      // Сохраняем токен админа для возврата
      const adminToken = localStorage.getItem('access_token');
      localStorage.setItem('admin_token_backup', adminToken || '');
      localStorage.setItem('impersonated_by', String(impersonated_by));
      
      // Устанавливаем новый токен
      localStorage.setItem('access_token', access_token);
      
      showToast('success', `Вход выполнен под пользователем: ${targetUser.name}`);
      
      // Перезагружаем страницу для обновления состояния
      setTimeout(() => {
        window.location.href = '/';
      }, 1000);
      
    } catch (error: any) {
      showToast('error', error?.response?.data?.message || 'Ошибка при входе под пользователем');
    }
  }, [currentUser]);

  const renderSortIcon = (field: string) => {
    if (sortField !== field) return <span className="material-icons sort-icon">unfold_more</span>;
    return <span className="material-icons sort-icon active">{sortDirection === 'asc' ? 'expand_less' : 'expand_more'}</span>;
  };

  // Рендер tooltip для подписок
  const renderSubscriptionsTooltip = (user: AdminUser) => (
    <div className="admin-users-sub-tooltip-content">
      <div className="admin-users-sub-tooltip-title">Активные подписки</div>
      {user.subscriptions.map(sub => (
        <div key={sub.id} className="admin-users-sub-item">
          <span className="admin-users-sub-item-name">{sub.category} / {sub.location}</span>
          <span className="admin-users-sub-item-date">до {sub.end_date}</span>
        </div>
      ))}
    </div>
  );

  return (
    <div className="admin-users-page">
      <div className="admin-users-header">
        <h1>
          <span className="material-icons">people</span>
          Пользователи
        </h1>
        <span className="admin-users-count">{pagination.total} пользователей</span>
      </div>

      {/* Фильтры */}
      <div className="card">
        <div className="admin-users-filters">
          <div className="admin-users-filters-row">
            <div className="admin-users-filter-group search">
              <label>Поиск</label>
              <input
                type="text"
                value={search}
                onChange={e => setSearch(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleSearch()}
                placeholder="ID, имя или @username"
                className="admin-users-filter-input"
              />
            </div>
            
            <div className="admin-users-filter-group">
              <label>Роль</label>
              <select
                value={roleFilter}
                onChange={e => { setRoleFilter(e.target.value as any); setPage(1); }}
                className="admin-users-filter-input"
              >
                <option value="">Все</option>
                <option value="user">Пользователь</option>
                <option value="admin">Администратор</option>
              </select>
            </div>
            
            <div className="admin-users-filter-group">
              <label>Подписка</label>
              <select
                value={subscriptionFilter}
                onChange={e => { setSubscriptionFilter(e.target.value as any); setPage(1); }}
                className="admin-users-filter-input"
              >
                <option value="">Все</option>
                <option value="true">С активной</option>
                <option value="false">Без подписки</option>
              </select>
            </div>
            
            <div className="admin-users-filter-actions">
              <button className="admin-users-btn admin-users-btn-primary" onClick={handleSearch}>
                <span className="material-icons">search</span>
                Применить
              </button>
              <button className="admin-users-btn admin-users-btn-secondary" onClick={handleReset}>
                <span className="material-icons">clear</span>
                Сбросить
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Таблица */}
      <div className="card">
        <div className="admin-users-table-container">
          {isLoading ? (
            <div className="admin-users-loading">
              <span className="material-icons spinning">sync</span>
              Загрузка...
            </div>
          ) : users.length === 0 ? (
            <div className="admin-users-empty">
              <span className="material-icons">person_off</span>
              <p>Пользователи не найдены</p>
            </div>
          ) : (
            <table className="admin-users-table">
              <thead>
                <tr>
                  <th onClick={() => handleSort('id')} className="sortable">
                    ID {renderSortIcon('id')}
                  </th>
                  <th onClick={() => handleSort('name')} className="sortable">
                    Имя {renderSortIcon('name')}
                  </th>
                  <th>Telegram</th>
                  <th onClick={() => handleSort('role')} className="sortable">
                    Роль {renderSortIcon('role')}
                  </th>
                  <th>Подписки</th>
                  <th onClick={() => handleSort('created_at')} className="sortable">
                    Регистрация {renderSortIcon('created_at')}
                  </th>
                  <th>Действия</th>
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <tr key={user.id}>
                    <td className="admin-users-id">#{user.id}</td>
                    <td className="admin-users-name">{user.name}</td>
                    <td className="admin-users-telegram">
                      {user.telegram_username ? (
                        <a href={`https://t.me/${user.telegram_username}`} target="_blank" rel="noopener noreferrer">
                          @{user.telegram_username}
                        </a>
                      ) : (
                        <span className="muted">—</span>
                      )}
                    </td>
                    <td>
                      <span className={`admin-users-role ${user.role}`}>
                        {user.role === 'admin' ? 'Админ' : 'Пользователь'}
                      </span>
                    </td>
                    <td>
                      {user.has_active_subscription ? (
                        <Tooltip 
                          content={renderSubscriptionsTooltip(user)}
                          position="bottom"
                        >
                          <span className="admin-users-sub-badge active">
                            {user.active_subscriptions_count} активных
                          </span>
                        </Tooltip>
                      ) : (
                        <span className="admin-users-sub-badge inactive">Нет</span>
                      )}
                    </td>
                    <td className="admin-users-date">{user.created_at}</td>
                    <td>
                      <Tooltip content="Войти под этим пользователем" position="left">
                        <button
                          className="admin-users-action-btn"
                          onClick={() => handleImpersonate(user)}
                          disabled={user.id === currentUser?.id}
                        >
                          <span className="material-icons">login</span>
                          Войти как
                        </button>
                      </Tooltip>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {/* Пагинация */}
      <Pagination
        page={page}
        totalPages={pagination.total_pages || 1}
        perPage={perPage}
        total={pagination.total}
        onPageChange={setPage}
        onPerPageChange={(newPerPage) => { setPerPage(newPerPage); setPage(1); }}
      />

      {/* Toast */}
      {toast && (
        <div className={`admin-users-toast ${toast.type}`}>
          <span className="material-icons">
            {toast.type === 'success' ? 'check_circle' : 'error'}
          </span>
          <span className="admin-users-toast-message">{toast.message}</span>
          <button className="admin-users-toast-close" onClick={() => setToast(null)}>
            <span className="material-icons">close</span>
          </button>
        </div>
      )}
    </div>
  );
}
