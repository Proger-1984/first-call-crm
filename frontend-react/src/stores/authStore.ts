import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { User, TelegramUser } from '../types';
import { authApi } from '../services/api';

// Типы ошибок авторизации
export type AuthErrorType = 
  | 'token_not_found'
  | 'token_expired' 
  | 'invalid_token'
  | 'invalid_token_type'
  | 'validation_error'
  | 'network_error'
  | 'unknown_error';

interface AuthState {
  user: User | null;
  accessToken: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;
  errorType: AuthErrorType | null;
  
  // Actions
  login: (telegramData: TelegramUser) => Promise<void>;
  logout: () => Promise<void>;
  logoutAll: () => Promise<void>;
  checkAuth: () => Promise<void>;
  refreshToken: () => Promise<boolean>;
  setUser: (user: User | null) => void;
  updateUser: (user: User) => void;
  setLoading: (loading: boolean) => void;
  clearError: () => void;
}

/**
 * Определяет тип ошибки из ответа API
 */
const getErrorType = (error: any): AuthErrorType => {
  const errorCode = error.response?.data?.error;
  if (errorCode === 'token_not_found') return 'token_not_found';
  if (errorCode === 'token_expired') return 'token_expired';
  if (errorCode === 'invalid_token') return 'invalid_token';
  if (errorCode === 'invalid_token_type') return 'invalid_token_type';
  if (errorCode === 'validation_error') return 'validation_error';
  if (error.message === 'Network Error') return 'network_error';
  return 'unknown_error';
};

/**
 * Получает понятное сообщение об ошибке
 */
