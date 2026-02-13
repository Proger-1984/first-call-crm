import axios, { AxiosError } from 'axios';
import type { 
  User, 
  Listing, 
  ListingsStats, 
  Tariff, 
  Subscription,
  FilterParams,
  SortParams,
  PaginationParams,
  ApiResponse,
  PaginatedResponse,
  TelegramUser,
  AuthResponse,
  RefreshTokenResponse,
  UserSettingsResponse,
  UserSubscriptionFull,
  TariffInfoResponse,
  ListingStatusCode
} from '../types';

// Флаг для предотвращения множественных refresh запросов
let isRefreshing = false;
let refreshSubscribers: ((token: string) => void)[] = [];

// Подписка на обновление токена
const subscribeTokenRefresh = (callback: (token: string) => void) => {
  refreshSubscribers.push(callback);
};

// Уведомление всех подписчиков о новом токене
const onTokenRefreshed = (token: string) => {
  refreshSubscribers.forEach(callback => callback(token));
  refreshSubscribers = [];
};

// Создаём экземпляр axios с базовыми настройками
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true, // Всегда отправляем cookies (для refresh token)
});

// Интерцептор для добавления токена к запросам
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Интерцептор для обработки ошибок и обновления токена
api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const originalRequest = error.config as any;
    
    // Если это не 401 или это запрос на auth endpoints - просто возвращаем ошибку
    if (
      error.response?.status !== 401 || 
      originalRequest._retry ||
      originalRequest.url?.includes('/auth/refresh') ||
      originalRequest.url?.includes('/auth/telegram') ||
      originalRequest.url?.includes('/auth/login')
    ) {
      return Promise.reject(error);
    }
    
    // Помечаем запрос как повторный
    originalRequest._retry = true;
    
    // Если уже идёт refresh - ждём его завершения
    if (isRefreshing) {
      return new Promise((resolve, reject) => {
        subscribeTokenRefresh((token: string) => {
          originalRequest.headers.Authorization = `Bearer ${token}`;
          resolve(api(originalRequest));
        });
      });
    }
    
    isRefreshing = true;
    
    try {
      // Пробуем обновить токен (refresh token в cookie)
      const response = await axios.get<RefreshTokenResponse>(
        `${api.defaults.baseURL}/auth/refresh`,
        { withCredentials: true }
      );
      
      // Поддержка и плоского ответа { access_token }, и обёртки { data: { access_token } }
      const access_token =
        response.data?.access_token ??
        (response.data as any)?.data?.access_token;

      if (!access_token) {
        throw new Error('Refresh response missing access_token');
      }
      
      // Сохраняем новый токен
      localStorage.setItem('access_token', access_token);
      
      // Обновляем заголовок для ТЕКУЩЕГО запроса
      originalRequest.headers.Authorization = `Bearer ${access_token}`;
      
      // Уведомляем всех ожидающих (они тоже получат новый токен)
      onTokenRefreshed(access_token);
      
      // Сбрасываем флаг ПЕРЕД повторным запросом
      isRefreshing = false;
      
      // Повторяем оригинальный запрос с новым токеном
      return api(originalRequest);
      
    } catch (refreshError: any) {
      // Refresh не удался - полностью очищаем состояние авторизации
      localStorage.removeItem('access_token');
      localStorage.removeItem('auth-storage'); // zustand persist, чтобы после редиректа не было «призрачной» авторизации
      
      isRefreshing = false;
      
      // Редирект только если мы не на странице логина
      if (!window.location.pathname.includes('/login')) {
        window.location.href = '/login';
      }
      
      return Promise.reject(refreshError);
    }
  }
);

// === AUTH ===
export const authApi = {
  /**
   * Авторизация через Telegram
   * При успехе: access_token в теле, refresh_token в cookie
   */
  authenticateWithTelegram: (telegramData: TelegramUser) =>
    api.post<AuthResponse>('/auth/telegram', telegramData),
  
  /**
   * Обновление access токена
   * Refresh token берётся из HttpOnly cookie
   * 
   * Возможные ошибки:
   * - 401 token_not_found: нет refresh cookie
   * - 401 token_expired: refresh токен истёк
   * - 401 invalid_token: невалидный токен
   * - 401 invalid_token_type: неверный тип токена
   */
  refreshToken: () =>
    api.get<RefreshTokenResponse>('/auth/refresh'),
  
  /**
   * Выход из системы (GET по документации)
   * Инвалидирует текущий токен
   */
  logout: () =>
    api.get<ApiResponse<{ message: string }>>('/auth/logout'),
  
  /**
   * Выход из всех устройств
   * Инвалидирует все токены пользователя
   */
  logoutAll: () =>
    api.get<ApiResponse<{ message: string }>>('/auth/logout-all'),
  
  /**
   * Получить информацию о текущем пользователе
   * Endpoint: /api/v1/me/info
   * Возвращает: { code, status, data: { user: {...} } }
   */
  me: () =>
    api.get<ApiResponse<{ user: User }>>('/me/info'),
  
  /**
   * Получить имя Telegram бота для виджета
   */
  getTelegramBotUsername: () =>
    api.get<string>('/config/telegram-bot-username', {
      transformResponse: (data) => data, // Возвращает plain text
    }),
};

// === LISTINGS ===
export const listingsApi = {
  /**
   * Получить список объявлений с фильтрацией, сортировкой и пагинацией
   * POST /api/v1/listings
   */
  getAll: (params?: FilterParams & SortParams & PaginationParams) =>
    api.post<ApiResponse<PaginatedResponse<Listing>>>('/listings', params),
  
  /**
   * Получить одно объявление по ID
   * GET /api/v1/listings/{id}
   */
  getById: (id: number) =>
    api.get<ApiResponse<{ listing: Listing }>>(`/listings/${id}`),
  
  /**
   * Обновить статус объявления
   * PATCH /api/v1/listings/{id}/status
   */
  updateStatus: (id: number, status: ListingStatusCode) =>
    api.patch<ApiResponse<{ listing: Listing }>>(`/listings/${id}/status`, { status }),
  
  /**
   * Получить статистику по объявлениям
   * GET /api/v1/listings/stats
   */
  getStats: () =>
    api.get<ApiResponse<ListingsStats>>('/listings/stats'),
};

