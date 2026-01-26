import { useState } from 'react';
import { StatsCard } from '../../components/UI/StatsCard';
import { SourceBadge, DuplicateBadge, Badge } from '../../components/UI/Badge';
import { MultiSelect } from '../../components/UI/MultiSelect';
import { DatePicker } from '../../components/UI/DatePicker';
import './Dashboard.css';

// Опции для фильтров
const categoryOptions = [
  { value: '1', label: '1-комн. квартира' },
  { value: '2', label: '2-комн. квартира' },
  { value: '3', label: '3-комн. квартира' },
  { value: 'studio', label: 'Студия' },
];

const statusOptions = [
  { value: 'our_apartment', label: 'Наша квартира' },
  { value: 'not_first', label: 'Не первые' },
  { value: 'not_picked_up', label: 'Не снял' },
  { value: 'not_answered', label: 'Не дозвонился' },
  { value: 'agent', label: 'Агент' },
];

const locationOptions = [
  { value: 'center', label: 'Центр' },
  { value: 'north', label: 'Север' },
  { value: 'south', label: 'Юг' },
  { value: 'west', label: 'Запад' },
];

const metroOptions = [
  { value: '1', label: 'Комсомольская' },
  { value: '2', label: 'Курская' },
  { value: '3', label: 'Тверская' },
];

const sourceOptions = [
  { value: 'avito', label: 'Авито' },
  { value: 'cian', label: 'ЦИАН' },
  { value: 'yandex', label: 'Яндекс' },
  { value: 'domofond', label: 'Домофонд' },
];

const roomsOptions = [
  { value: 'studio', label: 'Студия' },
  { value: '1', label: '1' },
  { value: '2', label: '2' },
  { value: '3', label: '3+' },
];

// Цвета линий московского метро
const metroLineColors: Record<string, string> = {
  'Сокольническая': '#EF161E',
  'Замоскворецкая': '#2DBE2C',
  'Арбатско-Покровская': '#0078BE',
  'Филёвская': '#00BFFF',
  'Кольцевая': '#8D5B2D',
  'Калужско-Рижская': '#ED9121',
  'Таганско-Краснопресненская': '#800080',
  'Калининская': '#FFD702',
  'Серпуховско-Тимирязевская': '#999999',
  'Люблинско-Дмитровская': '#99CC00',
  'Большая кольцевая': '#82C0C0',
  'Бутовская': '#A1B3D4',
  'Солнцевская': '#FFCD1C',
  'МЦК': '#F74E4E',
  'МЦД-1': '#EDA59E',
  'МЦД-2': '#E95B7B',
};

// Станции метро и их линии
const metroStations: Record<string, string> = {
  'Мичуринский проспект': 'Солнцевская',
  'Аэропорт': 'Замоскворецкая',
  'Полянка': 'Серпуховско-Тимирязевская',
  'Нахимовский проспект': 'Серпуховско-Тимирязевская',
  'Новые Черёмушки': 'Калужско-Рижская',
  'Кутузовская': 'Филёвская',
  'Арбатская': 'Арбатско-Покровская',
  'Университет': 'Сокольническая',
  'Маяковская': 'Замоскворецкая',
  'Крылатское': 'Арбатско-Покровская',
  'Бульвар Дмитрия Донского': 'Серпуховско-Тимирязевская',
  'Октябрьское поле': 'Таганско-Краснопресненская',
  'Фрунзенская': 'Сокольническая',
  'Тульская': 'Серпуховско-Тимирязевская',
  'Красные ворота': 'Сокольническая',
  'Проспект Вернадского': 'Сокольническая',
  'Смоленская': 'Арбатско-Покровская',
  'Щёлковская': 'Арбатско-Покровская',
  'Октябрьская': 'Кольцевая',
  'Молодёжная': 'Арбатско-Покровская',
  'Каширская': 'Замоскворецкая',
  'Белорусская': 'Замоскворецкая',
  'Первомайская': 'Арбатско-Покровская',
  'Проспект Ветеранов': 'Кировско-Выборгская',
  'Пролетарская': 'Таганско-Краснопресненская',
};

