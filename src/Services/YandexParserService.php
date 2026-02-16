<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Listing;
use App\Models\MetroStation;
use App\Models\Room;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Capsule\Manager as DB;
use Exception;
use Psr\Log\LoggerInterface;
use Random\RandomException;

/**
 * Сервис парсинга объявлений с Яндекс.Недвижимости
 *
 * Запрашивает мобильное API Яндекса, обрабатывает объявления,
 * сохраняет в БД и привязывает к ближайшим станциям метро.
 *
 * Ключевые особенности:
 * - Ротация кеша дедупликации (перезагрузка из БД каждые N минут)
 * - Поддержка прокси с ротацией
 * - Graceful stop через передачу &$shouldStop
 * - Reconnect БД при ошибках
 * - Счётчик последовательных ошибок с backoff
 */
class YandexParserService
{
    private const SOURCE_ID = 2;
    private const LISTING_STATUS_NEW = 1;
    private const LISTING_STATUS_RAISED = 2;

    /** Максимум последовательных ошибок до паузы */
    private const MAX_CONSECUTIVE_ERRORS = 10;

    /** Пауза при превышении лимита ошибок (секунды) */
    private const ERROR_BACKOFF_SECONDS = 30;

    /** ID коммерческих категорий в нашей БД */
    private const COMMERCIAL_CATEGORY_IDS = [2, 4];

    /** Маппинг roomsTotal → room_id в нашей БД */
    private const ROOM_MAPPING = [
        'STUDIO' => 1,
        1 => 2,
        2 => 3,
        3 => 4,
    ];
    private const ROOM_ID_PLUS_4 = 5;

    /** Маппинг commercialType → room_id (коды из таблицы rooms) */
    private const COMMERCIAL_TYPE_MAPPING = [
        'OFFICE' => 'office',
        'RETAIL' => 'retail',
        'FREE_PURPOSE' => 'free_purpose',
        'WAREHOUSE' => 'warehouse',
        'MANUFACTURING' => 'manufacturing',
        'PUBLIC_CATERING' => 'public_catering',
        'AUTO_REPAIR' => 'auto_repair',
        'HOTEL' => 'hotel',
        'BUSINESS' => 'business',
    ];

    /** Названия коммерческих типов для title */
    private const COMMERCIAL_TYPE_NAMES = [
        'OFFICE' => 'Офис',
        'RETAIL' => 'Торговое помещение',
        'FREE_PURPOSE' => 'Помещение свободного назначения',
        'WAREHOUSE' => 'Склад',
        'MANUFACTURING' => 'Производство',
        'PUBLIC_CATERING' => 'Общепит',
        'AUTO_REPAIR' => 'Автосервис',
        'HOTEL' => 'Гостиница',
        'BUSINESS' => 'Готовый бизнес',
    ];

    /** Кеш commercialType code → room_id (загружается из БД один раз) */
    private array $commercialRoomIdCache = [];

    /** Средняя скорость пешехода (км/ч) для расчёта дистанции */
    private const WALKING_SPEED_KMH = 5.0;

    /** Средняя скорость общественного транспорта (км/ч) */
    private const TRANSPORT_SPEED_KMH = 25.0;

    /** Маппинг типа транспорта из API Яндекса → нашу БД */
    private const METRO_TRANSPORT_MAPPING = [
        'ON_FOOT' => 'walk',
        'ON_TRANSPORT' => 'public_transport',
    ];

    private LoggerInterface $logger;
    private array $config;
    private Client $httpClient;

    /** @var array<string, string> Кеш существующих external_id для дедупликации */
    private array $existingItems = [];

    /** @var array<int, array{id: int, name: string, lat: float, lng: float}> Кеш станций метро */
    private array $metroStations = [];

    /** @var int Timestamp последней ротации кеша */
    private int $lastCacheRotation = 0;

    /** @var int Счётчик последовательных ошибок */
    private int $consecutiveErrors = 0;

