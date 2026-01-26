/**
 * Типы для авторизации через Telegram
 */
import type { User } from './index';

// Данные от Telegram Widget
export interface TelegramUser {
  id: number;
  first_name: string;
  last_name?: string;
  username?: string;
  photo_url?: string;
  auth_date: number;
  hash: string;
}

// Ответ от API при авторизации
export interface AuthResponse {
  code: number;
  status: string;
  message?: string;
  access_token: string;
  user: User;
}

// Ответ от API при обновлении токена
export interface RefreshTokenResponse {
  code: number;
  status: string;
  access_token: string;
  expires_in: number;
}

// Состояние авторизации
export interface AuthState {
  user: User | null;
  accessToken: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;
}
