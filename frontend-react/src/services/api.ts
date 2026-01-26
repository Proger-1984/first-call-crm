import axios, { AxiosError } from 'axios';
import type { 
  User, 
  Listing, 
  Stats, 
  Tariff, 
  Subscription,
  FilterParams,
  PaginationParams,
  ApiResponse,
  PaginatedResponse,
  TelegramUser,
  AuthResponse,
  RefreshTokenResponse,
  UserSettingsResponse,
  UserSubscriptionFull,
  TariffInfoResponse
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
      return new Promise((resolve) => {
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
      
      const { access_token } = response.data;
      
      // Сохраняем новый токен
      localStorage.setItem('access_token', access_token);
      
      // Уведомляем всех ожидающих
      onTokenRefreshed(access_token);
      
      // Повторяем оригинальный запрос
      originalRequest.headers.Authorization = `Bearer ${access_token}`;
      return api(originalRequest);
      
    } catch (refreshError) {
      // Refresh не удался - очищаем токен и редиректим на логин
      localStorage.removeItem('access_token');
      
      // Редирект только если мы не на странице логина
      if (!window.location.pathname.includes('/login')) {
        window.location.href = '/login';
      }
      
      return Promise.reject(refreshError);
    } finally {
      isRefreshing = false;
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
  getAll: (filters?: FilterParams, pagination?: PaginationParams) =>
    api.get<ApiResponse<PaginatedResponse<Listing>>>('/listings', { 
      params: { ...filters, ...pagination } 
    }),
  
  getById: (id: number) =>
    api.get<ApiResponse<Listing>>(`/listings/${id}`),
  
  updateStatus: (id: number, status: string) =>
    api.patch<ApiResponse<Listing>>(`/listings/${id}/status`, { status }),
  
  toggleFavorite: (id: number) =>
    api.post<ApiResponse<Listing>>(`/listings/${id}/favorite`),
  
  getStats: () =>
    api.get<ApiResponse<Stats>>('/listings/stats'),
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
   * Возвращает файл APK
   */
  downloadAndroidApp: () => {
    const token = localStorage.getItem('access_token');
    const baseUrl = import.meta.env.VITE_API_URL || '/api/v1';
    
    // Создаём скрытую ссылку для скачивания с токеном
    const link = document.createElement('a');
    link.href = `${baseUrl}/me/download/android`;
    link.download = 'FirstCall.apk';
    
    // Для авторизованного скачивания используем fetch + blob
    return fetch(`${baseUrl}/me/download/android`, {
      headers: {
        'Authorization': `Bearer ${token}`,
      },
    })
    .then(response => {
      if (!response.ok) {
        throw new Error('Ошибка скачивания');
      }
      return response.blob();
    })
    .then(blob => {
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'FirstCall.apk';
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    });
  },
  
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

export default api;