// === USER ===
export const userApi = {
  getProfile: () =>
    api.get<ApiResponse<User>>('/user/profile'),
  
  updateProfile: (data: Partial<User>) =>
    api.put<ApiResponse<User>>('/user/profile', data),
  
  changePassword: (data: { current_password: string; new_password: string }) =>
    api.put<ApiResponse<void>>('/user/password', data),
  
  /**
   * Обновить статус автозвонка
   * PUT /api/v1/me/auto-call
   * @param auto_call - true = автозвонок включён, false = выключен
   */
  setAutoCallStatus: (auto_call: boolean) =>
    api.put<ApiResponse<{ auto_call: boolean }>>('/me/auto-call', { auto_call }),
};

// === PROFILE ===
export interface DownloadInfo {
  android: {
    available: boolean;
    size: number | null;
    size_formatted: string | null;
    download_url: string;
  };
  ios: {
    available: boolean;
    size: number | null;
    download_url: string | null;
  };
}

export const profileApi = {
  /**
   * Получить логин для приложения
   * GET /api/v1/me/app-login
   */
  getAppLogin: () =>
    api.get<ApiResponse<{ login: string }>>('/me/app-login'),
  
  /**
   * Сгенерировать новый пароль для приложения
   * POST /api/v1/me/generate-password
   * Новый пароль отправляется в Telegram
   */
  generatePassword: () =>
    api.post<ApiResponse<{ message: string }>>('/me/generate-password'),
  
  /**
   * Получить информацию о доступных приложениях для скачивания
   * GET /api/v1/me/download-info
   */
  getDownloadInfo: () =>
    api.get<ApiResponse<DownloadInfo>>('/me/download-info'),
  
  /**
   * Скачать приложение для Android
   * GET /api/v1/me/download/android
   * Возвращает файл APK как blob
   */
  downloadAndroidApp: () =>
    api.get('/me/download/android', { responseType: 'blob' }),
  
  /**
   * Перепривязка Telegram аккаунта
   * POST /api/v1/auth/telegram/rebind
   */
  rebindTelegram: (telegramData: TelegramUser) =>
    api.post<ApiResponse<{ message: string }>>('/auth/telegram/rebind', telegramData),
};

// === SUBSCRIPTIONS ===
export const subscriptionsApi = {
  /**
   * Получить активные подписки (для настроек локаций)
   */
  getActive: () =>
    api.get<ApiResponse<Subscription[]>>('/subscriptions'),
  
  /**
   * Получить все подписки пользователя (для профиля)
   * Включает активные, истёкшие и отменённые
   */
  getAll: () =>
    api.get<ApiResponse<{ subscriptions: UserSubscriptionFull[] }>>('/subscriptions/all'),
  
  create: (data: { tariff_id: number; location_id: number; category_id: number }) =>
    api.post<ApiResponse<Subscription>>('/subscriptions', data),
  
  cancel: (id: number) =>
    api.delete<ApiResponse<void>>(`/subscriptions/${id}`),
  
  /**
   * Создать заявку на продление подписки
   * POST /api/v1/subscriptions/extend-request
   * 
   * После успешного запроса:
   * - Администратору отправляется уведомление в Telegram
   * - Пользователю отправляется уведомление с реквизитами для оплаты
   * 
   * @param subscriptionId - ID подписки для продления
   * @param tariffId - ID тарифа (обычно текущий тариф подписки)
   * @param notes - Комментарий к заявке (опционально)
   */
  requestExtend: (subscriptionId: number, tariffId: number, notes?: string) =>
    api.post<ApiResponse<{ subscription_id: number; message: string }>>('/subscriptions/extend-request', {
      subscription_id: subscriptionId,
      tariff_id: tariffId,
      notes: notes || 'Хочу продлить подписку',
    }),
};

// === SETTINGS ===
export const settingsApi = {
  /**
   * Получить настройки пользователя
   * GET /api/v1/me/settings
   * Возвращает: settings, sources, active_subscriptions
   */
  getSettings: () =>
    api.get<ApiResponse<UserSettingsResponse>>('/me/settings'),
  
  /**
   * Обновить настройки пользователя
   * PUT /api/v1/me/settings
   * Принимает: { settings, sources, active_subscriptions }
   */
  updateSettings: (data: Partial<UserSettingsResponse>) =>
    api.put<ApiResponse<UserSettingsResponse>>('/me/settings', data),
  
  /**
   * Обновить отдельную настройку (log_events, auto_call, etc.)
   * Хелпер для удобства
   */
  updateSetting: (key: string, value: boolean, currentSettings: UserSettingsResponse) => {
    const updatedSettings = {
      settings: { ...currentSettings.settings, [key]: value },
      sources: currentSettings.sources,
      active_subscriptions: currentSettings.active_subscriptions,
    };
    return api.put<ApiResponse<UserSettingsResponse>>('/me/settings', updatedSettings);
  },
  
  /**
   * Обновить источник
   */
  updateSource: (sourceId: number, enabled: boolean, currentSettings: UserSettingsResponse) => {
    const updatedSources = currentSettings.sources.map(s => 
      s.id === sourceId ? { ...s, enabled } : s
    );
    const updatedSettings = {
      settings: currentSettings.settings,
      sources: updatedSources,
      active_subscriptions: currentSettings.active_subscriptions,
    };
    return api.put<ApiResponse<UserSettingsResponse>>('/me/settings', updatedSettings);
  },
  
  /**
   * Обновить статус подписки (enabled/disabled)
   */
  updateSubscriptionStatus: (subscriptionId: number, enabled: boolean, currentSettings: UserSettingsResponse) => {
    const updatedSubscriptions = currentSettings.active_subscriptions.map(s => 
      s.id === subscriptionId ? { ...s, enabled } : s
    );
    const updatedSettings = {
      settings: currentSettings.settings,
      sources: currentSettings.sources,
      active_subscriptions: updatedSubscriptions,
    };
    return api.put<ApiResponse<UserSettingsResponse>>('/me/settings', updatedSettings);
  },
};