// Моковые данные для демонстрации
const mockListings = [
  {
    id: 1,
    date: '15.03.2024',
    time: '14:30',
    title: '2-комн. квартира, 52 м²',
    meta: 'Этаж: 4/9, Кирпичный дом, 2010 г.',
    source: 'avito' as const,
    price: '45 000 ₽',
    address: 'Москва, ул. Мичуринский проспект Олимпийская деревня, 25к1',
    metro: 'Мичуринский проспект',
    metroTime: 7,
    phone: '+7 (999) 123-45-67',
    duplicates: ['cian'] as const,
    area: '52 м²',
    status: 'our_apartment',
    isFavorite: false,
  },
  {
    id: 2,
    date: '16.03.2024',
    time: '09:15',
    title: '1-комн. квартира, 35 м²',
    meta: 'Этаж: 2/5, Панельный дом, 1985 г.',
    source: 'cian' as const,
    price: '32 000 ₽',
    address: 'Москва, Ленинградский проспект, 42к3',
    metro: 'Аэропорт',
    metroTime: 5,
    phone: '+7 (999) 987-65-43',
    duplicates: [] as const,
    area: '35 м²',
    status: 'not_answered',
    isFavorite: true,
  },
  {
    id: 3,
    date: '17.03.2024',
    time: '11:45',
    title: '3-комн. квартира, 78 м²',
    meta: 'Этаж: 7/12, Монолитный дом, 2015 г.',
    source: 'yandex' as const,
    price: '65 000 ₽',
    address: 'Москва, ул. Большая Полянка, 8с1',
    metro: 'Полянка',
    metroTime: 3,
    phone: '+7 (999) 111-22-33',
    duplicates: ['avito'] as const,
    area: '78 м²',
    status: 'not_picked_up',
    isFavorite: false,
  },
  {
    id: 4,
    date: '18.03.2024',
    time: '16:20',
    title: 'Студия, 28 м²',
    meta: 'Этаж: 12/16, Монолитный дом, 2019 г.',
    source: 'ula' as const,
    price: '28 000 ₽',
    address: 'Москва, Нахимовский проспект, 21к2',
    metro: 'Нахимовский проспект',
    metroTime: 12,
    phone: '+7 (999) 444-55-66',
    duplicates: [] as const,
    area: '28 м²',
    status: 'agent',
    isFavorite: false,
  },
  {
    id: 5,
    date: '19.03.2024',
    time: '10:05',
    title: '2-комн. квартира, 48 м²',
    meta: 'Этаж: 3/9, Кирпичный дом, 2005 г.',
    source: 'avito' as const,
    price: '42 000 ₽',
    address: 'Москва, ул. Профсоюзная, 55к1',
    metro: 'Новые Черёмушки',
    metroTime: 8,
    phone: '+7 (999) 777-88-99',
    duplicates: ['cian', 'yandex'] as const,
    area: '48 м²',
    status: 'not_first',
    isFavorite: false,
  },
  {
    id: 6,
    date: '20.03.2024',
    time: '08:30',
    title: '1-комн. квартира, 40 м²',
    meta: 'Этаж: 5/9, Панельный дом, 2000 г.',
    source: 'cian' as const,
    price: '35 000 ₽',
    address: 'Москва, Кутузовский проспект, 12',
    metro: 'Кутузовская',
    metroTime: 4,
    phone: '+7 (999) 222-33-44',
    duplicates: [] as const,
    area: '40 м²',
    status: 'our_apartment',
    isFavorite: false,
  },
  {
    id: 7,
    date: '20.03.2024',
    time: '10:45',
    title: '3-комн. квартира, 85 м²',
    meta: 'Этаж: 8/14, Монолитный дом, 2018 г.',
    source: 'avito' as const,
    price: '75 000 ₽',
    address: 'Москва, ул. Арбат, 28с2',
    metro: 'Арбатская',
    metroTime: 2,
    phone: '+7 (999) 333-44-55',
    duplicates: ['yandex'] as const,
    area: '85 м²',
    status: 'not_answered',
    isFavorite: false,
  },
  {
    id: 8,
    date: '21.03.2024',
    time: '12:00',
    title: '2-комн. квартира, 55 м²',
    meta: 'Этаж: 6/10, Кирпичный дом, 2012 г.',
    source: 'yandex' as const,
    price: '48 000 ₽',
    address: 'Москва, Ломоносовский проспект, 5к1',
    metro: 'Университет',
    metroTime: 6,
    phone: '+7 (999) 555-66-77',
    duplicates: [] as const,
    area: '55 м²',
    status: 'our_apartment',
    isFavorite: true,
  },
  {
    id: 9,
    date: '21.03.2024',
    time: '14:15',
    title: 'Студия, 25 м²',
    meta: 'Этаж: 3/5, Панельный дом, 1990 г.',
    source: 'cian' as const,
    price: '22 000 ₽',
    address: 'Москва, ул. Тверская-Ямская 1-я, 100',
    metro: 'Маяковская',
    metroTime: 10,
    phone: '+7 (999) 666-77-88',
    duplicates: ['avito'] as const,
    area: '25 м²',
    status: 'agent',
    isFavorite: false,
  },
  {
    id: 10,
    date: '22.03.2024',
    time: '09:30',
    title: '4-комн. квартира, 120 м²',
    meta: 'Этаж: 10/16, Монолитный дом, 2020 г.',
    source: 'avito' as const,
    price: '95 000 ₽',
    address: 'Москва, Рублёвское шоссе, 15к3',
    metro: 'Крылатское',
    metroTime: 15,
    phone: '+7 (999) 888-99-00',
    duplicates: ['cian', 'yandex'] as const,
    area: '120 м²',
    status: 'not_picked_up',
    isFavorite: false,
  },
  {
    id: 11,
    date: '22.03.2024',
    time: '11:20',
    title: '1-комн. квартира, 38 м²',
    meta: 'Этаж: 2/9, Кирпичный дом, 2008 г.',
    source: 'ula' as const,
    price: '30 000 ₽',
    address: 'Москва, б-р Дмитрия Донского, 7',
    metro: 'Бульвар Дмитрия Донского',
    metroTime: 3,
    phone: '+7 (999) 111-00-99',
    duplicates: [] as const,
    area: '38 м²',
    status: 'not_first',
    isFavorite: false,
  },
  {
    id: 12,
    date: '23.03.2024',
    time: '15:45',
    title: '2-комн. квартира, 60 м²',
    meta: 'Этаж: 7/12, Панельный дом, 2005 г.',
    source: 'cian' as const,
    price: '52 000 ₽',
    address: 'Москва, ул. Народного Ополчения, 33к1',
    metro: 'Октябрьское поле',
    metroTime: 9,
    phone: '+7 (999) 222-11-00',
    duplicates: ['avito'] as const,
    area: '60 м²',
    status: 'our_apartment',
    isFavorite: false,
  },
  {
    id: 13,
    date: '23.03.2024',
    time: '17:00',
    title: '3-комн. квартира, 72 м²',
    meta: 'Этаж: 4/9, Кирпичный дом, 1998 г.',
    source: 'yandex' as const,
    price: '58 000 ₽',
    address: 'Москва, Комсомольский проспект, 45',
    metro: 'Фрунзенская',
    metroTime: 5,
    phone: '+7 (999) 333-22-11',
    duplicates: [] as const,
    area: '72 м²',
    status: 'not_answered',
    isFavorite: true,
  },
  {
    id: 14,
    date: '24.03.2024',
    time: '08:00',
    title: 'Студия, 30 м²',
    meta: 'Этаж: 15/20, Монолитный дом, 2021 г.',
    source: 'avito' as const,
    price: '35 000 ₽',
    address: 'Москва, Варшавское шоссе, 1с5',
    metro: 'Тульская',
    metroTime: 7,
    phone: '+7 (999) 444-33-22',
    duplicates: ['cian'] as const,
    area: '30 м²',
    status: 'agent',
    isFavorite: false,
  },
  {
    id: 15,
    date: '24.03.2024',
    time: '10:30',
    title: '1-комн. квартира, 42 м²',
    meta: 'Этаж: 6/9, Панельный дом, 2003 г.',
    source: 'cian' as const,
    price: '38 000 ₽',
    address: 'Москва, ул. Садовая-Спасская, 18',
    metro: 'Красные ворота',
    metroTime: 4,
    phone: '+7 (999) 555-44-33',
    duplicates: [] as const,
    area: '42 м²',
    status: 'not_picked_up',
    isFavorite: false,
  },
  {
    id: 16,
    date: '25.03.2024',
    time: '13:15',
    title: '2-комн. квартира, 58 м²',
    meta: 'Этаж: 3/5, Кирпичный дом, 1995 г.',
    source: 'ula' as const,
    price: '44 000 ₽',
    address: 'Москва, пр. Вернадского, 78',
    metro: 'Проспект Вернадского',
    metroTime: 11,
    phone: '+7 (999) 666-55-44',
    duplicates: ['yandex'] as const,
    area: '58 м²',
    status: 'our_apartment',
    isFavorite: false,
  },
  {
    id: 17,
    date: '25.03.2024',
    time: '16:40',
    title: '3-комн. квартира, 90 м²',
    meta: 'Этаж: 9/12, Монолитный дом, 2017 г.',
    source: 'yandex' as const,
    price: '82 000 ₽',
    address: 'Москва, ул. Новый Арбат, 25',
    metro: 'Смоленская',
    metroTime: 6,
    phone: '+7 (999) 777-66-55',
    duplicates: ['avito', 'cian'] as const,
    area: '90 м²',
    status: 'not_first',
    isFavorite: true,
  },
  {
    id: 18,
    date: '26.03.2024',
    time: '09:00',
    title: '1-комн. квартира, 33 м²',
    meta: 'Этаж: 1/5, Панельный дом, 1980 г.',
    source: 'avito' as const,
    price: '25 000 ₽',
    address: 'Москва, Щёлковское шоссе, 10',
    metro: 'Щёлковская',
    metroTime: 8,
    phone: '+7 (999) 888-77-66',
    duplicates: [] as const,
    area: '33 м²',
    status: 'not_answered',
    isFavorite: false,
  },
  {
    id: 19,
    date: '26.03.2024',
    time: '11:30',
    title: '4-комн. квартира, 110 м²',
    meta: 'Этаж: 5/10, Кирпичный дом, 2010 г.',
    source: 'cian' as const,
    price: '88 000 ₽',
    address: 'Москва, Ленинский проспект, 3с1',
    metro: 'Октябрьская',
    metroTime: 2,
    phone: '+7 (999) 999-88-77',
    duplicates: ['avito'] as const,
    area: '110 м²',
    status: 'our_apartment',
    isFavorite: false,
  },
  {
    id: 20,
    date: '27.03.2024',
    time: '14:00',
    title: 'Студия, 27 м²',
    meta: 'Этаж: 8/16, Монолитный дом, 2019 г.',
    source: 'yandex' as const,
    price: '29 000 ₽',
    address: 'Москва, ул. Молодёжная, 50к2',
    metro: 'Молодёжная',
    metroTime: 5,
    phone: '+7 (999) 000-99-88',
    duplicates: [] as const,
    area: '27 м²',
    status: 'agent',
    isFavorite: false,
  },
  {
    id: 21,
    date: '27.03.2024',
    time: '16:20',
    title: '2-комн. квартира, 65 м²',
    meta: 'Этаж: 4/9, Панельный дом, 2007 г.',
    source: 'avito' as const,
    price: '55 000 ₽',
    address: 'Москва, Каширское шоссе, 22к1',
    metro: 'Каширская',
    metroTime: 10,
    phone: '+7 (999) 123-00-11',
    duplicates: ['cian', 'yandex'] as const,
    area: '65 м²',
    status: 'not_picked_up',
    isFavorite: true,
  },
  {
    id: 22,
    date: '28.03.2024',
    time: '08:45',
    title: '1-комн. квартира, 36 м²',
    meta: 'Этаж: 2/5, Кирпичный дом, 1992 г.',
    source: 'ula' as const,
    price: '28 000 ₽',
    address: 'Москва, ул. Лесная, 8',
    metro: 'Белорусская',
    metroTime: 12,
    phone: '+7 (999) 234-11-22',
    duplicates: [] as const,
    area: '36 м²',
    status: 'not_first',
    isFavorite: false,
  },
  {
    id: 23,
    date: '28.03.2024',
    time: '12:10',
    title: '3-комн. квартира, 80 м²',
    meta: 'Этаж: 6/12, Монолитный дом, 2014 г.',
    source: 'cian' as const,
    price: '68 000 ₽',
    address: 'Москва, Измайловский бульвар, 17',
    metro: 'Первомайская',
    metroTime: 4,
    phone: '+7 (999) 345-22-33',
    duplicates: ['avito'] as const,
    area: '80 м²',
    status: 'our_apartment',
    isFavorite: false,
  },
  {
    id: 24,
    date: '29.03.2024',
    time: '10:00',
    title: '2-комн. квартира, 50 м²',
    meta: 'Этаж: 3/9, Панельный дом, 2001 г.',
    source: 'yandex' as const,
    price: '40 000 ₽',
    address: 'Москва, пр. Ветеранов, 60к3',
    metro: 'Проспект Ветеранов',
    metroTime: 7,
    phone: '+7 (999) 456-33-44',
    duplicates: [] as const,
    area: '50 м²',
    status: 'not_answered',
    isFavorite: false,
  },
  {
    id: 25,
    date: '29.03.2024',
    time: '15:30',
    title: 'Студия, 32 м²',
    meta: 'Этаж: 11/18, Монолитный дом, 2022 г.',
    source: 'avito' as const,
    price: '38 000 ₽',
    address: 'Москва, Волгоградский проспект, 5',
    metro: 'Пролетарская',
    metroTime: 3,
    phone: '+7 (999) 567-44-55',
    duplicates: ['cian'] as const,
    area: '32 м²',
    status: 'agent',
    isFavorite: true,
  },
];

