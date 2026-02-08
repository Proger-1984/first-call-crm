import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { StatsCard } from '../../components/UI/StatsCard';
import { SourceBadge, DuplicateBadge, Badge } from '../../components/UI/Badge';
import { MultiSelect } from '../../components/UI/MultiSelect';
import { DatePicker } from '../../components/UI/DatePicker';
import { Tooltip } from '../../components/UI/Tooltip';
import { listingsApi, filtersApi, favoritesApi, photoTasksApi, type FilterOption } from '../../services/api';
import { useUIStore } from '../../stores/uiStore';
import './Dashboard.css';

// Типы для отображения
type SourceType = 'avito' | 'cian' | 'yandex' | 'domofond' | 'ula';

// Тип дубликата с URL для перехода
interface DuplicateInfo {
  source: SourceType;
  url: string;
}

// Запись истории изменения цены
interface PriceHistoryEntry {
  date: number;      // Unix timestamp
  price: number;     // Цена на эту дату
  diff: number;      // Изменение относительно предыдущей цены
}

interface DisplayListing {
  id: number;
  date: string;           // "27.01.2026"
  time: string;           // "01:44"
  category: string;       // "Аренда жилая (Квартиры)"
  title: string;
  description: string;    // Описание объявления
  meta: string;           // "Этаж: 4/9, 2-комн"
  source: SourceType;
  sourceId: number;       // ID источника для сортировки
  price: string;          // "45 000 ₽"
  priceRaw: number | null; // Цена числом для расчётов
  priceHistory: PriceHistoryEntry[] | null; // История изменения цен
  address: string;
  metro: string;          // "Мичуринский проспект"
  metroLine: string | null; // "Солнцевская" (название линии)
  metroTime: number | null; // 7 (минут пешком)
  metroDistance: string | null; // "900 м" или "2,7 км"
  metroColor?: string;    // "#FFCD1C" (цвет линии метро)
  phone: string;          // "+7 (999) 123-45-67"
  phoneUnavailable: boolean; // Телефон недоступен (только звонки через приложение)
  duplicates: DuplicateInfo[]; // Дубликаты с URL
  area: string;           // "52 м²"
  callStatus: string;     // Статус звонка: "Новое", "Наша квартира", и т.д.
  callStatusId: number;   // ID статуса звонка
  callStatusColor: string; // Цвет статуса звонка
  isFavorite: boolean;
  isPaid: boolean;        // Платный телефон (is_paid из API)
  isRaised: boolean;      // Поднятое объявление (listing_status_id = 2)
  url: string;            // URL объявления
  photoTask?: {           // Задача обработки фото
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    photos_count: number | null;
    error_message: string | null;
  };
}

// Конфигурация столбцов таблицы
type ColumnId = 'datetime' | 'category' | 'title' | 'source' | 'price' | 'address' | 'metro' | 'phone' | 'duplicates' | 'area' | 'status';

// Поля для сортировки на бэкенде
type SortField = 'created_at' | 'price' | 'square_meters' | 'source_id' | 'listing_status_id';

interface ColumnConfig {
  id: ColumnId;
  label: string;
  visible: boolean;
  sortable?: boolean;
  sortField?: SortField;
}

// Начальная конфигурация столбцов (порядок и видимость по умолчанию)
const defaultColumns: ColumnConfig[] = [
  { id: 'datetime', label: 'Дата и время', visible: true, sortable: true, sortField: 'created_at' },
  { id: 'category', label: 'Категория', visible: true },
  { id: 'title', label: 'Заголовок', visible: true },
  { id: 'source', label: 'Источник', visible: true, sortable: true, sortField: 'source_id' },
  { id: 'price', label: 'Цена', visible: true, sortable: true, sortField: 'price' },
  { id: 'address', label: 'Адрес', visible: true },
  { id: 'metro', label: 'Метро', visible: true },
  { id: 'phone', label: 'Контакт', visible: true },
  { id: 'duplicates', label: 'Дубли', visible: true },
  { id: 'area', label: 'Площадь', visible: true, sortable: true, sortField: 'square_meters' },
  { id: 'status', label: 'Статус', visible: true, sortable: true, sortField: 'listing_status_id' },
];

// Ключ для localStorage (версия 2 — добавлена категория и сортировка)
const COLUMNS_STORAGE_KEY = 'dashboard_columns_config_v2';

// Маппинг source_id -> код источника
const sourceIdToCode: Record<number, SourceType> = {
  1: 'avito',
  2: 'yandex',
  3: 'cian',
  4: 'ula',
};

// Маппинг call_status_id -> код статуса для CSS классов
const callStatusIdToCode: Record<number, string> = {
  0: 'new',           // Нет записи = Новое
  1: 'our_apartment', // Наша квартира
  2: 'not_answered',  // Не дозвонился
  3: 'not_picked_up', // Не снял
  4: 'agent',         // Агент
  5: 'not_first',     // Не первые
  6: 'calling',       // Звонок (в процессе)
};

// Форматирование цены
function formatPrice(price: number | null | undefined): string {
  if (!price) return '—';
  return price.toLocaleString('ru-RU') + ' ₽';
}

// Форматирование телефона
function formatPhone(phone: string | null | undefined): string {
  if (!phone) return '—';
  // Убираем всё кроме цифр
  const digits = phone.replace(/\D/g, '');
  if (digits.length === 11) {
    return `+7 (${digits.slice(1, 4)}) ${digits.slice(4, 7)}-${digits.slice(7, 9)}-${digits.slice(9)}`;
  }
  return phone;
}