// === TARIFFS ===
export const tariffsApi = {
  /**
   * Получить всю информацию о тарифах
   * GET /api/v1/catalog/tariff-info
   * Возвращает: categories, locations, tariffs, tariff_prices
   */
  getTariffInfo: () =>
    api.get<ApiResponse<TariffInfoResponse>>('/catalog/tariff-info'),
  
  getAll: () =>
    api.get<ApiResponse<Tariff[]>>('/tariffs'),
  
  getById: (id: number) =>
    api.get<ApiResponse<Tariff>>(`/tariffs/${id}`),
};

// === FILTERS ===
export interface FilterOption {
  id: number;
  name: string;
  code?: string;
  line?: string;
  color?: string;
}

export interface FiltersData {
  categories: FilterOption[];
  locations: FilterOption[];
  metro: FilterOption[];
  rooms: FilterOption[];
  sources: FilterOption[];
  call_statuses: FilterOption[];
  meta: {
    is_admin: boolean;
    selected_category_id: number | null;
    selected_location_ids: number[] | null;
  };
}

export const filtersApi = {
  /**
   * Получить данные для фильтров
   * GET /api/v1/filters
   * 
   * Возвращает категории, локации, метро, комнаты, источники, статусы
   * с учётом подписок пользователя
   * 
   * @param categoryId - выбранная категория (для фильтрации локаций)
   * @param locationIds - выбранные локации (для фильтрации метро), может быть массивом
   */
  getFilters: (categoryId?: number, locationIds?: number[]) => {
    const params = new URLSearchParams();
    if (categoryId) params.append('category_id', categoryId.toString());
    
    // Поддержка массива локаций
    if (locationIds && locationIds.length > 0) {
      locationIds.forEach(id => params.append('location_id[]', id.toString()));
    }
    
    const queryString = params.toString();
    return api.get<ApiResponse<FiltersData>>(`/filters${queryString ? `?${queryString}` : ''}`);
  },
};

// === LOCATION POLYGONS ===
export interface LocationPolygon {
  id: number;
  name: string;
  polygon_coordinates: [number, number][]; // [lat, lng][]
  center_lat: number;
  center_lng: number;
  bounds: {
    north: number;
    south: number;
    east: number;
    west: number;
  };
  created_at: string;
}

export interface CreatePolygonData {
  subscription_id: number;
  name: string;
  polygon_coordinates: [number, number][];
}

export interface UpdatePolygonData {
  subscription_id?: number;
  name?: string;
  polygon_coordinates?: [number, number][];
}

export const polygonsApi = {
  /**
   * Получить полигоны по подписке
   * GET /api/v1/location-polygons/subscription/{subscription_id}
   */
  getBySubscription: (subscriptionId: number) =>
    api.get<ApiResponse<{ location_polygons: LocationPolygon[] }>>(`/location-polygons/subscription/${subscriptionId}`),
  
  /**
   * Создать новый полигон
   * POST /api/v1/location-polygons
   */
  create: (data: CreatePolygonData) =>
    api.post<ApiResponse<{ message: string }>>('/location-polygons', data),
  
  /**
   * Обновить полигон
   * PUT /api/v1/location-polygons/{id}
   */
  update: (id: number, data: UpdatePolygonData) =>
    api.put<ApiResponse<{ message: string }>>(`/location-polygons/${id}`, data),
  
  /**
   * Удалить полигон
   * DELETE /api/v1/location-polygons/{id}
   */
  delete: (id: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/location-polygons/${id}`),
};

// === FAVORITES ===
export interface FavoriteStatus {
  id: number;
  name: string;
  color: string;
  sort_order?: number;
  favorites_count?: number;
}

export interface FavoriteListing {
  id: number;
  external_id: string;
  title: string;
  description?: string;
  price: number | null;
  square_meters: number | null;
  floor: number | null;
  floors_total: number | null;
  phone: string | null;
  address: string;
  city: string | null;
  street: string | null;
  house: string | null;
  url: string | null;
  is_paid: boolean;
  status: FavoriteStatus | null;
  created_at: string;
  favorited_at: string;
  is_favorite: boolean;
  comment: string | null;
  source?: { id: number; name: string };
  category?: { id: number; name: string };
  listing_status?: { id: number; name: string };
  location?: { id: number; name: string };
  room?: { id: number; name: string; code: string };
  metro?: Array<{
    id: number;
    name: string;
    line: string;
    color: string;
    travel_time_min: number | null;
    travel_type: string;
  }>;
}

export interface FavoritesResponse {
  listings: FavoriteListing[];
  pagination: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface ToggleFavoriteResponse {
  listing_id: number;
  is_favorite: boolean;
  message: string;
}

export interface FavoritesFilters {
  page?: number;
  perPage?: number;
  sortBy?: 'created_at' | 'source' | 'price' | 'status';
  order?: 'asc' | 'desc';
  dateFrom?: string;
  dateTo?: string;
  comment?: string;
  statusId?: number | 'none';
}

export const favoritesApi = {
  /**
   * Получить список избранных объявлений
   * GET /api/v1/favorites
   */
  getList: (filters: FavoritesFilters = {}) => {
    const params = new URLSearchParams();
    params.append('page', String(filters.page || 1));
    params.append('per_page', String(filters.perPage || 20));
    if (filters.sortBy) params.append('sort_by', filters.sortBy);
    if (filters.order) params.append('order', filters.order);
    if (filters.dateFrom) params.append('date_from', filters.dateFrom);
    if (filters.dateTo) params.append('date_to', filters.dateTo);
    if (filters.comment) params.append('comment', filters.comment);
    if (filters.statusId !== undefined) params.append('status_id', String(filters.statusId));
    return api.get<ApiResponse<FavoritesResponse>>(`/favorites?${params.toString()}`);
  },
  
  /**
   * Добавить/удалить из избранного (toggle)
   * POST /api/v1/favorites/toggle
   */
  toggle: (listingId: number) =>
    api.post<ApiResponse<ToggleFavoriteResponse>>('/favorites/toggle', { listing_id: listingId }),
  
  /**
   * Проверить, в избранном ли объявление
   * GET /api/v1/favorites/check/{id}
   */
  check: (listingId: number) =>
    api.get<ApiResponse<{ listing_id: number; is_favorite: boolean }>>(`/favorites/check/${listingId}`),
  
  /**
   * Получить количество избранных
   * GET /api/v1/favorites/count
   */
  getCount: () =>
    api.get<ApiResponse<{ count: number }>>('/favorites/count'),
  
  /**
   * Обновить комментарий к избранному
   * PUT /api/v1/favorites/comment
   */
  updateComment: (listingId: number, comment: string | null) =>
    api.put<ApiResponse<{ listing_id: number; comment: string | null; message: string }>>('/favorites/comment', { 
      listing_id: listingId, 
      comment 
    }),
  
  /**
   * Обновить статус избранного
   * PUT /api/v1/favorites/status
   */
  updateStatus: (listingId: number, statusId: number | null) =>
    api.put<ApiResponse<{ listing_id: number; status: FavoriteStatus | null; message: string }>>('/favorites/status', { 
      listing_id: listingId, 
      status_id: statusId 
    }),
  
  // === Управление статусами ===
  
  /**
   * Получить все статусы пользователя
   * GET /api/v1/favorites/statuses
   */
  getStatuses: () =>
    api.get<ApiResponse<{ statuses: FavoriteStatus[] }>>('/favorites/statuses'),
  
  /**
   * Создать новый статус
   * POST /api/v1/favorites/statuses
   */
  createStatus: (name: string, color: string = '#808080') =>
    api.post<ApiResponse<{ status: FavoriteStatus; message: string }>>('/favorites/statuses', { name, color }),
  
  /**
   * Обновить статус
   * PUT /api/v1/favorites/statuses/{id}
   */
  updateStatusInfo: (statusId: number, data: { name?: string; color?: string }) =>
    api.put<ApiResponse<{ status: FavoriteStatus; message: string }>>(`/favorites/statuses/${statusId}`, data),
  
  /**
   * Удалить статус
   * DELETE /api/v1/favorites/statuses/{id}
   */
  deleteStatus: (statusId: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/favorites/statuses/${statusId}`),
  
  /**
   * Изменить порядок статусов
   * PUT /api/v1/favorites/statuses/reorder
   */
  reorderStatuses: (order: number[]) =>
    api.put<ApiResponse<{ message: string }>>('/favorites/statuses/reorder', { order }),
};

