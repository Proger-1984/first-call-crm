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

// Источник объявления
export interface ListingSource {
  id: number;
  name: string;
}

// Категория объявления
export interface ListingCategory {
  id: number;
  name: string;
}

// Статус объявления
export interface ListingStatusInfo {
  id: number;
  name: ListingStatusCode;
}

// Локация объявления
export interface ListingLocation {
  id: number;
  name: string;
}

// Тип комнат
export interface ListingRoom {
  id: number;
  name: string;
  code: string;
}

// Станция метро
export interface ListingMetro {
  id: number;
  name: string;
  line: string | null;
  color: string | null;
  travel_time_min: number | null;
  distance: string | null;  // Расстояние до метро ("900 м", "2,7 км")
  travel_type: 'walk' | 'car' | 'public_transport';
}

// Задача обработки фото
export interface PhotoTask {
  id: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  photos_count: number | null;
  error_message: string | null;
}

// Запись истории изменения цены
export interface PriceHistoryEntry {
  date: number;      // Unix timestamp
  price: number;     // Цена на эту дату
  diff: number;      // Изменение относительно предыдущей цены
}

// Объявление
export interface Listing {
  id: number;
  external_id: string;
  title: string | null;
  description?: string | null;
  price: number | null;
  price_history?: PriceHistoryEntry[] | null;  // История изменения цен
  square_meters: number | null;
  floor?: number | null;
  floors_total?: number | null;
  phone: string | null;
  phone_unavailable?: boolean;  // Телефон недоступен (только звонки через приложение)
  address: string;
  city?: string | null;
  street?: string | null;
  house?: string | null;
  url?: string | null;
  lat?: number | null;
  lng?: number | null;
  is_paid: boolean;
  source: ListingSource;
  category?: ListingCategory;
  status: ListingStatusInfo;
  location?: ListingLocation;
  room?: ListingRoom;
  metro?: ListingMetro[];
  photo_task?: PhotoTask;  // Последняя задача обработки фото
  created_at: string;
  updated_at: string;
}

// Коды статусов объявлений
export type ListingStatusCode = 
  | 'new'
  | 'our_apartment'
  | 'not_answered'
  | 'not_picked_up'
  | 'not_first'
  | 'agent';

// Для обратной совместимости
export type ListingStatus = ListingStatusCode;

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
  category_id: number;
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
  phone?: string;
  status?: ListingStatusCode;
  source_id?: number;
  category_id?: number;
  location_id?: number;
  metro_id?: number;
  price_from?: number;
  price_to?: number;
  area_from?: number;
  area_to?: number;
  room_id?: number;
}

export interface SortParams {
  sort?: 'created_at' | 'price' | 'square_meters' | 'updated_at' | 'source_id' | 'listing_status_id';
  order?: 'asc' | 'desc';
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
  listings: T[];
  pagination: {
    total: number;
    page: number;
    per_page: number;
    total_pages: number;
  };
  stats?: ListingsStats;  // Статистика для баннеров
}

// Тренды статистики (изменение в процентах относительно предыдущего периода)
export interface ListingsStatsTrends {
  total: number;
  our_apartments: number;
  not_picked_up: number;
  not_first: number;
  not_answered: number;
  agent: number;
  new: number;
  conversion: number;  // Абсолютное изменение конверсии (п.п.)
}

// Статистика по объявлениям
export interface ListingsStats {
  total: number;
  our_apartments: number;
  not_picked_up: number;
  not_first: number;
  not_answered: number;
  agent: number;
  new: number;
  conversion: number;
  trends?: ListingsStatsTrends;
  period?: {
    from: string;
    to: string;
    days: number;
  };
  prev_period?: {
    from: string;
    to: string;
  };
}

// Типы для авторизации
export type { TelegramUser, AuthResponse, RefreshTokenResponse, AuthState } from './auth';
