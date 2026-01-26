// Типы данных для CRM системы

export interface User {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  telegram_id?: string;
  telegram_photo_url?: string;
  avatar?: string;
  role?: 'user' | 'admin';
  is_trial_used?: boolean;
  phone_status?: boolean;
  app_connected?: boolean;
  app_last_ping_at?: string;
  auto_call?: boolean;
  auto_call_raised?: boolean;
  has_active_subscription?: boolean;
  subscription_status_text?: string;
  created_at?: string;
}

export interface Listing {
  id: number;
  title: string;
  description?: string;
  price: number;
  area: number;
  rooms?: number;
  floor?: number;
  total_floors?: number;
  building_type?: string;
  building_year?: number;
  address: string;
  source: 'avito' | 'cian' | 'yandex' | 'domofond' | 'ula';
  source_url?: string;
  phone: string;
  status: ListingStatus;
  duplicates?: Duplicate[];
  is_favorite: boolean;
  created_at: string;
  updated_at: string;
}

export type ListingStatus = 
  | 'new'
  | 'our_apartment'
  | 'not_answered'
  | 'not_picked_up'
  | 'not_first'
  | 'agent';

export interface Duplicate {
  id: number;
  source: 'avito' | 'cian' | 'yandex' | 'domofond' | 'ula';
  source_url: string;
}

export interface Stats {
  total: number;
  our_apartments: number;
  not_picked_up: number;
  not_first: number;
  not_answered: number;
  conversion: number;
  trends: {
    total: number;
    our_apartments: number;
    not_picked_up: number;
    not_first: number;
    not_answered: number;
    conversion: number;
  };
}

export interface Tariff {
  id: number;
  name: string;
  description: string | null;
  code?: string;
  duration_hours?: number;
  price?: number;
  is_active?: boolean;
}

export interface Category {
  id: number;
  name: string;
}

export interface Location {
  id: number;
  name: string;
}

export interface TariffPrice {
  tariff_id: number;
  location_id: number;
  price: number;
}

export interface TariffInfoResponse {
  categories: Category[];
  locations: Location[];
  tariffs: Tariff[];
  tariff_prices: TariffPrice[];
}

export interface Subscription {
  id: number;
  tariff: Tariff;
  location: string;
  category: string;
  expires_at: string;
  is_active: boolean;
}

// Типы для настроек пользователя
export interface UserSettingsData {
  log_events: boolean;
  auto_call: boolean;
  auto_call_raised: boolean;
  telegram_notifications: boolean;
}

export interface SourceItem {
  id: number;
  name: string;
  enabled: boolean;
}

export interface ActiveSubscriptionItem {
  id: number;
  name: string;
  enabled: boolean;
}

export interface UserSettingsResponse {
  settings: UserSettingsData;
  sources: SourceItem[];
  active_subscriptions: ActiveSubscriptionItem[];
}

// Типы для подписок в профиле
export interface UserSubscriptionFull {
  id: number;
  category_id: number;
  category_name: string;
  location_id: number;
  location_name: string;
  tariff_id: number;
  tariff_name: string;
  status: 'active' | 'pending' | 'expired' | 'cancelled';
  start_date: string | null;
  end_date: string | null;
  price_paid: number;
  is_enabled: boolean;
}

export interface FilterParams {
  date_from?: string;
  date_to?: string;
  listing_id?: string;
  phone?: string;
  category?: string;
  status?: ListingStatus;
  location?: string;
  metro?: string;
  source?: string;
  price_from?: number;
  price_to?: number;
  area_from?: number;
  area_to?: number;
  rooms?: number;
  metro_time_from?: number;
  metro_time_to?: number;
}

export interface PaginationParams {
  page: number;
  per_page: number;
}

export interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

// Типы для авторизации
export type { TelegramUser, AuthResponse, RefreshTokenResponse, AuthState } from './auth';