// ============================================================================
// Photo Tasks API (обработка фото - удаление водяных знаков)
// ============================================================================

export interface PhotoTask {
  id: number;
  listing_id: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  photos_count: number;
  error_message: string | null;
  created_at: string;
  updated_at?: string;
}

export const photoTasksApi = {
  /**
   * Создать задачу на обработку фото
   * POST /api/v1/photo-tasks
   */
  create: (listingId: number) =>
    api.post<ApiResponse<PhotoTask>>('/photo-tasks', { listing_id: listingId }),

  /**
   * Получить URL для скачивания архива
   * GET /api/v1/photo-tasks/{id}/download
   */
  getDownloadUrl: (taskId: number) =>
    `${api.defaults.baseURL}/photo-tasks/${taskId}/download`,
};

// ============================================================================
// Billing API (биллинг - подписки пользователя)
// ============================================================================

export interface BillingSubscription {
  id: number;
  created_at: string;
  tariff_info: string;
  status: 'active' | 'pending' | 'expired' | 'cancelled' | 'extend_pending';
  days_left: string;
  start_date: string | null;
  end_date: string | null;
}

export interface BillingMeta {
  total: number;
  per_page: number;
  current_page: number;
  total_pages: number;
  from: number;
  to: number;
}

export interface BillingFilters {
  page?: number;
  per_page?: number;
  filters?: {
    subscription_id?: number;
    status?: string[];
    created_at?: {
      from?: string;
      to?: string;
    };
  };
  sorting?: Record<string, 'asc' | 'desc'>;
}

export const billingApi = {
  /**
   * Получить подписки текущего пользователя
   * POST /api/v1/billing/user-subscriptions
   */
  getUserSubscriptions: (filters: BillingFilters = {}) =>
    api.post<ApiResponse<{ meta: BillingMeta; data: BillingSubscription[] }>>('/billing/user-subscriptions', filters),
};

// ============================================================================
// Admin Billing API (биллинг - административная панель)
// ============================================================================

export interface AdminSubscription {
  id: number;
  created_at: string;
  tariff_info: string;
  telegram: string;
  status: string;
  payment_method: string | null;
  admin_notes: string | null;
  days_left: string;
  start_date: string | null;
  end_date: string | null;
  user_id: number;
  price_paid: number | null;
}

export interface SubscriptionHistoryItem {
  id: number;
  subscription_id: number;
  user_id: number;
  tariff_info: string;
  old_status: string;
  new_status: string;
  price: number | null;
  action_date: string;
  notes: string | null;
}

export interface AdminBillingFilters {
  page?: number;
  per_page?: number;
  filters?: {
    subscription_id?: number;
    user_id?: number;
    tariff_id?: number;
    status?: string[];
    days_left_min?: number;
    days_left_max?: number;
    created_at?: {
      from?: string;
      to?: string;
    };
    action?: string[];
    action_date?: {
      from?: string;
      to?: string;
    };
  };
  sorting?: Record<string, 'asc' | 'desc'>;
}