    /**
     * @param LoggerInterface $logger Логгер
     * @param array $config Конфигурация из config/yandex.php
     */
    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->httpClient = new Client([
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
            'allow_redirects' => true,
        ]);
    }

    /**
     * Daemon-цикл парсинга для конкретной пары location+category
     *
     * @param int $locationId ID локации
     * @param array $locationConfig Конфигурация локации (rgid, bbox, name)
     * @param int $categoryId ID категории
     * @param array $categoryConfig Конфигурация категории (type, price_min, price_max)
     * @param bool $shouldStop Флаг остановки (по ссылке — меняется обработчиком SIGTERM в команде)
     * @throws RandomException
     *
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection — ссылка нужна: SIGTERM меняет значение извне
     */
    public function runLoop(
        int $locationId,
        array $locationConfig,
        int $categoryId,
        array $categoryConfig,
        bool &$shouldStop
    ): void {
        $workerName = sprintf(
            'yandex-%s-%s',
            mb_strtolower($locationConfig['name']),
            mb_strtolower($categoryConfig['api_params']['type'] ?? 'unknown')
        );

        $this->logger->info("[$workerName] Запуск воркера", [
            'location_id' => $locationId,
            'category_id' => $categoryId,
        ]);

        // Загружаем существующие объявления для дедупликации
        $this->existingItems = $this->loadExistingItems($locationId, $categoryId);
        $this->lastCacheRotation = time();
        $this->logger->info("[$workerName] Загружено существующих объявлений: " . count($this->existingItems));

        // Загружаем станции метро для привязки
        $this->metroStations = $this->loadMetroStations($locationId);
        $this->logger->info("[$workerName] Загружено станций метро: " . count($this->metroStations));

        // Daemon-цикл с проверкой флага остановки
        while (!$shouldStop) {
            // Пересобираем URL каждую итерацию (рандомизация цен для обхода кеша)
            $apiUrl = $this->buildApiUrl($locationConfig, $categoryConfig);
            // Обработка сигналов в дочернем процессе
            pcntl_signal_dispatch();

            // Ротация кеша дедупликации
            if ($this->shouldRotateCache()) {
                $this->existingItems = $this->loadExistingItems($locationId, $categoryId);
                $this->lastCacheRotation = time();
                $this->logger->info("[$workerName] Ротация кеша: " . count($this->existingItems));
            }

            // Задержка между запросами (категорийная → глобальная)
            $sleepTime = random_int(
                $categoryConfig['sleep_min_us'] ?? $this->config['sleep_min_us'],
                $categoryConfig['sleep_max_us'] ?? $this->config['sleep_max_us']
            );
            usleep($sleepTime);

            try {
                // HTTP запрос к API Яндекса (с прокси или без)
                $requestOptions = [
                    'headers' => $this->getHeaders(),
                    'debug' => false,
                ];

                if ($this->isProxyEnabled()) {
                    $requestOptions['proxy'] = $this->getRandomProxy();
                }

                $response = $this->httpClient->request('GET', $apiUrl, $requestOptions);
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);

                if ($data === null) {
                    $this->logger->warning("[$workerName] Пустой ответ API");
                    $this->consecutiveErrors++;
                    $this->handleErrorBackoff($workerName);
                    continue;
                }

                $offers = $data['response']['offers']['items'] ?? [];
                if (empty($offers)) {
                    $this->logger->info("[$workerName] Нет объявлений");
                    // Нет объявлений — это не ошибка, сбрасываем счётчик
                    $this->consecutiveErrors = 0;
                    continue;
                }

                $this->parseOffers($offers, $locationId, $categoryId, $categoryConfig, $workerName);
                // Успешный запрос — сбрасываем счётчик ошибок
                $this->consecutiveErrors = 0;

            } catch (GuzzleException $guzzleException) {
                $this->logger->error("[$workerName] Ошибка HTTP запроса", [
                    'error' => $guzzleException->getMessage(),
                ]);
                $this->consecutiveErrors++;
                $this->handleErrorBackoff($workerName);
            } catch (Exception $exception) {
                $this->logger->error("[$workerName] Непредвиденная ошибка", [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]);
                $this->consecutiveErrors++;
                $this->handleErrorBackoff($workerName);
            }
        }

        $this->logger->info("[$workerName] Воркер остановлен");
    }

    /**
     * Обработка массива объявлений из ответа API
     *
     * @param array $offers Массив объявлений из API
     * @param int $locationId ID локации
     * @param int $categoryId ID категории
     * @param array $categoryConfig Конфигурация категории
     * @param string $workerName Имя воркера для логирования
     * @throws GuzzleException
     */
    private function parseOffers(
        array $offers,
        int $locationId,
        int $categoryId,
        array $categoryConfig,
        string $workerName
    ): void {
        $filterTodayOnly = $categoryConfig['filter_today_only'] ?? false;

        foreach ($offers as $offer) {
            $offerId = $offer['offerId'] ?? null;
            if ($offerId === null) {
                continue;
            }

            // 1. Дедупликация: offerId уже в памяти — пропускаем
            if (isset($this->existingItems[$offerId])) {
                continue;
            }

            // Проверяем, поднятое ли объявление
            $isRaised = !empty($offer['raised']) || !empty($offer['promoted']);

            // 2. Фильтр по дате: только сегодняшние объявления (если включено)
            // API возвращает creationDate в UTC (суффикс Z), переводим в Moscow
            if ($filterTodayOnly) {
                $todayDate = Carbon::now('Europe/Moscow')->format('Y-m-d');
                $rawDate = $offer['creationDate'] ?? '';
                $creationDate = $rawDate !== ''
                    ? Carbon::parse($rawDate)->setTimezone('Europe/Moscow')->format('Y-m-d')
                    : '';
                if ($todayDate !== $creationDate) {
                    // Дата не изменится — помечаем чтобы не проверять повторно
                    $this->existingItems[$offerId] = $offerId;
                    continue;
                }
            }

            // 3. Маппинг в нашу структуру
            $listingData = $this->mapOfferToListing($offer, $locationId, $categoryId);
            if ($listingData === null) {
                // Маппинг провалился — помечаем чтобы не пробовать снова
                $this->existingItems[$offerId] = $offerId;
                continue;
            }

            // Поднятое новое объявление — сразу ставим статус
            if ($isRaised) {
                $listingData['listing_status_id'] = self::LISTING_STATUS_RAISED;
            }

            // 4. Ищем станцию метро (если есть metroList в ответе API)
            $nearestStation = null;
            $metroData = [];
            if (!empty($offer['location']['metroList'])) {
                $apiMetro = $offer['location']['metro'] ?? $offer['location']['metroList'][0];
                $metroName = $apiMetro['name'] ?? '';
                $metroLat = (float) ($apiMetro['latitude'] ?? 0);
                $metroLng = (float) ($apiMetro['longitude'] ?? 0);
                $nearestStation = $this->findMetroStation($metroName, $metroLat, $metroLng);
                $metroData = $this->extractMetroData($offer);
            }

            // 5. Сохраняем объявление (с reconnect при ошибке БД)
            $listing = $this->saveListing($listingData);
            if ($listing !== null) {
                // Помечаем как обработанный ПОСЛЕ успешного сохранения
                $this->existingItems[$offerId] = $offerId;

                // 6. Привязка к метро (если найдена станция)
                if ($nearestStation !== null) {
                    $this->saveMetroLink($listing, $nearestStation, $metroData);
                }

                $this->logger->info("[$workerName] Новое объявление", [
                    'offer_id' => $offerId,
                    'listing_id' => $listing->id,
                    'price' => $listingData['price'],
                    'raised' => $isRaised,
                    'metro' => $nearestStation['name'] ?? null,
                ]);

                // Для поднятых — запрашиваем историю цен из карточки
                if ($isRaised) {
                    usleep(500_000); // Задержка 500мс чтобы не превысить rate-limit API
                    $this->fetchAndSavePriceHistory($listing, $offerId, $workerName);
                }
            }
        }
    }

    /**
     * Запрашивает карточку объявления и сохраняет историю цен
     *
     * @param Listing $listing Модель объявления
     * @param string $offerId ID оффера в Яндексе
     * @param string $workerName Имя воркера для логирования
     * @throws GuzzleException
     */
    private function fetchAndSavePriceHistory(Listing $listing, string $offerId, string $workerName): void
    {
        try {
            $cardUrl = "https://api.realty.yandex.net/1.0/cardWithViews.json?id=$offerId";

            $requestOptions = [
                'headers' => $this->getHeaders(),
            ];
            if ($this->isProxyEnabled()) {
                $requestOptions['proxy'] = $this->getRandomProxy();
            }

            $response = $this->httpClient->request('GET', $cardUrl, $requestOptions);

            $data = json_decode($response->getBody()->getContents(), true);
            $prices = $data['response']['history']['prices'] ?? [];

            if (count($prices) <= 1) {
                // Одна запись — нет истории изменений
                return;
            }

            // Формируем историю в нашем формате: [{date: timestamp, price: value, diff: delta}]
            $priceHistory = [];
            $previousPrice = null;

            // API отдаёт от старых к новым, нам нужно от новых к старым
            $prices = array_reverse($prices);

            foreach ($prices as $index => $priceEntry) {
                $date = isset($priceEntry['date'])
                    ? Carbon::parse($priceEntry['date'])->timestamp
                    : time();
                $value = (int) ($priceEntry['value'] ?? $priceEntry['price']['value'] ?? 0);

                // Для самой свежей записи diff считаем относительно предыдущей (второй)
                // Для остальных — относительно следующей (более новой)
                $diff = 0;
                if ($index === 0 && isset($prices[1])) {
                    $prevValue = (int) ($prices[1]['value'] ?? $prices[1]['price']['value'] ?? 0);
                    $diff = $value - $prevValue;
                } elseif ($previousPrice !== null) {
                    $diff = $previousPrice - $value;
                }

                $priceHistory[] = [
                    'date' => $date,
                    'price' => $value,
                    'diff' => $diff,
                ];

                $previousPrice = $value;
            }

            $listing->update(['price_history' => json_encode($priceHistory)]);

            $this->logger->info("[$workerName] История цен сохранена", [
                'offer_id' => $offerId,
                'listing_id' => $listing->id,
                'entries' => count($priceHistory),
            ]);
        } catch (Exception $exception) {
            $this->logger->warning("[$workerName] Ошибка получения истории цен", [
                'offer_id' => $offerId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Сборка URL запроса к API Яндекс.Недвижимости
     *
     * @param array $locationConfig Конфигурация локации
     * @param array $categoryConfig Конфигурация категории
     * @return string Полный URL с параметрами
     * @throws RandomException
     */
    public function buildApiUrl(array $locationConfig, array $categoryConfig): string
    {
        // Общие параметры + rgid локации + параметры категории
        $request = $this->config['request'];
        $categoryParams = $categoryConfig['api_params'] ?? [];

        // Мержим: общие → категорийные (категорийные перезаписывают общие)
        // Значение false удаляет параметр (например, roomsTotal для коммерческих)
        $params = array_merge($request, $categoryParams);
        $params['rgid'] = $locationConfig['rgid'];
        $params = array_filter($params, fn($value) => $value !== false);

        // Рандомизация цен для обхода кеширования ответов API
        // Формат в конфиге: 'priceMin' => [18000, 22000] — рандом кратный 1000
        // или 'priceMin' => 20000 — фиксированное значение (без рандома)
        $priceStep = 1000;
        if (isset($params['priceMin']) && is_array($params['priceMin'])) {
            [$from, $to] = $params['priceMin'];
            $steps = (int) (($to - $from) / $priceStep);
            $params['priceMin'] = $from + random_int(0, $steps) * $priceStep;
        }
        if (isset($params['priceMax']) && is_array($params['priceMax'])) {
            [$from, $to] = $params['priceMax'];
            $steps = (int) (($to - $from) / $priceStep);
            $params['priceMax'] = $from + random_int(0, $steps) * $priceStep;
        }

        // Собираем URL: массивы (roomsTotal) раскладываем в повторяющиеся ключи
        $queryParts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $queryParts[] = urlencode($key) . '=' . urlencode((string) $item);
                }
            } else {
                $queryParts[] = urlencode($key) . '=' . urlencode((string) $value);
            }
        }

        return $this->config['api_url'] . '?' . implode('&', $queryParts);
    }

    /**
     * Заголовки HTTP-запроса к API Яндекса
     *
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'host' => 'api.realty.yandex.net',
            'user-agent' => $this->config['user_agent'],
            'x-authorization' => $this->config['auth_token'],
            'accept-encoding' => 'gzip',
        ];
    }

    /**
     * Маппинг объявления из Яндекс API в формат нашей таблицы listings
     *
     * @param array $offer Объявление из API
     * @param int $locationId ID локации
     * @param int $categoryId ID категории
     * @return array|null Данные для записи или null при ошибке маппинга
     */
    private function mapOfferToListing(array $offer, int $locationId, int $categoryId): ?array
    {
        $offerId = $offer['offerId'] ?? null;
        if ($offerId === null) {
            return null;
        }

        $isCommercial = in_array($categoryId, self::COMMERCIAL_CATEGORY_IDS, true);

        // Определяем room_id
        $roomId = $this->determineRoomId($offer, $isCommercial);

        // Получаем площадь
        $squareMeters = isset($offer['area']['value']) ? (float) $offer['area']['value'] : null;

        // Тип коммерческой: offer.commercial.commercialTypes[0] (массив, берём первый)
        $commercialType = $offer['commercial']['commercialTypes'][0] ?? null;

        // Формируем title
        $title = $this->buildTitle($commercialType, $offer, $squareMeters, $isCommercial);

        // Получаем телефон
        $phone = null;
        if (isset($offer['author']['phones'][0])) {
            $phone = $this->cleanPhone($offer['author']['phones'][0]);
        }

        // Координаты самого объекта (не станции метро)
        $lat = isset($offer['location']['latitude'])
            ? (float) $offer['location']['latitude']
            : null;
        $lng = isset($offer['location']['longitude'])
            ? (float) $offer['location']['longitude']
            : null;

        // Цена
        $price = isset($offer['price']['value']) ? (float) $offer['price']['value'] : null;

        // Этаж и этажность
        $floor = isset($offer['floorsOffered'][0]) ? (int) $offer['floorsOffered'][0] : null;
        $floorsTotal = isset($offer['floorsTotal']) ? (int) $offer['floorsTotal'] : null;

        // Парсинг адреса из structuredAddress
        $addressParts = $this->parseStructuredAddress($offer);

        return [
            'external_id' => (string) $offerId,
            'source_id' => self::SOURCE_ID,
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'room_id' => $roomId,
            'listing_status_id' => self::LISTING_STATUS_NEW,
            'title' => $title,
            'address' => $offer['location']['geocoderAddress'] ?? null,
            'city' => $addressParts['city'],
            'street' => $addressParts['street'],
            'house' => $addressParts['house'],
            'price' => $price,
            'square_meters' => $squareMeters,
            'floor' => $floor,
            'floors_total' => $floorsTotal,
            'phone' => $phone,
            'url' => $offer['shareUrl'] ?? null,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    /**
     * Формирует title объявления
     *
     * Коммерческая: "Офис, 120 м²" / "Коммерческая недвижимость, 120 м²"
     * Жилая: "2-к. квартира, 54 м²" / "Студия, 28 м²"
     *
     * @param string|null $commercialType Тип коммерческой из API (OFFICE, RETAIL и т.д.)
     * @param array $offer Объявление из API
     * @param float|null $squareMeters Площадь
     * @param bool $isCommercial Коммерческая категория (2, 4)
     * @return string Заголовок
     */
    private function buildTitle(?string $commercialType, array $offer, ?float $squareMeters, bool $isCommercial): string
    {
        $areaSuffix = $squareMeters ? ', ' . $squareMeters . ' м²' : '';

        // Коммерческая с известным типом
        if ($commercialType !== null && isset(self::COMMERCIAL_TYPE_NAMES[$commercialType])) {
            return self::COMMERCIAL_TYPE_NAMES[$commercialType] . $areaSuffix;
        }

        // Коммерческая категория, но API не вернул commercialType
        if ($isCommercial) {
            return 'Коммерческая недвижимость' . $areaSuffix;
        }

        // Жилая недвижимость
        $roomsTotal = $offer['roomsTotal'] ?? null;
        if ($roomsTotal !== null && $roomsTotal !== 'STUDIO') {
            $rooms = ($roomsTotal === 'PLUS_4') ? '4+' : (string) (int) $roomsTotal;
            return $rooms . '-к. квартира' . $areaSuffix;
        }

        return 'Студия' . $areaSuffix;
    }

    /**
     * Определяет room_id на основе данных объявления
     *
     * Жилая: STUDIO→1, 1→2, 2→3, 3→4, >=4→5
     * Коммерческая: commercialType → room_id из БД по коду
     *
     * @param array $offer Объявление из API
     * @param bool $isCommercial Коммерческая категория (2, 4)
     * @return int|null room_id или null
     */
    private function determineRoomId(array $offer, bool $isCommercial): ?int
    {
        // Коммерческая недвижимость — тип в offer.commercial.commercialTypes[0]
        $commercialType = $offer['commercial']['commercialTypes'][0] ?? null;
        if ($commercialType !== null) {
            return $this->resolveCommercialRoomId($commercialType);
        }

        // Коммерческая категория без commercialType в ответе API — не проваливаемся в жилую логику
        if ($isCommercial) {
            return null;
        }

        if (!isset($offer['roomsTotal'])) {
            // Нет roomsTotal — считаем студией
            return self::ROOM_MAPPING['STUDIO'];
        }

        $roomsTotal = $offer['roomsTotal'];

        // Строковые значения из API
        if ($roomsTotal === 'STUDIO') {
            return self::ROOM_MAPPING['STUDIO'];
        }
        if ($roomsTotal === 'PLUS_4') {
            return self::ROOM_ID_PLUS_4;
        }

        $roomsInt = (int) $roomsTotal;

        if (isset(self::ROOM_MAPPING[$roomsInt])) {
            return self::ROOM_MAPPING[$roomsInt];
        }

        // 4 и более комнат (числовое значение)
        if ($roomsInt >= 4) {
            return self::ROOM_ID_PLUS_4;
        }

        return null;
    }

    /**
     * Определяет room_id для коммерческого типа (по коду из БД)
     *
     * @param string $commercialType Тип из API (OFFICE, RETAIL и т.д.)
     * @return int|null room_id или null
     */
    private function resolveCommercialRoomId(string $commercialType): ?int
    {
        // Лениво загружаем кеш коммерческих room_id
        if (empty($this->commercialRoomIdCache)) {
            $codes = array_values(self::COMMERCIAL_TYPE_MAPPING);
            $rows = Room::whereIn('code', $codes)->pluck('id', 'code')->toArray();
            $this->commercialRoomIdCache = $rows;
        }

        $code = self::COMMERCIAL_TYPE_MAPPING[$commercialType] ?? null;
        if ($code === null) {
            return null;
        }

        return $this->commercialRoomIdCache[$code] ?? null;
    }

    /**
     * Парсинг адреса из structuredAddress
     *
     * Извлекает город, улицу и номер дома из массива компонентов
     * offer.location.structuredAddress.component[] по regionType.
     *
     * @param array $offer Объявление из API
     * @return array{city: string|null, street: string|null, house: string|null}
     */
    private function parseStructuredAddress(array $offer): array
    {
        $result = [
            'city' => null,
            'street' => null,
            'house' => null,
        ];

        $components = $offer['location']['structuredAddress']['component'] ?? [];
        if (empty($components)) {
            return $result;
        }

        foreach ($components as $component) {
            $regionType = $component['regionType'] ?? '';
            $value = $component['value'] ?? '';

            if ($value === '') {
                continue;
            }

            switch ($regionType) {
                case 'CITY':
                case 'CITY_DISTRICT':
                    // CITY — основной город, CITY_DISTRICT — для случаев без CITY
                    if ($result['city'] === null) {
                        $result['city'] = $value;
                    }
                    break;

                case 'STREET':
                    $result['street'] = $value;
                    break;

                case 'HOUSE':
                    $result['house'] = $value;
                    break;
            }
        }

        return $result;
    }

    /**
     * Извлекает данные о метро из ответа API (время, тип транспорта, дистанция)
     *
     * Использует offer.location.metro (ближайшее метро) или metroList[0].
     * Дистанция рассчитывается по средней скорости: пешком 5 км/ч, транспорт 25 км/ч.
     *
     * @param array $offer Объявление из API
     * @return array{travel_time_min: int|null, travel_type: string|null, distance: string|null}
     */
    private function extractMetroData(array $offer): array
    {
        $result = [
            'travel_time_min' => null,
            'travel_type' => null,
            'distance' => null,
        ];

        // Приоритет: metro (ближайшее) → metroList[0]
        $metroInfo = $offer['location']['metro'] ?? $offer['location']['metroList'][0] ?? null;
        if ($metroInfo === null) {
            return $result;
        }

        // Время до метро (в минутах)
        $timeToMetro = isset($metroInfo['timeToMetro']) ? (int) $metroInfo['timeToMetro'] : null;
        if ($timeToMetro !== null && $timeToMetro > 0) {
            $result['travel_time_min'] = $timeToMetro;
        }

        // Тип транспорта (ON_FOOT → walk, ON_TRANSPORT → public_transport)
        $transport = $metroInfo['metroTransport'] ?? null;
        if ($transport !== null && isset(self::METRO_TRANSPORT_MAPPING[$transport])) {
            $result['travel_type'] = self::METRO_TRANSPORT_MAPPING[$transport];
        }

        // Расчёт дистанции по средней скорости
        if ($timeToMetro !== null && $timeToMetro > 0) {
            $speedKmh = ($transport === 'ON_FOOT')
                ? self::WALKING_SPEED_KMH
                : self::TRANSPORT_SPEED_KMH;

            // Дистанция в метрах: (время_мин / 60) * скорость_км/ч * 1000
            $distanceMeters = ($timeToMetro / 60) * $speedKmh * 1000;

            if ($distanceMeters >= 1000) {
                // Округляем до 0.1 км
                $distanceKm = round($distanceMeters / 1000, 1);
                $result['distance'] = str_replace('.', ',', (string) $distanceKm) . ' км';
            } else {
                // Округляем до 50 м
                $distanceRounded = (int) round($distanceMeters / 50) * 50;
                $result['distance'] = $distanceRounded . ' м';
            }
        }

        return $result;
    }

    /**
     * Нормализация телефонного номера
     *
     * Приводит телефон к формату 7XXXXXXXXXX (11 цифр).
     *
     * @param string $phone Сырой телефон из API
     * @return string|null Очищенный телефон или null
     */
    private function cleanPhone(string $phone): ?string
    {
        $cleaned = preg_replace('/\D/', '', $phone);
        if ($cleaned === null) {
            return null;
        }

        if (strlen($cleaned) === 10) {
            return '7' . $cleaned;
        }

        if (strlen($cleaned) === 11) {
            return '7' . substr($cleaned, 1);
        }

        if (preg_match('/([78]9\d{9})/', $cleaned, $matches)) {
            if (count($matches) > 1) {
                return '7' . substr($matches[1], 1);
            }
        }

        if (preg_match('/(9\d{9})/', $cleaned, $matches)) {
            if (count($matches) > 1) {
                return '7' . $matches[1];
            }
        }

        return null;
    }

    /**
     * Сохраняет объявление в БД (upsert по external_id + source_id)
     *
     * При ошибке БД пытается переподключиться и повторить запись.
     *
     * @param array $listingData Данные объявления
     * @return Listing|null Модель или null при ошибке
     */
    private function saveListing(array $listingData): ?Listing
    {
        try {
            return $this->doSaveListing($listingData);
        } catch (Exception $exception) {
            // Попытка переподключения к БД
            $this->logger->warning('Ошибка БД, пробуем переподключиться', [
                'error' => $exception->getMessage(),
            ]);

            try {
                DB::connection()->reconnect();
                return $this->doSaveListing($listingData);
            } catch (Exception $retryException) {
                $this->logger->error('Повторная ошибка БД после reconnect', [
                    'error' => $retryException->getMessage(),
                ]);
                return null;
            }
        }
    }

    /**
     * Непосредственное сохранение объявления (upsert + PostGIS)
     *
     * @param array $listingData Данные объявления
     * @return Listing Модель объявления
     */
    private function doSaveListing(array $listingData): Listing
    {
        return DB::transaction(function () use ($listingData) {
            $listing = Listing::updateOrCreate(
                [
                    'external_id' => $listingData['external_id'],
                    'source_id' => $listingData['source_id'],
                ],
                $listingData
            );

            // Обновляем PostGIS point
            if ($listing->hasCoordinates()) {
                $listing->updatePointField();
            }

            return $listing;
        });
    }

    /**
     * Сохраняет привязку объявления к ближайшей станции метро
     *
     * @param Listing $listing Модель объявления
     * @param array $station Данные станции метро
     * @param array $metroData Данные о расстоянии до метро из API (travel_time_min, travel_type, distance)
     */
    private function saveMetroLink(Listing $listing, array $station, array $metroData = []): void
    {
        try {
            // Timestamps устанавливаются автоматически через withTimestamps() на связи
            $pivotData = [];

            // Добавляем данные о времени/расстоянии если есть
            if (!empty($metroData['travel_time_min'])) {
                $pivotData['travel_time_min'] = $metroData['travel_time_min'];
            }
            if (!empty($metroData['travel_type'])) {
                $pivotData['travel_type'] = $metroData['travel_type'];
            }
            if (!empty($metroData['distance'])) {
                $pivotData['distance'] = $metroData['distance'];
            }

            // syncWithoutDetaching чтобы не удалять существующие связи
            $listing->metroStations()->syncWithoutDetaching([
                $station['id'] => $pivotData,
            ]);
        } catch (Exception $exception) {
            $this->logger->warning('Ошибка привязки метро', [
                'listing_id' => $listing->id,
                'station_id' => $station['id'],
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Загружает существующие external_id для дедупликации
     *
     * Загружает объявления за последние 30 дней для указанной локации/категории.
     *
     * @param int $locationId ID локации
     * @param int $categoryId ID категории
     * @return array<string, string> Ассоциативный массив [external_id => external_id]
     */
    private function loadExistingItems(int $locationId, int $categoryId): array
    {
        try {
            $existingIds = DB::table('listings')
                ->where('location_id', $locationId)
                ->where('category_id', $categoryId)
                ->where('source_id', self::SOURCE_ID)
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->pluck('external_id')
                ->toArray();

            $result = [];
            foreach ($existingIds as $externalId) {
                $result[$externalId] = $externalId;
            }

            return $result;
        } catch (Exception $exception) {
            $this->logger->error('Ошибка загрузки существующих объявлений', [
                'location_id' => $locationId,
                'category_id' => $categoryId,
                'error' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Загружает станции метро с координатами для указанной локации
     *
     * @param int $locationId ID локации
     * @return array<int, array{id: int, name: string, lat: float, lng: float}> Массив станций
     */
    private function loadMetroStations(int $locationId): array
    {
        try {
            return MetroStation::where('location_id', $locationId)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['id', 'name', 'lat', 'lng'])
                ->toArray();
        } catch (Exception $exception) {
            $this->logger->error('Ошибка загрузки станций метро', [
                'location_id' => $locationId,
                'error' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Находит станцию метро по названию и координатам
     *
     * Алгоритм:
     * 1. Ищем станции с совпадающим названием (без учёта регистра)
     * 2. Если найдено несколько (одноимённые на разных линиях) — берём ближайшую по координатам
     * 3. Если по названию не найдено — fallback на ближайшую по координатам из всего кеша
     *
     * @param string $name Название станции из API Яндекса
     * @param float $lat Широта станции из API
     * @param float $lng Долгота станции из API
     * @return array|null Данные станции из нашей БД или null
     */
    private function findMetroStation(string $name, float $lat, float $lng): ?array
    {
        $nameLower = mb_strtolower(trim($name));

        // 1. Фильтруем по названию
        $matchedByName = [];
        foreach ($this->metroStations as $station) {
            $stationNameLower = mb_strtolower(trim($station['name']));
            if ($stationNameLower === $nameLower) {
                $matchedByName[] = $station;
            }
        }

        // 2. Если нашли по названию — выбираем ближайшую по координатам
        if (count($matchedByName) === 1) {
            return $matchedByName[0];
        }

        if (count($matchedByName) > 1) {
            return $this->findNearestByCoordinates($matchedByName, $lat, $lng);
        }

        // 3. Fallback: по названию не нашли — ищем ближайшую из всего кеша
        return $this->findNearestByCoordinates($this->metroStations, $lat, $lng);
    }

    /**
     * Находит ближайшую станцию по координатам из переданного массива (формула Haversine)
     *
     * @param array $stations Массив станций для поиска
     * @param float $lat Широта точки
     * @param float $lng Долгота точки
     * @return array|null Ближайшая станция или null
     */
    private function findNearestByCoordinates(array $stations, float $lat, float $lng): ?array
    {
        $nearestStation = null;
        $minDistance = PHP_INT_MAX;

        foreach ($stations as $station) {
            $stationLat = (float) $station['lat'];
            $stationLng = (float) $station['lng'];

            $distance = $this->calculateDistance($lat, $lng, $stationLat, $stationLng);

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestStation = $station;
            }
        }

        return $nearestStation;
    }

    /**
     * Вычисляет расстояние между двумя точками по формуле Haversine
     *
     * @param float $lat1 Широта первой точки
     * @param float $lng1 Долгота первой точки
     * @param float $lat2 Широта второй точки
     * @param float $lng2 Долгота второй точки
     * @return float Расстояние в километрах
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // Радиус Земли в километрах

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $angle = sin($latDiff / 2) * sin($latDiff / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDiff / 2) * sin($lngDiff / 2);

        $distance = 2 * atan2(sqrt($angle), sqrt(1 - $angle));

        return $earthRadius * $distance;
    }

    /**
     * Проверяет, нужно ли ротировать кеш дедупликации
     *
     * @return bool true если прошло больше cache_rotation_minutes с последней ротации
     */
    private function shouldRotateCache(): bool
    {
        $rotationInterval = ($this->config['cache_rotation_minutes'] ?? 60) * 60;

        return (time() - $this->lastCacheRotation) >= $rotationInterval;
    }

    /**
     * Проверяет, включены ли прокси
     *
     * @return bool true если прокси включены и список не пуст
     */
    private function isProxyEnabled(): bool
    {
        return !empty($this->config['proxy']['enabled'])
            && !empty($this->config['proxy']['list']);
    }

    /**
     * Возвращает случайный прокси из списка
     *
     * @return string URL прокси
     */
    private function getRandomProxy(): string
    {
        $proxyList = $this->config['proxy']['list'];

        return $proxyList[array_rand($proxyList)];
    }

    /**
     * Обработка backoff при превышении лимита последовательных ошибок
     *
     * @param string $workerName Имя воркера для логирования
     */
    private function handleErrorBackoff(string $workerName): void
    {
        if ($this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
            $this->logger->warning(
                "[$workerName] Слишком много ошибок подряд ($this->consecutiveErrors), пауза " .
                self::ERROR_BACKOFF_SECONDS . " сек"
            );
            sleep(self::ERROR_BACKOFF_SECONDS);
            $this->consecutiveErrors = 0;
        }
    }
}