// Адаптер: преобразует данные API в формат для отображения
function adaptApiListing(apiListing: any): DisplayListing {
  // Дата и время
  const createdAt = apiListing.created_at ? new Date(apiListing.created_at) : new Date();
  const date = createdAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const time = createdAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

  // Категория
  const category = apiListing.category?.name || '—';

  // Источник
  const sourceId = apiListing.source?.id || apiListing.source_id || 0;
  const source: SourceType = sourceIdToCode[sourceId] || 'cian';

  // Мета информация
  const metaParts: string[] = [];
  if (apiListing.floor && apiListing.floors_total) {
    metaParts.push(`Этаж: ${apiListing.floor}/${apiListing.floors_total}`);
  }
  if (apiListing.room?.name) {
    metaParts.push(apiListing.room.name);
  }
  const meta = metaParts.join(', ') || '—';

  // Метро (берём первую станцию из массива)
  const metroData = apiListing.metro?.[0];
  const metro = metroData?.name || '';
  const metroLine = metroData?.line || null;
  const metroTime = metroData?.travel_time_min || null;
  const metroDistance = metroData?.distance || null;
  // Добавляем # к цвету если его нет (API возвращает цвет без #)
  const rawColor = metroData?.color;
  const metroColor = rawColor ? (rawColor.startsWith('#') ? rawColor : `#${rawColor}`) : undefined;

  // Статус звонка (из call_status)
  const callStatusId = apiListing.call_status?.id ?? 0;
  const callStatus = apiListing.call_status?.name || 'Новое';
  const callStatusColor = apiListing.call_status?.color || '#9E9E9E';

  // Поднятое объявление (listing_status_id = 2)
  const listingStatusId = apiListing.listing_status?.id;
  const isRaised = listingStatusId === 2 || listingStatusId === '2';

  return {
    id: apiListing.id,
    date,
    time,
    category,
    title: apiListing.title || 'Без названия',
    description: apiListing.description || '',
    meta,
    source,
    sourceId,
    price: formatPrice(apiListing.price),
    priceRaw: apiListing.price,
    priceHistory: apiListing.price_history || null,
    address: apiListing.address || '—',
    metro,
    metroLine,
    metroTime,
    metroDistance,
    metroColor,
    phone: formatPhone(apiListing.phone),
    phoneUnavailable: apiListing.phone_unavailable || false,
    duplicates: (apiListing.duplicates || []).map((dup: { source_id: number; url: string }) => ({
      source: sourceIdToCode[dup.source_id] || 'avito',
      url: dup.url || '',
    })),
    area: apiListing.square_meters ? `${apiListing.square_meters} м²` : '—',
    callStatus,
    callStatusId,
    callStatusColor,
    isFavorite: apiListing.is_favorite || false,
    isPaid: apiListing.is_paid || false, // Платный телефон
    isRaised, // Поднятое объявление (listing_status_id = 2)
    url: apiListing.url || '', // URL для открытия в новой вкладке
    photoTask: apiListing.photo_task || undefined, // Задача обработки фото
  };
}

// Опции фильтров теперь загружаются из API (см. filtersApi.getFilters)

// Статусы звонков (call_status) — используем для CSS классов
const callStatusVariants: Record<number, string> = {
  0: 'new',           // Серый — Новое
  1: 'success',       // Зелёный — Наша квартира
  2: 'warning',       // Жёлтый — Не дозвонился
  3: 'danger',        // Оранжевый — Не снял
  4: 'agent',         // Красный — Агент
  5: 'not-first',     // Фиолетовый — Не первые
  6: 'calling',       // Светло-зелёный — Звонок (в процессе)
};