export const adminBillingApi = {
  /**
   * Получить текущие подписки всех пользователей (админ)
   * POST /api/v1/billing/admin/current-subscriptions
   */
  getCurrentSubscriptions: (filters: AdminBillingFilters = {}) =>
    api.post<ApiResponse<{ meta: BillingMeta; data: AdminSubscription[] }>>('/billing/admin/current-subscriptions', filters),

  /**
   * Получить историю подписок (админ)
   * POST /api/v1/billing/admin/subscription-history
   */
  getSubscriptionHistory: (filters: AdminBillingFilters = {}) =>
    api.post<ApiResponse<{ meta: BillingMeta; data: SubscriptionHistoryItem[] }>>('/billing/admin/subscription-history', filters),

  /**
   * Активировать подписку (админ)
   * POST /api/v1/admin/subscriptions/activate
   * 
   * @param subscriptionId - ID подписки для активации
   * @param paymentMethod - Метод оплаты (обязательно)
   * @param notes - Примечания администратора (опционально)
   * @param durationHours - Продолжительность в часах (опционально, по умолчанию из тарифа)
   */
  activateSubscription: (
    subscriptionId: number,
    paymentMethod: string,
    notes?: string,
    durationHours?: number
  ) =>
    api.post<ApiResponse<{ activated_subscription: { id: number; start_date: string; end_date: string } }>>('/admin/subscriptions/activate', {
      subscription_id: subscriptionId,
      payment_method: paymentMethod,
      notes,
      duration_hours: durationHours,
    }),

  /**
   * Продлить подписку (админ)
   * POST /api/v1/admin/subscriptions/extend
   * 
   * @param subscriptionId - ID подписки для продления
   * @param paymentMethod - Метод оплаты (обязательно)
   * @param price - Цена продления (опционально)
   * @param notes - Примечания администратора (опционально)
   * @param durationHours - Продолжительность продления в часах (опционально)
   */
  extendSubscription: (
    subscriptionId: number,
    paymentMethod: string,
    price?: number,
    notes?: string,
    durationHours?: number
  ) =>
    api.post<ApiResponse<{ message: string }>>('/admin/subscriptions/extend', {
      subscription_id: subscriptionId,
      payment_method: paymentMethod,
      price,
      notes,
      duration_hours: durationHours,
    }),

  /**
   * Отменить подписку (админ)
   * POST /api/v1/admin/subscriptions/cancel
   * 
   * @param subscriptionId - ID подписки для отмены
   * @param reason - Причина отмены (опционально)
   */
  cancelSubscription: (subscriptionId: number, reason?: string) =>
    api.post<ApiResponse<{ message: string }>>('/admin/subscriptions/cancel', {
      subscription_id: subscriptionId,
      reason,
    }),

  /**
   * Создать подписку для пользователя (админ)
   * POST /api/v1/admin/subscriptions/create
   * 
   * Используется для миграции пользователей со старой CRM
   * 
   * @param userId - ID пользователя
   * @param tariffId - ID тарифа
   * @param categoryId - ID категории
   * @param locationId - ID локации
   * @param paymentMethod - Метод оплаты
   * @param options - Дополнительные параметры
   */
  createSubscription: (
    userId: number,
    tariffId: number,
    categoryId: number,
    locationId: number,
    paymentMethod: string,
    options?: {
      notes?: string;
      durationHours?: number;
      price?: number;
      autoActivate?: boolean;
    }
  ) =>
    api.post<ApiResponse<{ 
      subscription_id: number; 
      status: string; 
      start_date: string | null; 
      end_date: string | null;
    }>>('/admin/subscriptions/create', {
      user_id: userId,
      tariff_id: tariffId,
      category_id: categoryId,
      location_id: locationId,
      payment_method: paymentMethod,
      notes: options?.notes,
      duration_hours: options?.durationHours,
      price: options?.price,
      auto_activate: options?.autoActivate ?? true,
    }),
};

// === ANALYTICS API (только для админов) ===

export interface AnalyticsChartDataPoint {
  date: string;
  label: string;
  revenue: number;
  users: number;
  subscriptions: number;
}

export interface AnalyticsChartsResponse {
  period: {
    from: string;
    to: string;
    group_by: 'day' | 'month';
  };
  chart_data: AnalyticsChartDataPoint[];
  totals: {
    revenue: number;
    users: number;
    subscriptions: number;
  };
}

export interface AnalyticsSummaryResponse {
  revenue: {
    today: number;
    week: number;
    month: number;
  };
  users: {
    today: number;
    week: number;
    month: number;
    total: number;
  };
  subscriptions: {
    today: number;
    week: number;
    month: number;
    active: number;
  };
}

export interface AnalyticsChartsParams {
  period?: 'week' | 'month' | 'quarter' | 'year';
  date_from?: string;
  date_to?: string;
}

export const analyticsApi = {
  /**
   * Получить данные для графиков
   * POST /api/v1/admin/analytics/charts
   */
  getChartsData: (params: AnalyticsChartsParams = {}) =>
    api.post<ApiResponse<AnalyticsChartsResponse>>('/admin/analytics/charts', params),

  /**
   * Получить сводную статистику
   * GET /api/v1/admin/analytics/summary
   */
  getSummary: () =>
    api.get<ApiResponse<AnalyticsSummaryResponse>>('/admin/analytics/summary'),
};

// === ADMIN USERS API ===

export interface AdminUser {
  id: number;
  name: string;
  telegram_username: string | null;
  telegram_id: string | null;
  role: 'user' | 'admin';
  created_at: string;
  has_active_subscription: boolean;
  active_subscriptions_count: number;
  subscriptions: Array<{
    id: number;
    category: string;
    location: string;
    status: string;
    end_date: string;
  }>;
}

