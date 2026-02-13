// Типы CRM-модуля — объекты недвижимости, контакты, воронка (новая модель)

/** Тип сделки */
export type DealType = 'sale' | 'rent';

/** Стадия воронки (переиспользуем из client.ts) */
export interface PipelineStage {
  id: number;
  name: string;
  color: string;
  sort_order: number;
  is_system: boolean;
  is_final: boolean;
  clients_count: number;
}

/** Контакт (покупатель/арендатор) */
export interface Contact {
  id: number;
  name: string;
  phone: string | null;
  phone_secondary: string | null;
  email: string | null;
  telegram_username: string | null;
  comment: string | null;
  is_archived: boolean;
  created_at: string;
  updated_at: string;
  properties_count?: number;
  object_clients?: ObjectClientItem[];
}

/** Связка объект+контакт (здесь живёт воронка) */
export interface ObjectClientItem {
  id: number;
  contact_id: number;
  property_id?: number;
  comment: string | null;
  next_contact_at: string | null;
  last_contact_at: string | null;
  created_at: string;
  contact?: {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
  };
  property?: {
    id: number;
    title: string | null;
    address: string | null;
    price: number | null;
    deal_type: DealType;
  };
  pipeline_stage?: {
    id: number;
    name: string;
    color: string;
    is_final?: boolean;
  };
}

/** Объект недвижимости (полная карточка) */
export interface Property {
  id: number;
  listing_id: number | null;
  title: string | null;
  address: string | null;
  price: number | null;
  rooms: number | null;
  area: number | null;
  floor: number | null;
  floors_total: number | null;
  description: string | null;
  url: string | null;
  deal_type: DealType;
  owner_name: string | null;
  owner_phone: string | null;
  owner_phone_secondary: string | null;
  source_type: string | null;
  source_details: string | null;
  comment: string | null;
  is_archived: boolean;
  created_at: string;
  updated_at: string;
  object_clients?: ObjectClientItem[];
  contacts_count?: number;
  listing?: {
    id: number;
    title: string | null;
    price: number | null;
    url: string | null;
  };
}

/** Карточка kanban (пара объект+контакт) */
export interface PipelineCard {
  id: number;
  property_id: number;
  contact_id: number;
  comment: string | null;
  next_contact_at: string | null;
  last_contact_at: string | null;
  property: {
    id: number;
    title: string | null;
    address: string | null;
    price: number | null;
    rooms: number | null;
    area: number | null;
    floor: number | null;
    deal_type: DealType;
  } | null;
  contact: {
    id: number;
    name: string;
    phone: string | null;
  } | null;
  updated_at: string;
}

/** Стадия с карточками (для kanban) */
export interface PipelineColumn {
  id: number;
  name: string;
  color: string;
  sort_order: number;
  is_system: boolean;
  is_final: boolean;
  cards: PipelineCard[];
}

/** Статистика по объектам */
export interface PropertyStats {
  total_active: number;
  total_archived: number;
  by_deal_type: Record<DealType, number>;
  new_this_week: number;
  overdue_contacts: number;
  total_contacts: number;
}

/** Фильтры для списка объектов */
export interface PropertyFilters {
  page?: number;
  per_page?: number;
  sort?: 'created_at' | 'price' | 'address' | 'deal_type' | 'owner_name' | 'stage';
  order?: 'asc' | 'desc';
  search?: string;
  deal_type?: DealType;
  stage_ids?: string;
  is_archived?: boolean;
}

/** Фильтры для списка контактов */
export interface ContactFilters {
  page?: number;
  per_page?: number;
  sort?: 'created_at' | 'name' | 'phone';
  order?: 'asc' | 'desc';
  search?: string;
  is_archived?: boolean;
}

/** Метки типов сделок (для UI) */
export const DEAL_TYPE_LABELS: Record<DealType, string> = {
  sale: 'Продажа',
  rent: 'Аренда',
};

// ==========================================
// ТАЙМЛАЙН ВЗАИМОДЕЙСТВИЙ
// ==========================================

/** Тип взаимодействия */
export type InteractionType = 'call' | 'meeting' | 'showing' | 'message' | 'note' | 'stage_change';

/** Метки типов взаимодействий */
export const INTERACTION_TYPE_LABELS: Record<InteractionType, string> = {
  call: 'Звонок',
  meeting: 'Встреча',
  showing: 'Показ',
  message: 'Сообщение',
  note: 'Заметка',
  stage_change: 'Смена стадии',
};

/** Иконки типов взаимодействий (Material Icons) */
export const INTERACTION_TYPE_ICONS: Record<InteractionType, string> = {
  call: 'phone',
  meeting: 'groups',
  showing: 'visibility',
  message: 'chat',
  note: 'note',
  stage_change: 'swap_horiz',
};

/** Взаимодействие (элемент таймлайна) */
export interface Interaction {
  id: number;
  object_client_id: number;
  user_id: number;
  type: InteractionType;
  description: string | null;
  metadata: Record<string, any> | null;
  interaction_at: string;
  created_at: string;
  user?: {
    id: number;
    name: string;
  };
  contact?: {
    id: number;
    name: string;
  };
  property?: {
    id: number;
    address: string | null;
  };
}

// ==========================================
// НАПОМИНАНИЯ
// ==========================================

/** Напоминание */
export interface Reminder {
  id: number;
  object_client_id: number;
  user_id: number;
  remind_at: string;
  message: string;
  is_sent: boolean;
  sent_at: string | null;
  created_at: string;
  property?: {
    id: number;
    address: string | null;
    title: string | null;
  };
  contact?: {
    id: number;
    name: string;
    phone: string | null;
  };
  pipeline_stage?: {
    id: number;
    name: string;
    color: string;
  };
}