const getErrorMessage = (error: any): string => {
  // Сначала пробуем получить сообщение из API
  if (error.response?.data?.message) {
    return error.response.data.message;
  }
  
  // Сообщения по типу ошибки
  const errorType = getErrorType(error);
  switch (errorType) {
    case 'token_not_found':
      return 'Сессия не найдена. Войдите в систему.';
    case 'token_expired':
      return 'Сессия истекла. Войдите в систему заново.';
    case 'invalid_token':
      return 'Недействительная сессия. Войдите в систему.';
    case 'invalid_token_type':
      return 'Ошибка авторизации. Войдите в систему.';
    case 'validation_error':
      return 'Ошибка данных авторизации.';
    case 'network_error':
      return 'Ошибка сети. Проверьте подключение.';
    default:
      return 'Произошла ошибка. Попробуйте позже.';
  }
};

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      accessToken: null,
      isAuthenticated: false,
      isLoading: true,
      error: null,
      errorType: null,
      
      /**
       * Авторизация через Telegram
       * 
       * Поток:
       * 1. Отправляем данные от Telegram Widget
       * 2. Получаем access_token в ответе
       * 3. refresh_token устанавливается в HttpOnly cookie автоматически
       * 4. Сохраняем access_token в localStorage
       */
      login: async (telegramData: TelegramUser) => {
        set({ isLoading: true, error: null, errorType: null });
        
        try {
          const response = await authApi.authenticateWithTelegram(telegramData);
          
          const { access_token } = response.data;
          
          // Сохраняем токен
          localStorage.setItem('access_token', access_token);
          
          // Сразу загружаем полные данные пользователя через /me/info
          // т.к. endpoint авторизации не возвращает subscription_status_text, auto_call и др.
          const meResponse = await authApi.me();
          const user = meResponse.data.data.user;
          
          set({
            user,
            accessToken: access_token,
            isAuthenticated: true,
            isLoading: false,
            error: null,
            errorType: null,
          });
          
          console.log('[Auth] Login successful, user data loaded');
        } catch (error: any) {
          const errorType = getErrorType(error);
          const errorMessage = getErrorMessage(error);
          
          console.error('[Auth] Login failed:', errorType, errorMessage);
          
          set({
            user: null,
            accessToken: null,
            isAuthenticated: false,
            isLoading: false,
            error: errorMessage,
            errorType,
          });
          
          throw error;
        }
      },
      
      /**
       * Выход из системы
       * 
       * 1. Отправляет GET /api/v1/auth/logout на сервер
       * 2. Сервер инвалидирует access_token
       * 3. Сервер очищает refresh_token cookie
       * 4. Очищаем localStorage и Zustand state
       */
      logout: async () => {
        const token = localStorage.getItem('access_token');
        
        try {
          if (token) {
            await authApi.logout();
            console.log('[Auth] Logout successful - token invalidated on server');
          }
        } catch (error: any) {
          // Даже если сервер вернул ошибку (например, токен уже истёк),
          // всё равно очищаем локальное состояние
          console.warn('[Auth] Logout API error (cleaning up anyway):', error.response?.data?.error || error.message);
        } finally {
          // Очищаем access_token из localStorage
          localStorage.removeItem('access_token');
          
          // Очищаем данные имперсонации
          localStorage.removeItem('admin_token_backup');
          localStorage.removeItem('impersonated_by');
          
          // Очищаем persisted Zustand state
          localStorage.removeItem('auth-storage');
          
          // Сбрасываем состояние
          set({
            user: null,
            accessToken: null,
            isAuthenticated: false,
            isLoading: false,
            error: null,
            errorType: null,
          });
          
          console.log('[Auth] Local state cleared');
        }
      },
      
      /**
       * Выход из всех устройств
       * 
       * Инвалидирует ВСЕ токены пользователя на всех устройствах
       */
      logoutAll: async () => {
        try {
          await authApi.logoutAll();
          console.log('[Auth] Logout from all devices successful');
        } catch (error: any) {
          console.warn('[Auth] Logout all API error:', error.response?.data?.error || error.message);
        } finally {
          // Очищаем всё локальное состояние
          localStorage.removeItem('access_token');
          localStorage.removeItem('auth-storage');
          
          // Очищаем данные имперсонации
          localStorage.removeItem('admin_token_backup');
          localStorage.removeItem('impersonated_by');
          
          set({
            user: null,
            accessToken: null,
            isAuthenticated: false,
            isLoading: false,
            error: null,
            errorType: null,
          });
          
          console.log('[Auth] Local state cleared (all devices logout)');
        }
      },
      
      /**
       * Обновление токена через refresh
       * 
       * @returns true если обновление успешно, false если нет
       */
      refreshToken: async (): Promise<boolean> => {
        try {
          console.log('[Auth] Attempting token refresh...');
          
          const response = await authApi.refreshToken();
          const { access_token } = response.data;
          
          localStorage.setItem('access_token', access_token);
          set({ accessToken: access_token });
          
          console.log('[Auth] Token refreshed successfully');
          return true;
        } catch (error: any) {
          const errorType = getErrorType(error);
          console.log('[Auth] Token refresh failed:', errorType);
          
          // Очищаем токен при ошибке refresh
          localStorage.removeItem('access_token');
          set({
            accessToken: null,
            isAuthenticated: false,
            errorType,
          });
          
          return false;
        }
      },
      
      /**
       * Проверка авторизации при загрузке приложения
       * 
       * Алгоритм:
       * 1. Если нет access_token → сразу показываем форму логина (НЕ делаем refresh)
       * 2. Если есть access_token → проверяем через /me/info
       * 3. Если /me/info вернул 401 → interceptor автоматически пробует refresh
       * 4. Если refresh успешен → interceptor повторяет /me/info
       * 5. Если refresh не удался → показываем форму логина
       */
      checkAuth: async () => {
        console.log('[Auth] Checking authentication...');
        set({ isLoading: true });
        
        const token = localStorage.getItem('access_token');
        
        // Если нет токена - сразу показываем форму логина
        // НЕ пытаемся refresh, т.к. после logout cookie тоже очищена
        if (!token) {
          console.log('[Auth] No access token found, showing login form');
          set({
            user: null,
            accessToken: null,
            isAuthenticated: false,
            isLoading: false,
            error: null,
            errorType: null,
          });
          return;
        }
        
        // Есть токен - проверяем его валидность
        try {
          console.log('[Auth] Found access token, validating via /me/info...');
          const response = await authApi.me();
          // API возвращает { code, status, data: { user: {...} } }
          const user = response.data.data.user;
          
          set({
            user,
            accessToken: token,
            isAuthenticated: true,
            isLoading: false,
            error: null,
            errorType: null,
          });
          
          console.log('[Auth] Token valid, user authenticated');
        } catch (error: any) {
          // Если interceptor не смог обновить токен - мы попадём сюда
          console.log('[Auth] Authentication failed:', error.response?.status, error.response?.data?.error);
          
          const errorType = getErrorType(error);
          const isAuthError = error.response?.status === 401;
          
          // Очищаем токен
          localStorage.removeItem('access_token');
          localStorage.removeItem('auth-storage');
          
          // Очищаем данные имперсонации
          localStorage.removeItem('admin_token_backup');
          localStorage.removeItem('impersonated_by');
          
          set({
            user: null,
            accessToken: null,
            isAuthenticated: false,
            isLoading: false,
            // Не показываем ошибку если это просто истёкшая сессия
            error: isAuthError ? null : getErrorMessage(error),
            errorType: isAuthError ? null : errorType,
          });
        }
      },
      
      setUser: (user) => set({ 
        user, 
        isAuthenticated: !!user,
        isLoading: false 
      }),
      
      updateUser: (user) => set({ user }),
      
      setLoading: (isLoading) => set({ isLoading }),
      
      clearError: () => set({ error: null, errorType: null }),
    }),
    {
      name: 'auth-storage',
      partialize: (state) => ({ 
        user: state.user, 
        isAuthenticated: state.isAuthenticated 
      }),
    }
  )
);