export interface AdminUsersResponse {
  users: AdminUser[];
  pagination: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface AdminUsersParams {
  page?: number;
  per_page?: number;
  search?: string;
  role?: 'user' | 'admin';
  has_subscription?: boolean;
  sort?: string;
  order?: 'asc' | 'desc';
}

export interface ImpersonateResponse {
  access_token: string;
  user: {
    id: number;
    name: string;
    telegram_username: string | null;
    role: string;
  };
  impersonated_by: number;
}

export interface ExitImpersonateResponse {
  access_token: string;
  user: {
    id: number;
    name: string;
    telegram_username: string | null;
    role: string;
  };
}

export const adminUsersApi = {
  /**
   * Получить список пользователей
   * POST /api/v1/admin/users
   */
  getUsers: (params: AdminUsersParams = {}) =>
    api.post<ApiResponse<AdminUsersResponse>>('/admin/users', params),

  /**
   * Войти под пользователем (имперсонация)
   * POST /api/v1/admin/users/impersonate
   */
  impersonate: (userId: number) =>
    api.post<ApiResponse<ImpersonateResponse>>('/admin/users/impersonate', { user_id: userId }),

  /**
   * Выйти из имперсонации (вернуться к админу)
   * POST /api/v1/admin/users/exit-impersonate
   */
  exitImpersonate: (adminId: number) =>
    api.post<ApiResponse<ExitImpersonateResponse>>('/admin/users/exit-impersonate', { admin_id: adminId }),
};

// ============================================================================
// Clients CRM API (клиенты, воронка, подборки)
// ============================================================================

import type {
  Client,
  ClientFilters,
  ClientStats,
  PipelineColumn,
  PipelineStage,
  ClientListingStatus,
} from '../types/client';

export const clientsApi = {
  /**
   * Получить список клиентов с фильтрами
   * GET /api/v1/clients
   */
  getList: (filters: ClientFilters = {}) => {
    const params = new URLSearchParams();
    if (filters.page) params.append('page', String(filters.page));
    if (filters.per_page) params.append('per_page', String(filters.per_page));
    if (filters.sort) params.append('sort', filters.sort);
    if (filters.order) params.append('order', filters.order);
    if (filters.search) params.append('search', filters.search);
    if (filters.client_type) params.append('client_type', filters.client_type);
    if (filters.stage_id) params.append('stage_id', String(filters.stage_id));
    if (filters.is_archived !== undefined) params.append('is_archived', String(filters.is_archived));
    if (filters.source_type) params.append('source_type', filters.source_type);
    const queryString = params.toString();
    return api.get<ApiResponse<{
      clients: Client[];
      pagination: { page: number; per_page: number; total: number; total_pages: number };
    }>>(`/clients${queryString ? `?${queryString}` : ''}`);
  },

  /**
   * Получить карточку клиента
   * GET /api/v1/clients/{id}
   */
  getById: (id: number) =>
    api.get<ApiResponse<{ client: Client }>>(`/clients/${id}`),

  /**
   * Создать клиента
   * POST /api/v1/clients
   */
  create: (data: Partial<Client>) =>
    api.post<ApiResponse<{ client: Client; message: string }>>('/clients', data),

  /**
   * Обновить клиента
   * PUT /api/v1/clients/{id}
   */
  update: (id: number, data: Partial<Client>) =>
    api.put<ApiResponse<{ client: Client; message: string }>>(`/clients/${id}`, data),

  /**
   * Архивировать/разархивировать клиента
   * PATCH /api/v1/clients/{id}/archive
   */
  archive: (id: number, isArchived: boolean) =>
    api.patch<ApiResponse<{ client: Client; message: string }>>(`/clients/${id}/archive`, { is_archived: isArchived }),

  /**
   * Удалить клиента
   * DELETE /api/v1/clients/{id}
   */
  delete: (id: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/clients/${id}`),

  /**
   * Переместить клиента на другую стадию
   * PATCH /api/v1/clients/{id}/stage
   */
  moveStage: (id: number, stageId: number) =>
    api.patch<ApiResponse<{ client: Client; message: string }>>(`/clients/${id}/stage`, { stage_id: stageId }),

  /**
   * Получить kanban-доску (стадии + клиенты)
   * GET /api/v1/clients/pipeline
   */
  getPipeline: () =>
    api.get<ApiResponse<{ pipeline: PipelineColumn[] }>>('/clients/pipeline'),

  /**
   * Получить статистику по клиентам
   * GET /api/v1/clients/stats
   */
  getStats: () =>
    api.get<ApiResponse<ClientStats>>('/clients/stats'),

  // === Подборки (привязка объявлений) ===

  /**
   * Добавить объявление в подборку клиента
   * POST /api/v1/clients/{id}/listings
   */
  addListing: (clientId: number, listingId: number, comment?: string) =>
    api.post<ApiResponse<{ client_listing: any; message: string }>>(`/clients/${clientId}/listings`, {
      listing_id: listingId,
      comment,
    }),

  /**
   * Удалить объявление из подборки
   * DELETE /api/v1/clients/{id}/listings/{listing_id}
   */
  removeListing: (clientId: number, listingId: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/clients/${clientId}/listings/${listingId}`),

  /**
   * Обновить статус объявления в подборке
   * PATCH /api/v1/clients/{id}/listings/{listing_id}
   */
  updateListingStatus: (clientId: number, listingId: number, status: ClientListingStatus, comment?: string) =>
    api.patch<ApiResponse<{ client_listing: any; message: string }>>(`/clients/${clientId}/listings/${listingId}`, {
      status,
      comment,
    }),

  // === Критерии поиска ===

  /**
   * Добавить критерий поиска
   * POST /api/v1/clients/{id}/criteria
   */
  addCriteria: (clientId: number, data: Record<string, any>) =>
    api.post<ApiResponse<{ criteria: any; message: string }>>(`/clients/${clientId}/criteria`, data),

  /**
   * Обновить критерий поиска
   * PUT /api/v1/clients/criteria/{id}
   */
  updateCriteria: (criteriaId: number, data: Record<string, any>) =>
    api.put<ApiResponse<{ message: string }>>(`/clients/criteria/${criteriaId}`, data),

  /**
   * Удалить критерий поиска
   * DELETE /api/v1/clients/criteria/{id}
   */
  deleteCriteria: (criteriaId: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/clients/criteria/${criteriaId}`),

  // === Стадии воронки ===

  /**
   * Получить все стадии
   * GET /api/v1/clients/stages
   */
  getStages: () =>
    api.get<ApiResponse<{ stages: PipelineStage[] }>>('/clients/stages'),

  /**
   * Создать стадию
   * POST /api/v1/clients/stages
   */
  createStage: (name: string, color: string = '#808080', isFinal: boolean = false) =>
    api.post<ApiResponse<{ stage: PipelineStage; message: string }>>('/clients/stages', { name, color, is_final: isFinal }),

  /**
   * Обновить стадию
   * PUT /api/v1/clients/stages/{id}
   */
  updateStage: (id: number, data: { name?: string; color?: string; is_final?: boolean }) =>
    api.put<ApiResponse<{ stage: PipelineStage; message: string }>>(`/clients/stages/${id}`, data),

  /**
   * Удалить стадию
   * DELETE /api/v1/clients/stages/{id}
   */
  deleteStage: (id: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/clients/stages/${id}`),

  /**
   * Изменить порядок стадий
   * PUT /api/v1/clients/stages/reorder
   */
  reorderStages: (order: number[]) =>
    api.put<ApiResponse<{ message: string }>>('/clients/stages/reorder', { order }),
};

// === PROPERTIES API (объекты недвижимости — новая CRM модель) ===

import type {
  Property,
  Contact as CrmContact,
  PipelineColumn as PropertyPipelineColumn,
  PipelineCard,
  PropertyStats,
  PropertyFilters,
  ContactFilters,
  ObjectClientItem,
  Interaction,
  Reminder,
} from '../types/property';

export const propertiesApi = {
  /**
   * Получить список объектов
   * GET /api/v1/properties
   */
  getAll: (params?: PropertyFilters) =>
    api.get<ApiResponse<{ properties: Property[]; pagination: { page: number; per_page: number; total: number; total_pages: number } }>>('/properties', { params }),

  /**
   * Получить карточку объекта
   * GET /api/v1/properties/{id}
   */
  getById: (id: number) =>
    api.get<ApiResponse<{ property: Property }>>(`/properties/${id}`),

  /**
   * Создать объект
   * POST /api/v1/properties
   */
  create: (data: Record<string, any>) =>
    api.post<ApiResponse<{ property: Property; message: string }>>('/properties', data),

  /**
   * Обновить объект
   * PUT /api/v1/properties/{id}
   */
  update: (id: number, data: Record<string, any>) =>
    api.put<ApiResponse<{ property: Property; message: string }>>(`/properties/${id}`, data),

  /**
   * Удалить объект
   * DELETE /api/v1/properties/{id}
   */
  delete: (id: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/properties/${id}`),

  /**
   * Архивировать/разархивировать
   * PATCH /api/v1/properties/{id}/archive
   */
  archive: (id: number, isArchived: boolean = true) =>
    api.patch<ApiResponse<{ is_archived: boolean; message: string }>>(`/properties/${id}/archive`, { is_archived: isArchived }),

  /**
   * Получить kanban-доску
   * GET /api/v1/properties/pipeline
   */
  getPipeline: () =>
    api.get<ApiResponse<{ pipeline: PropertyPipelineColumn[] }>>('/properties/pipeline'),

  /**
   * Получить статистику
   * GET /api/v1/properties/stats
   */
  getStats: () =>
    api.get<ApiResponse<PropertyStats>>('/properties/stats'),

  /**
   * Привязать контакт к объекту
   * POST /api/v1/properties/{id}/contacts
   */
  attachContact: (propertyId: number, contactId: number, stageId?: number) =>
    api.post<ApiResponse<{ object_client: ObjectClientItem; message: string }>>(`/properties/${propertyId}/contacts`, {
      contact_id: contactId,
      pipeline_stage_id: stageId,
    }),

  /**
   * Отвязать контакт от объекта
   * DELETE /api/v1/properties/{id}/contacts/{contactId}
   */
  detachContact: (propertyId: number, contactId: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/properties/${propertyId}/contacts/${contactId}`),

  /**
   * Сменить стадию связки
   * PATCH /api/v1/properties/{id}/contacts/{contactId}/stage
   */
  moveContactStage: (propertyId: number, contactId: number, stageId: number) =>
    api.patch<ApiResponse<{ pipeline_stage: any; message: string }>>(`/properties/${propertyId}/contacts/${contactId}/stage`, {
      pipeline_stage_id: stageId,
    }),

  /**
   * Обновить связку (комментарий, даты)
   * PATCH /api/v1/properties/{id}/contacts/{contactId}
   */
  updateContact: (propertyId: number, contactId: number, data: Record<string, any>) =>
    api.patch<ApiResponse<{ message: string }>>(`/properties/${propertyId}/contacts/${contactId}`, data),

  /**
   * Массовая операция с объектами
   * POST /api/v1/properties/bulk-action
   */
  bulkAction: (action: string, propertyIds: number[], params?: Record<string, any>) =>
    api.post<ApiResponse<{ affected: number; message: string }>>('/properties/bulk-action', {
      action,
      property_ids: propertyIds,
      params,
    }),
};

// === CONTACTS API (справочник контактов — новая CRM модель) ===

export const contactsApi = {
  /**
   * Получить список контактов
   * GET /api/v1/contacts
   */
  getAll: (params?: ContactFilters) =>
    api.get<ApiResponse<{ contacts: CrmContact[]; pagination: { page: number; per_page: number; total: number; total_pages: number } }>>('/contacts', { params }),

  /**
   * Получить карточку контакта
   * GET /api/v1/contacts/{id}
   */
  getById: (id: number) =>
    api.get<ApiResponse<{ contact: CrmContact }>>(`/contacts/${id}`),

  /**
   * Создать контакт
   * POST /api/v1/contacts
   */
  create: (data: Record<string, any>) =>
    api.post<ApiResponse<{ contact: CrmContact; message: string }>>('/contacts', data),

  /**
   * Обновить контакт
   * PUT /api/v1/contacts/{id}
   */
  update: (id: number, data: Record<string, any>) =>
    api.put<ApiResponse<{ contact: CrmContact; message: string }>>(`/contacts/${id}`, data),

  /**
   * Удалить контакт
   * DELETE /api/v1/contacts/{id}
   */
  delete: (id: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/contacts/${id}`),

  /**
   * Поиск контактов (для ContactPicker)
   * GET /api/v1/contacts/search?q=...
   */
  search: (query: string) =>
    api.get<ApiResponse<{ contacts: CrmContact[] }>>('/contacts/search', { params: { q: query } }),
};

// === INTERACTIONS API (таймлайн взаимодействий) ===

export const interactionsApi = {
  /**
   * Таймлайн по объекту (все связки)
   * GET /api/v1/properties/{id}/interactions
   */
  getByProperty: (propertyId: number, limit = 50, offset = 0) =>
    api.get<ApiResponse<{ interactions: Interaction[]; total: number }>>(`/properties/${propertyId}/interactions`, {
      params: { limit, offset },
    }),

  /**
   * Таймлайн по контакту
   * GET /api/v1/contacts/{id}/interactions
   */
  getByContact: (contactId: number, limit = 50, offset = 0) =>
    api.get<ApiResponse<{ interactions: Interaction[]; total: number }>>(`/contacts/${contactId}/interactions`, {
      params: { limit, offset },
    }),

  /**
   * Таймлайн конкретной связки объект+контакт
   * GET /api/v1/properties/{id}/contacts/{contactId}/interactions
   */
  getByObjectClient: (propertyId: number, contactId: number, limit = 50, offset = 0) =>
    api.get<ApiResponse<{ interactions: Interaction[]; total: number }>>(`/properties/${propertyId}/contacts/${contactId}/interactions`, {
      params: { limit, offset },
    }),

  /**
   * Создать взаимодействие
   * POST /api/v1/properties/{id}/contacts/{contactId}/interactions
   */
  create: (propertyId: number, contactId: number, data: {
    type: string;
    description?: string;
    interaction_at?: string;
    metadata?: Record<string, any>;
  }) =>
    api.post<ApiResponse<{ interaction: Interaction; message: string }>>(`/properties/${propertyId}/contacts/${contactId}/interactions`, data),
};

// === REMINDERS API (напоминания CRM) ===

export const remindersApi = {
  /**
   * Все напоминания текущего пользователя
   * GET /api/v1/reminders
   */
  getAll: () =>
    api.get<ApiResponse<{ reminders: Reminder[] }>>('/reminders'),

  /**
   * Напоминания по связке
   * GET /api/v1/properties/{id}/contacts/{contactId}/reminders
   */
  getByObjectClient: (propertyId: number, contactId: number) =>
    api.get<ApiResponse<{ reminders: Reminder[] }>>(`/properties/${propertyId}/contacts/${contactId}/reminders`),

  /**
   * Создать напоминание
   * POST /api/v1/properties/{id}/contacts/{contactId}/reminders
   */
  create: (propertyId: number, contactId: number, data: { remind_at: string; message: string }) =>
    api.post<ApiResponse<{ reminder: Reminder; message: string }>>(`/properties/${propertyId}/contacts/${contactId}/reminders`, data),

  /**
   * Удалить напоминание
   * DELETE /api/v1/reminders/{id}
   */
  delete: (reminderId: number) =>
    api.delete<ApiResponse<{ message: string }>>(`/reminders/${reminderId}`),
};

// === SOURCE AUTH API (авторизация на источниках: CIAN, Avito) ===

export interface SourceAuthStatus {
  is_authorized: boolean;
  has_cookies: boolean;
  is_expired?: boolean;
  last_validated_at?: string;
  expires_at?: string;
  subscription_info?: {
    // Поля CIAN
    status?: string;
    tariff?: string;
    expire_text?: string;
    limit_info?: string;
    phone?: string;
    // Поля Avito
    name?: string;
    contact_name?: string;
    position?: string;
    balance?: string;
    bonuses?: string;
    listings_remaining?: string;
    messages_count?: number;
    rating?: number | null;
  };
}

export interface SourceAuthStatusResponse {
  cian: SourceAuthStatus;
  avito: SourceAuthStatus;
}

export interface SaveCookiesResponse {
  success: boolean;
  message: string;
  auth_status?: boolean;
  subscription_info?: SourceAuthStatus['subscription_info'];
}

export const sourceAuthApi = {
  /**
   * Получить статус авторизации на источниках
   * GET /api/v1/source-auth/status
   */
  getStatus: (source?: 'cian' | 'avito') => {
    const params = source ? `?source=${source}` : '';
    return api.get<ApiResponse<SourceAuthStatusResponse>>(`/source-auth/status${params}`);
  },

  /**
   * Сохранить куки (ручной ввод)
   * POST /api/v1/source-auth/cookies
   */
  saveCookies: (source: 'cian' | 'avito', cookies: string) =>
    api.post<ApiResponse<SaveCookiesResponse>>('/source-auth/cookies', { source, cookies }),

  /**
   * Удалить куки (деавторизация)
   * DELETE /api/v1/source-auth/cookies
   */
  deleteCookies: (source: 'cian' | 'avito') =>
    api.delete<ApiResponse<{ success: boolean; message: string }>>(`/source-auth/cookies?source=${source}`),

  /**
   * Перепроверить авторизацию (валидация текущих кук)
   * POST /api/v1/source-auth/revalidate
   */
  revalidate: (source: 'cian' | 'avito') =>
    api.post<ApiResponse<SaveCookiesResponse>>('/source-auth/revalidate', { source }),
};

export default api;