const statusLabels: Record<string, { label: string; variant: string }> = {
  our_apartment: { label: 'Наша квартира', variant: 'success' },
  not_answered: { label: 'Не дозвонился', variant: 'danger' },
  not_picked_up: { label: 'Не снял', variant: 'warning' },
  agent: { label: 'Агент', variant: 'agent' },
  not_first: { label: 'Не первые', variant: 'not-first' },
  new: { label: 'Новое', variant: 'info' },
};

export function Dashboard() {
  const [filtersExpanded, setFiltersExpanded] = useState(false);

  // Состояние фильтров
  const [categoryFilter, setCategoryFilter] = useState<string[]>([]);
  const [statusFilter, setStatusFilter] = useState<string[]>([]);
  const [locationFilter, setLocationFilter] = useState<string[]>([]);
  const [metroFilter, setMetroFilter] = useState<string[]>([]);
  const [sourceFilter, setSourceFilter] = useState<string[]>([]);
  const [roomsFilter, setRoomsFilter] = useState<string[]>([]);

  return (
    <div className="dashboard">
      {/* Компактная статистика */}
      <div className="compact-stats">
        <div className="compact-stat">
          <div className="compact-stat-icon featured">
            <span className="material-icons">dashboard</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-value">1,258</div>
            <div className="compact-stat-title">Всего</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon success">
            <span className="material-icons">check_circle</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-value">764</div>
            <div className="compact-stat-title">Наших</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon warning">
            <span className="material-icons">phone_missed</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-value">352</div>
            <div className="compact-stat-title">Не снял</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon info">
            <span className="material-icons">groups</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-value">289</div>
            <div className="compact-stat-title">Не первые</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon danger">
            <span className="material-icons">phone_disabled</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-value">147</div>
            <div className="compact-stat-title">Не дозвон.</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon featured">
            <span className="material-icons">percent</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-value">32%</div>
            <div className="compact-stat-title">Конверсия</div>
          </div>
        </div>
      </div>

      {/* Фильтры */}
      <div className="card filters-card">
        <div className="card-header">
          <h3 className="card-title">
            <span className="material-icons">filter_list</span>
            Фильтры
          </h3>
          <button
            className={`filter-toggle-btn ${!filtersExpanded ? 'collapsed' : ''}`}
            onClick={() => setFiltersExpanded(!filtersExpanded)}
          >
            <span className="material-icons">
              {filtersExpanded ? 'expand_less' : 'expand_more'}
            </span>
            <span>{filtersExpanded ? 'Свернуть' : 'Развернуть'}</span>
          </button>
        </div>
        {filtersExpanded && (
          <div className="card-body">
            <div className="filters-grid">
              {/* Дата */}
              <div className="filter-group">
                <label className="filter-label">Дата</label>
                <div className="filter-row">
                  <DatePicker placeholder="От" />
                  <DatePicker placeholder="До" />
                </div>
              </div>
              {/* № объявления */}
              <div className="filter-group">
                <label className="filter-label">№ объявления</label>
                <input type="text" className="form-control" placeholder="Введите номер" />
              </div>
              {/* Телефон */}
              <div className="filter-group">
                <label className="filter-label">Телефон</label>
                <input type="tel" className="form-control" placeholder="+7 (___) ___-__-__" />
              </div>
              {/* Категория */}
              <div className="filter-group">
                <label className="filter-label">Категория</label>
                <MultiSelect
                  options={categoryOptions}
                  placeholder="Выберите категорию"
                  value={categoryFilter}
                  onChange={setCategoryFilter}
                />
              </div>
              {/* Статус */}
              <div className="filter-group">
                <label className="filter-label">Статус</label>
                <MultiSelect
                  options={statusOptions}
                  placeholder="Выберите статус"
                  value={statusFilter}
                  onChange={setStatusFilter}
                />
              </div>
              {/* Локация */}
              <div className="filter-group">
                <label className="filter-label">Локация</label>
                <MultiSelect
                  options={locationOptions}
                  placeholder="Выберите район"
                  value={locationFilter}
                  onChange={setLocationFilter}
                />
              </div>
              {/* Метро */}
              <div className="filter-group">
                <label className="filter-label">Метро</label>
                <MultiSelect
                  options={metroOptions}
                  placeholder="Выберите станцию"
                  value={metroFilter}
                  onChange={setMetroFilter}
                />
              </div>
              {/* Источник */}
              <div className="filter-group">
                <label className="filter-label">Источник</label>
                <MultiSelect
                  options={sourceOptions}
                  placeholder="Выберите источник"
                  value={sourceFilter}
                  onChange={setSourceFilter}
                />
              </div>
              {/* Цена */}
              <div className="filter-group">
                <label className="filter-label">Цена, ₽</label>
                <div className="filter-row">
                  <input type="number" className="form-control" placeholder="От" />
                  <input type="number" className="form-control" placeholder="До" />
                </div>
              </div>
              {/* Площадь */}
              <div className="filter-group">
                <label className="filter-label">Площадь, м²</label>
                <div className="filter-row">
                  <input type="number" className="form-control" placeholder="От" />
                  <input type="number" className="form-control" placeholder="До" />
                </div>
              </div>
              {/* Комнат */}
              <div className="filter-group">
                <label className="filter-label">Комнат</label>
                <MultiSelect
                  options={roomsOptions}
                  placeholder="Любое"
                  value={roomsFilter}
                  onChange={setRoomsFilter}
                />
              </div>
              {/* До метро */}
              <div className="filter-group">
                <label className="filter-label">До метро, мин</label>
                <div className="filter-row">
                  <input type="number" className="form-control" placeholder="От" />
                  <input type="number" className="form-control" placeholder="До" />
                </div>
              </div>
            </div>
            <div className="filter-actions">
              <button className="btn btn-outline">
                <span className="material-icons">refresh</span>
                Сбросить
              </button>
              <button className="btn btn-primary">
                <span className="material-icons">check</span>
                Применить
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Таблица объявлений */}
      <div className="card table-card no-header density-compact">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th className="sortable">
                  Дата и время
                  <span className="material-icons sort-icon">unfold_more</span>
                </th>
                <th>Заголовок</th>
                <th className="sortable">
                  Источник
                  <span className="material-icons sort-icon">unfold_more</span>
                </th>
                <th className="sortable">
                  Цена
                  <span className="material-icons sort-icon">unfold_more</span>
                </th>
                <th>Адрес</th>
                <th>Метро</th>
                <th>Контакт</th>
                <th>Дубли</th>
                <th className="sortable">
                  Площадь
                  <span className="material-icons sort-icon">unfold_more</span>
                </th>
                <th>Статус</th>
              </tr>
            </thead>
            <tbody>
              {mockListings.map((listing) => (
                <tr key={listing.id}>
                  <td>
                    <div className="date-cell">
                      <div className="date">{listing.date}</div>
                      <div className="time">{listing.time}</div>
                    </div>
                  </td>
                  <td>
                    <div className="listing-preview">
                      <div className="listing-header">
                        <div className="listing-info">
                          <h4>{listing.title}</h4>
                        </div>
                        <div className="listing-actions">
                          <div className="listing-action" title="Открыть">
                            <span className="material-icons">open_in_new</span>
                          </div>
                          <div
                            className={`listing-action favorite ${listing.isFavorite ? 'active' : ''}`}
                            title={listing.isFavorite ? 'В избранном' : 'В избранное'}
                          >
                            <span className="material-icons">
                              {listing.isFavorite ? 'star' : 'star_border'}
                            </span>
                          </div>
                          <div className="listing-action" title="Скопировать ссылку">
                            <span className="material-icons">link</span>
                          </div>
                        </div>
                      </div>
                      <div className="listing-meta">{listing.meta}</div>
                    </div>
                  </td>
                  <td>
                    <SourceBadge source={listing.source} />
                  </td>
                  <td>
                    <strong>{listing.price}</strong>
                  </td>
                  <td className="address-cell">{listing.address}</td>
                  <td className="metro-cell">
                    {'metro' in listing && listing.metro ? (
                      <div className="metro-info">
                        <span
                          className="metro-line-dot"
                          style={{
                            backgroundColor: metroLineColors[metroStations[listing.metro] || ''] || '#999'
                          }}
                        />
                        <div className="metro-text">
                          <span className="metro-name">{listing.metro}</span>
                          <span className="metro-time">{listing.metroTime} мин</span>
                        </div>
                      </div>
                    ) : '—'}
                  </td>
                  <td className="phone-cell">
                    <a href={`tel:${listing.phone}`} className="phone-link">
                      {listing.phone}
                    </a>
                  </td>
                  <td>
                    {listing.duplicates.length > 0 ? (
                      <div className="duplicates-list">
                        {listing.duplicates.map((dup) => (
                          <DuplicateBadge key={dup} source={dup} />
                        ))}
                      </div>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="area-cell">{listing.area}</td>
                  <td>
                    <div className="status-cell">
                      <Badge variant={statusLabels[listing.status]?.variant as any}>
                        {statusLabels[listing.status]?.label}
                      </Badge>
                      <div className="call-action-btn" title="Позвонить">
                        <span className="material-icons">call</span>
                      </div>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="pagination-container">
          <div className="table-actions">
            <button className="btn btn-outline btn-icon btn-sm">
              <span className="material-icons">view_column</span>
              Столбцы
            </button>
            <button className="btn btn-outline btn-icon btn-sm">
              <span className="material-icons">file_download</span>
              Экспорт
            </button>
          </div>
          <div className="pagination">
            <div className="page-item disabled">
              <span className="material-icons">chevron_left</span>
            </div>
            <div className="page-item active">1</div>
            <div className="page-item">2</div>
            <div className="page-item">3</div>
            <div className="page-item">4</div>
            <div className="page-item">5</div>
            <div className="page-item">
              <span className="material-icons">chevron_right</span>
            </div>
          </div>
          <div className="pagination-right">
            <div className="per-page-selector">
              <span>Строк:</span>
              <select defaultValue="20">
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
            </div>
            <div className="results-info">
              <strong>1–20</strong> из <strong>156</strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
