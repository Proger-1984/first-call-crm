// Типы CRM-модуля — клиенты, воронка, подборки

/** Тип клиента */
export type ClientType = 'buyer' | 'seller' | 'renter' | 'landlord';

/** Статус привязки объявления к клиенту */
export type ClientListingStatus = 'proposed' | 'showed' | 'liked' | 'rejected';

/** Стадия воронки */
export interface PipelineStage {
  id: number;
  name: string;
  color: string;
  sort_order: number;
  is_system: boolean;
  is_final: boolean;
  clients_count: number;
}

/** Критерий поиска клиента */
export interface ClientSearchCriteria {
  id: number;
  category: { id: number; name: string } | null;
  location: { id: number; name: string } | null;
  room_ids: number[] | null;
  price_min: number | null;
  price_max: number | null;
  area_min: number | null;
  area_max: number | null;
  floor_min: number | null;
  floor_max: number | null;
  metro_ids: number[] | null;
  districts: string[] | null;
  notes: string | null;
  is_active: boolean;
}

/** Привязка объявления к клиенту */
export interface ClientListingItem {
  id: number;
  listing_id: number;
  status: ClientListingStatus;
  comment: string | null;
  showed_at: string | null;
  created_at: string;
  listing?: {
    id: number;
    title: string | null;
    price: number | null;
    address: string;
    url: string | null;
  };
}

/** Карточка клиента (полная) */
export interface Client {
  id: number;
  name: string;
  phone: string | null;
  phone_secondary: string | null;
  email: string | null;
  telegram_username: string | null;
  client_type: ClientType;
  source_type: string | null;
  source_details: string | null;
  budget_min: number | null;
  budget_max: number | null;
  comment: string | null;
  is_archived: boolean;
  last_contact_at: string | null;
  next_contact_at: string | null;
  created_at: string;
  updated_at: string;
  pipeline_stage?: {
    id: number;
    name: string;
    color: string;
    is_final: boolean;
  };
  search_criteria?: ClientSearchCriteria[];
  listings?: ClientListingItem[];
  listings_count?: number;
}

/** Клиент (краткая версия для kanban) */
export interface ClientShort {
  id: number;
  name: string;
  phone: string | null;
  client_type: ClientType;
  budget_min: number | null;
  budget_max: number | null;
  last_contact_at: string | null;
  next_contact_at: string | null;
  updated_at: string;
}

/** Стадия с клиентами (для kanban) */
export interface PipelineColumn {
  id: number;
  name: string;
  color: string;
  sort_order: number;
  is_system: boolean;
  is_final: boolean;
  clients: ClientShort[];
}

/** Статистика по клиентам */
export interface ClientStats {
  total_active: number;
  total_archived: number;
  by_type: Record<ClientType, number>;
  new_this_week: number;
  overdue_contacts: number;
}

/** Фильтры для списка клиентов */
export interface ClientFilters {
  page?: number;
  per_page?: number;
  sort?: 'created_at' | 'name' | 'last_contact_at' | 'next_contact_at' | 'budget_max';
  order?: 'asc' | 'desc';
  search?: string;
  client_type?: ClientType;
  stage_id?: number;
  is_archived?: boolean;
  source_type?: string;
}

/** Метки типов клиентов (для UI) */
export const CLIENT_TYPE_LABELS: Record<ClientType, string> = {
  buyer: 'Покупатель',
  seller: 'Продавец',
  renter: 'Арендатор',
  landlord: 'Арендодатель',
};

/** Метки статусов привязки объявлений */
export const LISTING_STATUS_LABELS: Record<ClientListingStatus, string> = {
  proposed: 'Предложено',
  showed: 'Показано',
  liked: 'Понравилось',
  rejected: 'Отклонено',
};