export function Dashboard() {
  const [filtersExpanded, setFiltersExpanded] = useState(false);

  // Состояние модального окна описания
  const [descriptionModal, setDescriptionModal] = useState<{ open: boolean; title: string; description: string }>({
    open: false,
    title: '',
    description: '',
  });

  // Состояние фильтров (для UI) — хранят ID выбранных элементов
  const [categoryFilter, setCategoryFilter] = useState<string[]>([]);
  const [callStatusFilter, setCallStatusFilter] = useState<string[]>([]);
  const [locationFilter, setLocationFilter] = useState<string[]>([]);
  const [metroFilter, setMetroFilter] = useState<string[]>([]);
  const [sourceFilter, setSourceFilter] = useState<string[]>([]);
  const [roomsFilter, setRoomsFilter] = useState<string[]>([]);
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [priceFrom, setPriceFrom] = useState<string>('');
  const [priceTo, setPriceTo] = useState<string>('');
  const [areaFrom, setAreaFrom] = useState<string>('');
  const [areaTo, setAreaTo] = useState<string>('');
  const [phoneFilter, setPhoneFilter] = useState<string>('');
  const [externalIdFilter, setExternalIdFilter] = useState<string>('');

  // Применённые фильтры (отправляются в API)
  const [appliedFilters, setAppliedFilters] = useState<Record<string, any>>({});

  // Загрузка данных для фильтров из API
  const selectedCategoryId = categoryFilter.length > 0 ? parseInt(categoryFilter[0]) : undefined;
  const selectedLocationIds = locationFilter.length > 0 ? locationFilter.map(id => parseInt(id)) : undefined;

  const { data: filtersResponse } = useQuery({
    queryKey: ['filters', selectedCategoryId, selectedLocationIds],
    queryFn: () => filtersApi.getFilters(selectedCategoryId, selectedLocationIds),
    staleTime: 60000, // Кешируем на 1 минуту
    placeholderData: (previousData) => previousData, // Сохраняем предыдущие данные во время загрузки
  });

  const filtersData = filtersResponse?.data?.data;

  // Преобразуем данные API в формат для MultiSelect
  const categoryOptions = useMemo(() => 
    filtersData?.categories?.map((c: FilterOption) => ({ value: c.id.toString(), label: c.name })) || [],
    [filtersData?.categories]
  );

  const locationOptions = useMemo(() => 
    filtersData?.locations?.map((l: FilterOption) => ({ value: l.id.toString(), label: l.name })) || [],
    [filtersData?.locations]
  );

  const metroOptions = useMemo(() => 
    filtersData?.metro?.map((m: FilterOption & { line?: string }) => ({ 
      value: m.id.toString(), 
      label: m.name,
      color: m.color,
      sublabel: m.line, // Название линии метро
    })) || [],
    [filtersData?.metro]
  );

  const roomsOptions = useMemo(() => 
    filtersData?.rooms?.map((r: FilterOption) => ({ value: r.id.toString(), label: r.name })) || [],
    [filtersData?.rooms]
  );

  const sourceOptions = useMemo(() => 
    filtersData?.sources?.map((s: FilterOption) => ({ value: s.id.toString(), label: s.name })) || [],
    [filtersData?.sources]
  );

  const callStatusOptions = useMemo(() => 
    filtersData?.call_statuses?.map((s: FilterOption) => ({ value: s.id.toString(), label: s.name })) || [],
    [filtersData?.call_statuses]
  );

  // При смене категории сбрасываем локацию, метро и комнаты
  const prevCategoryRef = useRef<string[]>([]);
  useEffect(() => {
    // Проверяем, изменилась ли категория (а не просто загрузились данные)
    if (JSON.stringify(prevCategoryRef.current) !== JSON.stringify(categoryFilter)) {
      prevCategoryRef.current = categoryFilter;
      
      if (categoryFilter.length > 0) {
        // Сбрасываем зависимые фильтры при смене категории
        setLocationFilter([]);
        setMetroFilter([]);
        setRoomsFilter([]);
      }
    }
  }, [categoryFilter]);

  // При смене локации сбрасываем метро
  const prevLocationRef = useRef<string[]>([]);
  useEffect(() => {
    if (JSON.stringify(prevLocationRef.current) !== JSON.stringify(locationFilter)) {
      prevLocationRef.current = locationFilter;
      
      if (locationFilter.length > 0) {
        // Сбрасываем метро при смене локации
        setMetroFilter([]);
      }
    }
  }, [locationFilter]);

  // Состояние пагинации
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(10); // По умолчанию 10 записей

  // Состояние сортировки (добавлены source_id и status_id)
  const [sortField, setSortField] = useState<SortField>('created_at');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

  // Состояние столбцов (загружаем из localStorage или используем дефолтные)
  const [columns, setColumns] = useState<ColumnConfig[]>(() => {
    const saved = localStorage.getItem(COLUMNS_STORAGE_KEY);
    if (saved) {
      try {
        return JSON.parse(saved);
      } catch {
        return defaultColumns;
      }
    }
    return defaultColumns;
  });
  const [columnsModalOpen, setColumnsModalOpen] = useState(false);
  const [draggedColumnIndex, setDraggedColumnIndex] = useState<number | null>(null);

  // Сохраняем конфигурацию столбцов в localStorage при изменении
  useEffect(() => {
    localStorage.setItem(COLUMNS_STORAGE_KEY, JSON.stringify(columns));
  }, [columns]);

  // Загрузка данных с API
  const { data: apiResponse, isLoading, isError, error } = useQuery({
    queryKey: ['listings', { page: currentPage, perPage, sortField, sortOrder, ...appliedFilters }],
    queryFn: () => listingsApi.getAll({ 
      page: currentPage, 
      per_page: perPage,
      sort: sortField,
      order: sortOrder,
      ...appliedFilters,
    }),
    staleTime: 0,                    // Не кешируем — всегда делаем новый запрос
    gcTime: 0,                       // Не храним в кеше
    refetchInterval: 3000,           // Polling каждые 3 секунды
    refetchOnWindowFocus: false,     // Не обновлять при фокусе на окно
    refetchOnMount: false,           // Не обновлять при монтировании
    refetchOnReconnect: false,       // Не обновлять при восстановлении соединения
  });

  // Query client для инвалидации кеша
  const queryClient = useQueryClient();

  // Мутация для toggle избранного
  const toggleFavoriteMutation = useMutation({
    mutationFn: (listingId: number) => favoritesApi.toggle(listingId),
    onSuccess: () => {
      // Инвалидируем кеш listings чтобы обновить флаг is_favorite
      queryClient.invalidateQueries({ queryKey: ['listings'] });
      // Также инвалидируем кеш избранного
      queryClient.invalidateQueries({ queryKey: ['favorites'] });
    },
  });

  // Обработчик клика на избранное
  const handleToggleFavorite = useCallback((listingId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    toggleFavoriteMutation.mutate(listingId);
  }, [toggleFavoriteMutation]);

  // Мутация для создания задачи обработки фото
  const createPhotoTaskMutation = useMutation({
    mutationFn: (listingId: number) => photoTasksApi.create(listingId),
    onSuccess: () => {
      // Обновляем список объявлений чтобы получить актуальный статус задачи
      queryClient.invalidateQueries({ queryKey: ['listings'] });
    },
  });

  // Обработчик клика на обработку фото
  const handlePhotoTask = useCallback((listing: DisplayListing, e: React.MouseEvent) => {
    e.stopPropagation();
    e.preventDefault();
    const task = listing.photoTask;
    
    // Если задача уже завершена — скачиваем архив
    if (task?.status === 'completed') {
      const token = localStorage.getItem('access_token');
      const downloadUrl = `${photoTasksApi.getDownloadUrl(task.id)}?token=${token}`;
      
      // Скачиваем через скрытую ссылку без перезагрузки страницы
      const link = document.createElement('a');
      link.href = downloadUrl;
      link.download = `photos_${listing.id}.zip`;
      link.style.display = 'none';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      return;
    }
    
    // Если задача в процессе — ничего не делаем
    if (task?.status === 'pending' || task?.status === 'processing') {
      return;
    }
    
    // Создаём новую задачу
    createPhotoTaskMutation.mutate(listing.id);
  }, [createPhotoTaskMutation]);

  // Получить иконку и тултип для кнопки обработки фото
  const getPhotoTaskInfo = useCallback((listing: DisplayListing): { icon: string; tooltip: string; className: string } => {
    const task = listing.photoTask;
    
    if (!task) {
      return { icon: 'photo_library', tooltip: 'Обработать фото (удалить водяные знаки)', className: '' };
    }
    
    switch (task.status) {
      case 'pending':
        return { icon: 'hourglass_empty', tooltip: 'Ожидает обработки...', className: 'pending' };
      case 'processing':
        return { icon: 'sync', tooltip: 'Обрабатывается...', className: 'processing' };
      case 'completed':
        return { icon: 'download', tooltip: `Скачать фото (${task.photos_count} шт.)`, className: 'completed' };
      case 'failed':
        return { icon: 'error_outline', tooltip: task.error_message || 'Ошибка обработки', className: 'failed' };
      default:
        return { icon: 'photo_library', tooltip: 'Обработать фото', className: '' };
    }
  }, []);

  // Применить фильтры
  const handleApplyFilters = useCallback(() => {
    const filters: Record<string, any> = {};
    
    // Даты (преобразуем из dd.mm.yyyy в yyyy-mm-dd для API)
    if (dateFrom) {
      const parts = dateFrom.split('.');
      if (parts.length === 3) {
        filters.date_from = `${parts[2]}-${parts[1]}-${parts[0]}`;
      }
    }
    if (dateTo) {
      const parts = dateTo.split('.');
      if (parts.length === 3) {
        filters.date_to = `${parts[2]}-${parts[1]}-${parts[0]}`;
      }
    }
    
    // Цена
    if (priceFrom) filters.price_from = parseFloat(priceFrom);
    if (priceTo) filters.price_to = parseFloat(priceTo);
    
    // Площадь
    if (areaFrom) filters.area_from = parseFloat(areaFrom);
    if (areaTo) filters.area_to = parseFloat(areaTo);
    
    // Категория (всегда массив)
    if (categoryFilter.length > 0) {
      filters.category_id = categoryFilter.map(id => parseInt(id));
    }
    
    // Локация (всегда массив)
    if (locationFilter.length > 0) {
      filters.location_id = locationFilter.map(id => parseInt(id));
    }
    
    // Источник (всегда массив)
    if (sourceFilter.length > 0) {
      filters.source_id = sourceFilter.map(id => parseInt(id));
    }
    
    // Комнаты (всегда массив)
    if (roomsFilter.length > 0) {
      filters.room_id = roomsFilter.map(id => parseInt(id));
    }
    
    // Метро (всегда массив)
    if (metroFilter.length > 0) {
      filters.metro_id = metroFilter.map(id => parseInt(id));
    }
    
    // Статус звонка (всегда массив)
    if (callStatusFilter.length > 0) {
      filters.call_status_id = callStatusFilter.map(id => parseInt(id));
    }
    
    // Телефон
    if (phoneFilter) {
      filters.phone = phoneFilter;
    }
    
    // Номер объявления
    if (externalIdFilter) {
      filters.external_id = externalIdFilter;
    }
    
    setAppliedFilters(filters);
    setCurrentPage(1); // Сбрасываем на первую страницу
  }, [dateFrom, dateTo, priceFrom, priceTo, areaFrom, areaTo, categoryFilter, locationFilter, sourceFilter, roomsFilter, metroFilter, callStatusFilter, phoneFilter, externalIdFilter]);

  // Сбросить все фильтры
  const handleResetFilters = useCallback(() => {
    setCategoryFilter([]);
    setCallStatusFilter([]);
    setLocationFilter([]);
    setMetroFilter([]);
    setSourceFilter([]);
    setRoomsFilter([]);
    setDateFrom('');
    setDateTo('');
    setPriceFrom('');
    setPriceTo('');
    setAreaFrom('');
    setAreaTo('');
    setPhoneFilter('');
    setExternalIdFilter('');
    setAppliedFilters({});
    setCurrentPage(1);
  }, []);

  // Сброс отдельного фильтра
  const handleResetFilter = useCallback((filterName: string) => {
    switch (filterName) {
      case 'category':
        setCategoryFilter([]);
        setLocationFilter([]); // Сбрасываем зависимые
        setMetroFilter([]);
        setRoomsFilter([]);
        break;
      case 'location':
        setLocationFilter([]);
        setMetroFilter([]); // Сбрасываем зависимые
        break;
      case 'metro':
        setMetroFilter([]);
        break;
      case 'source':
        setSourceFilter([]);
        break;
      case 'rooms':
        setRoomsFilter([]);
        break;
      case 'callStatus':
        setCallStatusFilter([]);
        break;
      case 'date':
        setDateFrom('');
        setDateTo('');
        break;
      case 'price':
        setPriceFrom('');
        setPriceTo('');
        break;
      case 'area':
        setAreaFrom('');
        setAreaTo('');
        break;
      case 'phone':
        setPhoneFilter('');
        break;
      case 'externalId':
        setExternalIdFilter('');
        break;
    }
  }, []);

  // Извлекаем listings, пагинацию и статистику из ответа API (AxiosResponse -> ApiResponse -> PaginatedResponse)
  const apiListings = apiResponse?.data?.data?.listings;
  const pagination = apiResponse?.data?.data?.pagination;
  const stats = apiResponse?.data?.data?.stats;
  const totalPages = pagination?.total_pages || 1;
  const totalItems = pagination?.total || 0;

  // Адаптируем данные API к формату отображения
  const displayListings: DisplayListing[] = useMemo(() => {
    if (apiListings && apiListings.length > 0) {
      return apiListings.map(adaptApiListing);
    }
    return []; // Пустой массив если нет данных
  }, [apiListings]);

  // Звуковые уведомления о новых объявлениях
  // Играем звук только если появились объявления НОВЕЕ чем самое свежее из предыдущего запроса
  const { playNotificationSound } = useUIStore();
  const newestCreatedAtRef = useRef<string | null>(null);
  const isFirstLoadRef = useRef(true);

  useEffect(() => {
    if (!apiListings || apiListings.length === 0) return;

    // Находим самую свежую дату создания в текущем списке
    const currentNewest = apiListings.reduce((newest: string | null, listing: any) => {
      if (!listing.created_at) return newest;
      if (!newest) return listing.created_at;
      return listing.created_at > newest ? listing.created_at : newest;
    }, null as string | null);

    // Пропускаем первую загрузку (не играем звук при открытии страницы)
    if (isFirstLoadRef.current) {
      isFirstLoadRef.current = false;
      newestCreatedAtRef.current = currentNewest;
      return;
    }

    // Проверяем есть ли объявления новее чем предыдущее самое свежее
    if (newestCreatedAtRef.current && currentNewest && currentNewest > newestCreatedAtRef.current) {
      playNotificationSound();
    }

    // Обновляем самую свежую дату (только если она новее)
    if (currentNewest && (!newestCreatedAtRef.current || currentNewest > newestCreatedAtRef.current)) {
      newestCreatedAtRef.current = currentNewest;
    }
  }, [apiListings, playNotificationSound]);

  // Логируем ошибки API
  useEffect(() => {
    if (isError) {
      console.error('API Error:', error);
    }
  }, [isError, error]);

  // Обработчик сортировки (поддерживает все поля)
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      // Переключаем направление
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
    } else {
      // Новое поле — сортируем по убыванию
      setSortField(field);
      setSortOrder('desc');
    }
    setCurrentPage(1); // Сбрасываем на первую страницу
  };

  // Обработчик смены страницы
  const handlePageChange = (page: number) => {
    if (page >= 1 && page <= totalPages) {
      setCurrentPage(page);
    }
  };

  // Обработчик смены количества записей на странице
  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
    setCurrentPage(1); // Сбрасываем на первую страницу
  };

  // Генерация номеров страниц для отображения
  const getPageNumbers = (): (number | 'ellipsis')[] => {
    const pages: (number | 'ellipsis')[] = [];
    const maxVisiblePages = 5;
    
    if (totalPages <= maxVisiblePages) {
      for (let i = 1; i <= totalPages; i++) {
        pages.push(i);
      }
    } else {
      // Всегда показываем первую страницу
      pages.push(1);
      
      if (currentPage > 3) {
        pages.push('ellipsis');
      }
      
      // Страницы вокруг текущей
      const start = Math.max(2, currentPage - 1);
      const end = Math.min(totalPages - 1, currentPage + 1);
      
      for (let i = start; i <= end; i++) {
        pages.push(i);
      }
      
      if (currentPage < totalPages - 2) {
        pages.push('ellipsis');
      }
      
      // Всегда показываем последнюю страницу
      pages.push(totalPages);
    }
    
    return pages;
  };

  // Вычисление диапазона отображаемых записей
  const startRecord = (currentPage - 1) * perPage + 1;
  const endRecord = Math.min(currentPage * perPage, totalItems);

  // Переключение видимости столбца
  const toggleColumnVisibility = (columnId: ColumnId) => {
    setColumns(prev => prev.map(col => 
      col.id === columnId ? { ...col, visible: !col.visible } : col
    ));
  };

  // Drag & Drop для изменения порядка столбцов
  const handleDragStart = (index: number) => {
    setDraggedColumnIndex(index);
  };

  const handleDragOver = (e: React.DragEvent, index: number) => {
    e.preventDefault();
    if (draggedColumnIndex === null || draggedColumnIndex === index) return;
    
    const newColumns = [...columns];
    const draggedColumn = newColumns[draggedColumnIndex];
    newColumns.splice(draggedColumnIndex, 1);
    newColumns.splice(index, 0, draggedColumn);
    
    setColumns(newColumns);
    setDraggedColumnIndex(index);
  };

  const handleDragEnd = () => {
    setDraggedColumnIndex(null);
  };

  // Сброс настроек столбцов к дефолтным
  const resetColumns = () => {
    setColumns(defaultColumns);
  };

  // Получаем видимые столбцы в правильном порядке
  const visibleColumns = columns.filter(col => col.visible);

  // Функция рендеринга ячейки по ID столбца
  const renderCell = (columnId: ColumnId, listing: DisplayListing) => {
    switch (columnId) {
      case 'datetime':
        return (
          <td key={columnId}>
            <div className="date-cell">
              <div className="date">{listing.date}</div>
              <div className="time">{listing.time}</div>
            </div>
          </td>
        );
      case 'category':
        return (
          <td key={columnId} className="category-cell">
            {listing.category}
          </td>
        );
      case 'title':
        return (
          <td key={columnId}>
            <div className="listing-preview">
              <div className="listing-title">
                <h4>{listing.title}</h4>
              </div>
              <div className="listing-actions">
                {/* Кнопка обработки фото */}
                {(() => {
                  const photoInfo = getPhotoTaskInfo(listing);
                  return (
                    <Tooltip content={photoInfo.tooltip} position="top">
                      <div
                        className={`listing-action photo-task ${photoInfo.className}`}
                        onClick={(e) => handlePhotoTask(listing, e)}
                      >
                        <span className={`material-icons ${photoInfo.className === 'processing' ? 'spin' : ''}`}>
                          {photoInfo.icon}
                        </span>
                      </div>
                    </Tooltip>
                  );
                })()}
                {listing.description && (
                  <Tooltip content="Показать описание" position="top">
                    <div
                      className="listing-action"
                      onClick={(e) => {
                        e.stopPropagation();
                        setDescriptionModal({
                          open: true,
                          title: listing.title,
                          description: listing.description,
                        });
                      }}
                    >
                      <span className="material-icons">description</span>
                    </div>
                  </Tooltip>
                )}
                {listing.url && (
                  <Tooltip content="Открыть объявление" position="top">
                    <a 
                      href={listing.url} 
                      target="_blank" 
                      rel="noopener noreferrer"
                      className="listing-action"
                    >
                      <span className="material-icons">open_in_new</span>
                    </a>
                  </Tooltip>
                )}
                <Tooltip content={listing.isFavorite ? 'Удалить из избранного' : 'Добавить в избранное'} position="top">
                  <div
                    className={`listing-action favorite ${listing.isFavorite ? 'active' : ''}`}
                    onClick={(e) => handleToggleFavorite(listing.id, e)}
                  >
                    <span className="material-icons">
                      {listing.isFavorite ? 'favorite' : 'favorite_border'}
                    </span>
                  </div>
                </Tooltip>
              </div>
            </div>
          </td>
        );
      case 'source':
        return (
          <td key={columnId}>
            <SourceBadge source={listing.source} />
          </td>
        );
      case 'price':
        // Проверяем, есть ли история изменения цен (более 1 записи)
        const hasPriceHistory = listing.priceHistory && listing.priceHistory.length > 1;
        // Последнее изменение цены (первый элемент массива — самое свежее)
        const lastPriceChange = hasPriceHistory ? listing.priceHistory![0] : null;
        const priceChangeDirection = lastPriceChange?.diff ? (lastPriceChange.diff > 0 ? 'up' : 'down') : null;
        
        // Контент тултипа с историей цен
        const priceHistoryTooltip = hasPriceHistory ? (
          <div className="price-history-tooltip">
            <div className="price-history-tooltip-title">История изменений цены</div>
            {listing.priceHistory!.map((h, idx) => {
              const date = new Date(h.date * 1000).toLocaleDateString('ru-RU');
              const diffClass = h.diff > 0 ? 'up' : h.diff < 0 ? 'down' : 'neutral';
              return (
                <div key={idx} className="price-history-item">
                  <span className="price-history-date">{date}</span>
                  <div className="price-history-value">
                    <span className="price-history-price">{h.price.toLocaleString('ru-RU')} ₽</span>
                    {h.diff !== 0 && (
                      <span className={`price-history-diff ${diffClass}`}>
                        {h.diff > 0 ? '+' : ''}{h.diff.toLocaleString('ru-RU')}
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        ) : null;
        
        return (
          <td key={columnId} className="price-cell">
            <div className="price-wrapper">
              <strong>{listing.price}</strong>
              {hasPriceHistory && lastPriceChange && priceHistoryTooltip && (
                <Tooltip content={priceHistoryTooltip} position="left" maxWidth={280}>
                  <div className={`price-change ${priceChangeDirection}`}>
                    <span className="material-icons">
                      {priceChangeDirection === 'up' ? 'trending_up' : 'trending_down'}
                    </span>
                    <span className="price-diff">
                      {lastPriceChange.diff > 0 ? '+' : ''}{lastPriceChange.diff.toLocaleString('ru-RU')}
                    </span>
                  </div>
                </Tooltip>
              )}
            </div>
          </td>
        );
      case 'address':
        return <td key={columnId} className="address-cell">{listing.address}</td>;
      case 'metro':
        return (
          <td key={columnId} className="metro-cell">
            {listing.metro ? (
              <div className="metro-info">
                <span
                  className="metro-line-dot"
                  style={{
                    backgroundColor: listing.metroColor || '#999'
                  }}
                />
                <div className="metro-text">
                  <span className="metro-name">{listing.metro}</span>
                  {listing.metroLine && <span className="metro-line">{listing.metroLine}</span>}
                  <span className="metro-details">
                    {listing.metroDistance && <span className="metro-distance">{listing.metroDistance}</span>}
                    {listing.metroTime && <span className="metro-time">{listing.metroTime} мин</span>}
                  </span>
                </div>
              </div>
            ) : '—'}
          </td>
        );
      case 'phone':
        return (
          <td key={columnId} className="phone-cell">
            {listing.isPaid && (
              <span className="phone-paid-label">Платное</span>
            )}
            {listing.phoneUnavailable ? (
              <span className="phone-unavailable">Без звонков</span>
            ) : listing.phone !== '—' ? (
              <a href={`tel:${listing.phone}`} className="phone-link">
                {listing.phone}
              </a>
            ) : (
              <span className="phone-empty">***</span>
            )}
          </td>
        );
      case 'duplicates':
        return (
          <td key={columnId}>
            {listing.duplicates.length > 0 ? (
              <div className="duplicates-list">
                {listing.duplicates.map((dup, idx) => (
                  <a 
                    key={`${dup.source}-${idx}`}
                    href={dup.url} 
                    target="_blank" 
                    rel="noopener noreferrer"
                    title={`Открыть дубликат на ${dup.source === 'cian' ? 'ЦИАН' : dup.source === 'avito' ? 'Авито' : dup.source === 'yandex' ? 'Яндекс' : dup.source}`}
                  >
                    <DuplicateBadge source={dup.source} />
                  </a>
                ))}
              </div>
            ) : '—'}
          </td>
        );
      case 'area':
        return <td key={columnId} className="area-cell">{listing.area}</td>;
      case 'status':
        return (
          <td key={columnId}>
            <div className="status-cell">
              <Badge variant={callStatusVariants[listing.callStatusId] as any || 'new'}>
                {listing.callStatus}
              </Badge>
              <div className="call-action-btn" title="Позвонить">
                <span className="material-icons">call</span>
              </div>
            </div>
          </td>
        );
      default:
        return <td key={columnId}>—</td>;
    }
  };

  return (
    <div className="dashboard">
      {/* Модальное окно настройки столбцов */}
      {columnsModalOpen && (
        <div className="modal-overlay" onClick={() => setColumnsModalOpen(false)}>
          <div className="modal columns-modal" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Настройка столбцов</h3>
              <button className="modal-close" onClick={() => setColumnsModalOpen(false)}>
                <span className="material-icons">close</span>
              </button>
            </div>
            <div className="modal-body">
              <p className="modal-hint">Перетащите столбцы для изменения порядка. Снимите галочку чтобы скрыть столбец.</p>
              <div className="columns-list">
                {columns.map((column, index) => (
                  <div
                    key={column.id}
                    className={`column-item ${draggedColumnIndex === index ? 'dragging' : ''}`}
                    draggable
                    onDragStart={() => handleDragStart(index)}
                    onDragOver={(e) => handleDragOver(e, index)}
                    onDragEnd={handleDragEnd}
                  >
                    <span className="drag-handle material-icons">drag_indicator</span>
                    <label className="column-checkbox">
                      <input
                        type="checkbox"
                        checked={column.visible}
                        onChange={() => toggleColumnVisibility(column.id)}
                      />
                      <span className="checkmark"></span>
                      {column.label}
                    </label>
                  </div>
                ))}
              </div>
            </div>
            <div className="modal-footer">
              <button className="btn btn-outline" onClick={resetColumns}>
                Сбросить
              </button>
              <button className="btn btn-primary" onClick={() => setColumnsModalOpen(false)}>
                Готово
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Модальное окно описания */}
      {descriptionModal.open && (
        <div className="modal-overlay" onClick={() => setDescriptionModal({ open: false, title: '', description: '' })}>
          <div className="modal description-modal" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{descriptionModal.title}</h3>
              <button className="modal-close" onClick={() => setDescriptionModal({ open: false, title: '', description: '' })}>
                <span className="material-icons">close</span>
              </button>
            </div>
            <div className="modal-body">
              <div className="description-content">
                {descriptionModal.description.split('\n').map((line, idx) => (
                  <p key={idx}>{line || '\u00A0'}</p>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Компактная статистика */}
      <div className="compact-stats">
        <div className="compact-stat">
          <div className="compact-stat-icon featured">
            <span className="material-icons">dashboard</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-row">
              <div className="compact-stat-value">{stats?.total?.toLocaleString('ru-RU') || '—'}</div>
              {stats?.trends?.total !== undefined && stats.trends.total !== 0 && (
                <div className={`compact-stat-trend ${stats.trends.total > 0 ? 'up' : 'down'}`}>
                  <span className="material-icons">{stats.trends.total > 0 ? 'trending_up' : 'trending_down'}</span>
                  <span>{stats.trends.total > 0 ? '+' : ''}{stats.trends.total}%</span>
                </div>
              )}
            </div>
            <div className="compact-stat-title">Всего</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon success">
            <span className="material-icons">check_circle</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-row">
              <div className="compact-stat-value">{stats?.our_apartments?.toLocaleString('ru-RU') || '—'}</div>
              {stats?.trends?.our_apartments !== undefined && stats.trends.our_apartments !== 0 && (
                <div className={`compact-stat-trend ${stats.trends.our_apartments > 0 ? 'up' : 'down'}`}>
                  <span className="material-icons">{stats.trends.our_apartments > 0 ? 'trending_up' : 'trending_down'}</span>
                  <span>{stats.trends.our_apartments > 0 ? '+' : ''}{stats.trends.our_apartments}%</span>
                </div>
              )}
            </div>
            <div className="compact-stat-title">Наших</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon warning">
            <span className="material-icons">phone_missed</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-row">
              <div className="compact-stat-value">{stats?.not_picked_up?.toLocaleString('ru-RU') || '—'}</div>
              {stats?.trends?.not_picked_up !== undefined && stats.trends.not_picked_up !== 0 && (
                <div className={`compact-stat-trend ${stats.trends.not_picked_up > 0 ? 'up' : 'down'}`}>
                  <span className="material-icons">{stats.trends.not_picked_up > 0 ? 'trending_up' : 'trending_down'}</span>
                  <span>{stats.trends.not_picked_up > 0 ? '+' : ''}{stats.trends.not_picked_up}%</span>
                </div>
              )}
            </div>
            <div className="compact-stat-title">Не снял</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon info">
            <span className="material-icons">groups</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-row">
              <div className="compact-stat-value">{stats?.not_first?.toLocaleString('ru-RU') || '—'}</div>
              {stats?.trends?.not_first !== undefined && stats.trends.not_first !== 0 && (
                <div className={`compact-stat-trend ${stats.trends.not_first > 0 ? 'up' : 'down'}`}>
                  <span className="material-icons">{stats.trends.not_first > 0 ? 'trending_up' : 'trending_down'}</span>
                  <span>{stats.trends.not_first > 0 ? '+' : ''}{stats.trends.not_first}%</span>
                </div>
              )}
            </div>
            <div className="compact-stat-title">Не первые</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon danger">
            <span className="material-icons">phone_disabled</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-row">
              <div className="compact-stat-value">{stats?.not_answered?.toLocaleString('ru-RU') || '—'}</div>
              {stats?.trends?.not_answered !== undefined && stats.trends.not_answered !== 0 && (
                <div className={`compact-stat-trend ${stats.trends.not_answered > 0 ? 'up' : 'down'}`}>
                  <span className="material-icons">{stats.trends.not_answered > 0 ? 'trending_up' : 'trending_down'}</span>
                  <span>{stats.trends.not_answered > 0 ? '+' : ''}{stats.trends.not_answered}%</span>
                </div>
              )}
            </div>
            <div className="compact-stat-title">Не дозвон.</div>
          </div>
        </div>
        <div className="compact-stat">
          <div className="compact-stat-icon featured">
            <span className="material-icons">percent</span>
          </div>
          <div className="compact-stat-content">
            <div className="compact-stat-row">
              <div className="compact-stat-value">{stats?.conversion !== undefined ? `${stats.conversion}%` : '—'}</div>
              {stats?.trends?.conversion !== undefined && stats.trends.conversion !== 0 && (
                <div className={`compact-stat-trend ${stats.trends.conversion > 0 ? 'up' : 'down'}`}>
                  <span className="material-icons">{stats.trends.conversion > 0 ? 'trending_up' : 'trending_down'}</span>
                  <span>{stats.trends.conversion > 0 ? '+' : ''}{stats.trends.conversion}п.п.</span>
                </div>
              )}
            </div>
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
          <div className="filter-header-actions">
            <button 
              className="filter-toggle-btn"
              onClick={() => setColumnsModalOpen(true)}
              title="Настройка столбцов"
            >
              <span className="material-icons">view_column</span>
              <span>Столбцы</span>
            </button>
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
        </div>
        {filtersExpanded && (
          <div className="card-body">
            <div className="filters-grid">
              {/* Дата */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Дата</label>
                  {(dateFrom || dateTo) && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('date')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <div className="filter-row">
                  <DatePicker placeholder="От" value={dateFrom} onChange={setDateFrom} />
                  <DatePicker placeholder="До" value={dateTo} onChange={setDateTo} />
                </div>
              </div>
              {/* Категория */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Категория</label>
                  {categoryFilter.length > 0 && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('category')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <MultiSelect
                  options={categoryOptions}
                  placeholder="Выберите категорию"
                  value={categoryFilter}
                  onChange={setCategoryFilter}
                  maxDisplayItems={1}
                />
              </div>
              {/* Локация */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Локация</label>
                  {locationFilter.length > 0 && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('location')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <MultiSelect
                  options={locationOptions}
                  placeholder="Выберите локацию"
                  value={locationFilter}
                  onChange={setLocationFilter}
                  maxDisplayItems={1}
                />
              </div>
              {/* Метро */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Метро</label>
                  {metroFilter.length > 0 && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('metro')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <MultiSelect
                  options={metroOptions}
                  placeholder="Выберите станцию"
                  value={metroFilter}
                  onChange={setMetroFilter}
                  searchable={true}
                  maxDisplayItems={1}
                />
              </div>
              {/* Источник */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Источник</label>
                  {sourceFilter.length > 0 && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('source')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <MultiSelect
                  options={sourceOptions}
                  placeholder="Выберите источник"
                  value={sourceFilter}
                  onChange={setSourceFilter}
                  maxDisplayItems={1}
                />
              </div>
              {/* Статус звонка */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Статус</label>
                  {callStatusFilter.length > 0 && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('callStatus')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <MultiSelect
                  options={callStatusOptions}
                  placeholder="Выберите статус"
                  value={callStatusFilter}
                  onChange={setCallStatusFilter}
                  maxDisplayItems={1}
                />
              </div>
              {/* Цена */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Цена, ₽</label>
                  {(priceFrom || priceTo) && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('price')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <div className="filter-row">
                  <input 
                    type="number" 
                    className="form-control" 
                    placeholder="От" 
                    value={priceFrom}
                    onChange={(e) => setPriceFrom(e.target.value)}
                  />
                  <input 
                    type="number" 
                    className="form-control" 
                    placeholder="До" 
                    value={priceTo}
                    onChange={(e) => setPriceTo(e.target.value)}
                  />
                </div>
              </div>
              {/* Площадь */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Площадь, м²</label>
                  {(areaFrom || areaTo) && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('area')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <div className="filter-row">
                  <input 
                    type="number" 
                    className="form-control" 
                    placeholder="От" 
                    value={areaFrom}
                    onChange={(e) => setAreaFrom(e.target.value)}
                  />
                  <input 
                    type="number" 
                    className="form-control" 
                    placeholder="До" 
                    value={areaTo}
                    onChange={(e) => setAreaTo(e.target.value)}
                  />
                </div>
              </div>
              {/* Комнат */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Комнат</label>
                  {roomsFilter.length > 0 && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('rooms')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <MultiSelect
                  options={roomsOptions}
                  placeholder="Любое"
                  value={roomsFilter}
                  onChange={setRoomsFilter}
                />
              </div>
              {/* Телефон */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">Телефон</label>
                  {phoneFilter && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('phone')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <input 
                  type="text" 
                  inputMode="numeric"
                  className="form-control" 
                  placeholder="Введите цифры телефона" 
                  value={phoneFilter}
                  onChange={(e) => {
                    // Оставляем только цифры
                    const digits = e.target.value.replace(/\D/g, '');
                    setPhoneFilter(digits);
                  }}
                />
              </div>
              {/* № объявления */}
              <div className="filter-group">
                <div className="filter-label-row">
                  <label className="filter-label">№ объявления</label>
                  {externalIdFilter && (
                    <button className="filter-reset-btn" onClick={() => handleResetFilter('externalId')} title="Сбросить">
                      <span className="material-icons">close</span>
                    </button>
                  )}
                </div>
                <input 
                  type="text" 
                  inputMode="numeric"
                  className="form-control" 
                  placeholder="Введите номер" 
                  value={externalIdFilter}
                  onChange={(e) => {
                    // Оставляем только цифры
                    const digits = e.target.value.replace(/\D/g, '');
                    setExternalIdFilter(digits);
                  }}
                />
              </div>
            </div>
            <div className="filter-actions">
              <button className="btn btn-outline" onClick={handleResetFilters}>
                <span className="material-icons">refresh</span>
                Сбросить
              </button>
              <button className="btn btn-primary" onClick={handleApplyFilters}>
                <span className="material-icons">check</span>
                Применить
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Таблица объявлений */}
      <div className="card table-card density-compact">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                {visibleColumns.map(column => (
                  <th 
                    key={column.id}
                    className={column.sortable ? `sortable ${column.sortField && sortField === column.sortField ? 'sorted' : ''}` : ''}
                    onClick={column.sortable && column.sortField ? () => handleSort(column.sortField!) : undefined}
                  >
                    {column.label}
                    {column.sortable && (
                      <span className="material-icons sort-icon">
                        {column.sortField && sortField === column.sortField 
                          ? (sortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward') 
                          : 'unfold_more'}
                      </span>
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {displayListings.map((listing) => (
                <tr key={listing.id} className={listing.isRaised ? 'row-raised' : ''}>
                  {visibleColumns.map(column => renderCell(column.id, listing))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="pagination-container">
          <div className="pagination">
            <div 
              className={`page-item ${currentPage === 1 ? 'disabled' : ''}`}
              onClick={() => handlePageChange(currentPage - 1)}
            >
              <span className="material-icons">chevron_left</span>
            </div>
            {getPageNumbers().map((page, index) => (
              page === 'ellipsis' ? (
                <div key={`ellipsis-${index}`} className="page-item ellipsis">...</div>
              ) : (
                <div 
                  key={page}
                  className={`page-item ${currentPage === page ? 'active' : ''}`}
                  onClick={() => handlePageChange(page)}
                >
                  {page}
                </div>
              )
            ))}
            <div 
              className={`page-item ${currentPage === totalPages ? 'disabled' : ''}`}
              onClick={() => handlePageChange(currentPage + 1)}
            >
              <span className="material-icons">chevron_right</span>
            </div>
          </div>
          <div className="pagination-right">
            <div className="per-page-selector">
              <span>Строк:</span>
              <select 
                value={perPage}
                onChange={(e) => handlePerPageChange(Number(e.target.value))}
              >
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
            </div>
            <div className="results-info">
              <strong>{startRecord}–{endRecord}</strong> из <strong>{totalItems}</strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
