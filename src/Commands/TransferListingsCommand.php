<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\LogService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use PDO;
use PDOException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Команда для переноса объявлений с удалённого сервера
 * 
 * Подключается к MySQL базе на удалённом сервере,
 * забирает новые объявления и добавляет в локальную PostgreSQL базу
 */
class TransferListingsCommand extends Command
{
    private LogService $logger;
    private ?PDO $remoteDb = null;
    
    /** @var array<string, int> Кэш соответствия паттернов комнат к room_id */
    private array $roomPatterns = [];
    
    /** @var array<string, int> Кэш станций метро: "line_id:station_id" => id */
    private array $metroStationsCache = [];

    private const COMMAND_NAME = 'transfer-listings';
    
    // Настройки подключения к удалённой БД
    private const REMOTE_DB_HOST = '89.248.193.168';
    private const REMOTE_DB_PORT = '3306';
    private const REMOTE_DB_NAME = 'avito';
    private const REMOTE_DB_USER = 'sokol';
    private const REMOTE_DB_PASS = '3eRT_6RcVm/)_tyM';

    // Настройки переноса
    private const BATCH_SIZE_FIRST = 100;     // Количество записей в первом запросе
    private const BATCH_SIZE = 20;            // Количество записей в последующих запросах
    private const SLEEP_INTERVAL = 2000000;   // Пауза между итерациями (2 секунды)
    private const KEEP_DAYS = 31;             // Хранить объявления за последние N дней
    private const RECONNECT_DELAY = 5;        // Задержка перед переподключением (секунды)
    private const MAX_RECONNECT_ATTEMPTS = 5; // Максимум попыток переподключения подряд
    
    // Локальные значения по умолчанию
    private const DEFAULT_LOCATION_ID = 1;        // Москва и область
    private const DEFAULT_CATEGORY_ID = 1;        // Категория по умолчанию
    private const DEFAULT_LISTING_STATUS_ID = 1;  // Статус "Новое"
    
    // ID источника CIAN (в удалённой БД)
    private const SOURCE_CIAN = 3;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
             ->setDescription('Перенос объявлений с удалённого сервера');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        date_default_timezone_set('Europe/Moscow');

        $this->logger->info('Запуск переноса объявлений', [], self::COMMAND_NAME);

        try {
            // Подключаемся к удалённой БД
            $this->logger->info("Подключение к удалённой БД...", [], self::COMMAND_NAME);
            $this->connectToRemoteDb();
            
            // Загружаем паттерны комнат из БД
            $this->loadRoomPatterns();
            
            // Загружаем станции метро в кэш
            $this->loadMetroStations();
            
            // Удаляем старые объявления
            $this->logger->info("Удаление старых объявлений...", [], self::COMMAND_NAME);
            $this->deleteOldListings();

            // Основной цикл переноса
            $this->runTransferLoop();

        } catch (Throwable $e) {
            $this->logger->error('Критическая ошибка: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ], self::COMMAND_NAME);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Подключение к удалённой MySQL базе
     */
    private function connectToRemoteDb(): void
    {
        $dsn = sprintf(
            'mysql:host=%s:%s;dbname=%s',
            self::REMOTE_DB_HOST,
            self::REMOTE_DB_PORT,
            self::REMOTE_DB_NAME
        );

        $this->remoteDb = new PDO($dsn, self::REMOTE_DB_USER, self::REMOTE_DB_PASS, [
            PDO::ATTR_AUTOCOMMIT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $this->remoteDb->query("SET NAMES 'utf8'");
        $this->remoteDb->query("SET CHARACTER SET 'utf8mb4'");
        $this->remoteDb->query("SET TIME_ZONE = '+3:00'");
        $this->remoteDb->query("SET SESSION collation_connection = 'utf8_general_ci'");

        $this->logger->info('Подключение к удалённой БД установлено', [], self::COMMAND_NAME);
    }

    /**
     * Удаление старых объявлений (старше 31 дня)
     */
    private function deleteOldListings(): void
    {
        $deletedCount = DB::table('listings')
            ->where('created_at', '<', Carbon::now()->subDays(self::KEEP_DAYS))
            ->delete();

        if ($deletedCount > 0) {
            $this->logger->info("Удалено старых объявлений: $deletedCount", [], self::COMMAND_NAME);
        }
    }

    /**
     * Основной цикл переноса объявлений
     */
    private function runTransferLoop(): void
    {
        $connectionCheckTime = time();
        $reconnectAttempts = 0;
        $isFirstIteration = true;

        while (true) {
            try {
                // Первый запрос — больше записей, последующие — меньше
                $limit = $isFirstIteration ? self::BATCH_SIZE_FIRST : self::BATCH_SIZE;
                
                // Проверка соединения каждые 3 минуты
                if (time() - $connectionCheckTime >= 180) {
                    $connectionCheckTime = time();
                    $this->checkRemoteConnection();
                    $this->logger->info("Проверка соединения OK", [], self::COMMAND_NAME);
                }

                // Получаем новые объявления с удалённого сервера
                $items = $this->fetchRemoteListings($limit);
                
                // После первого запроса переключаемся на меньший лимит
                $isFirstIteration = false;

                // Фильтруем объявления по условиям
                $items = $this->filterItems($items);

                if (empty($items)) {
                    usleep(self::SLEEP_INTERVAL);
                    continue;
                }

                // Преобразуем и вставляем в локальную БД
                $insertedCount = $this->insertListings($items);

                if ($insertedCount > 0) {
                    $this->logger->info("Перенесено объявлений: $insertedCount", [], self::COMMAND_NAME);
                }
                
                // Сбрасываем счётчик попыток при успешной итерации
                $reconnectAttempts = 0;

            } catch (PDOException $e) {
                $this->logger->warning('Ошибка БД: ' . $e->getMessage(), [], self::COMMAND_NAME);
                $reconnectAttempts++;
                
                if ($reconnectAttempts > self::MAX_RECONNECT_ATTEMPTS) {
                    $this->logger->error("Превышено максимальное количество попыток переподключения ($reconnectAttempts)", [], self::COMMAND_NAME);
                    throw $e; // Пусть supervisor перезапустит скрипт
                }
                
                $this->reconnectToRemoteDb();
            } catch (Throwable $e) {
                $this->logger->warning('Ошибка: ' . $e->getMessage(), [], self::COMMAND_NAME);
                // Для других ошибок просто продолжаем работу
            }

            usleep(self::SLEEP_INTERVAL);
        }
    }

    /**
     * Получение объявлений с удалённого сервера
     */
    private function fetchRemoteListings(int $limit): array
    {
        $sql = /** @lang text */
            "SELECT 
                    i.id, i.category_id, i.group_id, i.source_id, i.city_id, 
                    i.title, i.address, i.price, i.url, i.lat, i.lng, i.phone, 
                    i.metro_id, i.room_id, i.raised_id, i.created_at, 
                    i.removed, i.is_paid, i.status_id, i.square_meters, 
                    i.total_floors, i.floor, i.street, i.house, i.description, i.city_name,
                    i.price_history, i.metro_distance, i.metro_walk_time, i.phone_unavailable,
                    m.hh_line_id, m.hh_station_id
                FROM items i
                LEFT JOIN metro m ON i.metro_id = m.id
                WHERE (TIMESTAMPDIFF(SECOND, i.created_at, NOW()) > 2) 
                  AND i.status_id != 2
                ORDER BY i.created_at DESC 
                LIMIT $limit";

        $stmt = $this->remoteDb->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Фильтрация объявлений по условиям из старого скрипта
     * 
     * - Платные объявления (is_paid=1)
     * - Статус 1 или 7 (Новое, Звонок)
     * - group_id=1 и определённые city_id
     * - Созданные более 2 минут назад
     */
    private function filterItems(array $items): array
    {
        foreach ($items as $key => $item) {
            // Фильтр для платных объявлений с телефоном
            if ($item['is_paid'] == 1 && !empty($item['phone'])) {
                $dateFromDb = $item['created_at'];
                $targetTimestamp = strtotime($dateFromDb);
                $currentTimestamp = time();

                $diffSeconds = $currentTimestamp - $targetTimestamp;
                $diffMinutes = floor($diffSeconds / 60);

                // Статус 1 или 7, group_id=1
                if (in_array($item['status_id'], [1, 7]) && $item['group_id'] == 1) {
                    // Пропускаем объявления младше 2 минут
                    if ($diffMinutes <= 2) {
                        unset($items[$key]);
                    }
                }
            }
        }

        return array_values($items);
    }

    /**
     * Вставка объявлений в локальную БД
     * 
     * @return int Количество вставленных/обновлённых записей
     */
    private function insertListings(array $items): int
    {
        $mappedItems = [];
        $metroLinks = [];

        foreach ($items as $item) {
            $mapped = $this->mapItemToListing($item);
            $mappedItems[] = $mapped;
            
            // Сохраняем связь с метро для последующей вставки (по hh_line_id и hh_station_id)
            if (!empty($item['hh_station_id']) && !empty($item['hh_line_id'])) {
                $metroLinks[$item['id']] = [
                    'line_id'    => $item['hh_line_id'],
                    'station_id' => $item['hh_station_id'],
                    'walk_time'  => $item['metro_walk_time'] ?? null,
                    'distance'   => $item['metro_distance'] ?? null,
                ];
            }
        }

        // Upsert: вставляем или обновляем по source_id + external_id
        DB::table('listings')->upsert(
            $mappedItems,
            ['source_id', 'external_id'],  // Уникальный ключ
            ['phone', 'phone_unavailable', 'price', 'price_history', 'is_paid', 'updated_at']  // Поля для обновления
        );

        // Вставляем связи с метро
        $this->insertMetroLinks($metroLinks);

        // Обновляем PostGIS point поля для новых записей
        $this->updatePointFields($mappedItems);

        return count($mappedItems);
    }

    /**
     * Преобразование записи из удалённой БД в формат локальной
     */
    private function mapItemToListing(array $item): array
    {
        // Очистка заголовка от служебных меток
        $title = $this->cleanTitle($item['title'] ?? '');
        
        // Очистка URL от параметров (для CIAN)
        $url = $this->cleanUrl($item['url'] ?? '', (int) $item['source_id']);
        
        // Определяем room_id по заголовку
        $roomId = $this->detectRoomIdFromTitle($title);

        return [
            'external_id'       => (string) $item['id'],
            'source_id'         => $item['source_id'],
            'category_id'       => self::DEFAULT_CATEGORY_ID,
            'listing_status_id' => $item['raised_id'],
            'location_id'       => self::DEFAULT_LOCATION_ID,        // Москва и область
            'title'             => $title,
            'address'           => $item['address'] ?? null,
            'price'             => $item['price'] ?? null,
            'phone'             => $item['phone'] ?? null,
            'phone_unavailable' => (bool) ($item['phone_unavailable'] ?? false),
            'url'               => $url,
            'lat'               => $item['lat'] ?? null,
            'lng'               => $item['lng'] ?? null,
            'room_id'           => $roomId,
            'square_meters'     => $item['square_meters'] ?? null,
            'floors_total'      => $item['total_floors'] ?? null,
            'description'       => $item['description'] ?? null,
            'city'              => $item['city_name'] ?? null,
            'price_history'     => $item['price_history'] ?? null,
            'floor'             => $item['floor'] ?? null,
            'street'            => $item['street'] ?? null,
            'house'             => $item['house'] ?? null,
            'is_paid'           => (bool) ($item['is_paid'] ?? false),
            'created_at'        => $item['created_at'] ?? Carbon::now(),
            'updated_at'        => Carbon::now(),
        ];
    }

    /**
     * Вставка связей объявлений с метро (batch)
     * 
     * @param array $metroLinks Массив [external_id => ['line_id' => string, 'station_id' => string, 'walk_time' => string|null, 'distance' => string|null]]
     */
    private function insertMetroLinks(array $metroLinks): void
    {
        if (empty($metroLinks) || empty($this->metroStationsCache)) {
            return;
        }

        try {
            // 1. Собираем все external_id для batch-запроса
            $externalIds = array_map('strval', array_keys($metroLinks));
            
            // 2. Получаем все listing_id одним запросом
            $listings = DB::table('listings')
                ->whereIn('external_id', $externalIds)
                ->pluck('id', 'external_id'); // ['external_id' => id]
            
            if ($listings->isEmpty()) {
                return;
            }
            
            // 3. Формируем массив для batch-вставки
            $insertData = [];
            $now = Carbon::now();
            
            foreach ($metroLinks as $externalId => $metroData) {
                $lineId = $metroData['line_id'];
                $stationId = $metroData['station_id'];
                
                if (empty($lineId) || empty($stationId)) {
                    continue;
                }
                
                // Ищем станцию метро в кэше
                $metroStationId = $this->findMetroStationId((string) $lineId, (string) $stationId);
                if (!$metroStationId) {
                    continue;
                }
                
                // Ищем listing_id в результатах запроса
                $listingId = $listings->get((string) $externalId);
                if (!$listingId) {
                    continue;
                }
                
                $insertData[] = [
                    'listing_id'       => $listingId,
                    'metro_station_id' => $metroStationId,
                    'travel_time_min'  => $this->parseWalkTime($metroData['walk_time'] ?? null),
                    'distance'         => $metroData['distance'] ?? null,
                    'travel_type'      => 'walk',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            
            // 4. Batch upsert всех связей одним запросом
            if (!empty($insertData)) {
                DB::table('listing_metro')->upsert(
                    $insertData,
                    ['listing_id', 'metro_station_id'],
                    ['travel_time_min', 'distance', 'updated_at']
                );
            }
        } catch (Throwable) {
            // Игнорируем ошибки вставки метро
        }
    }

    /**
     * Парсинг времени пешком из строки
     * 
     * Примеры: "11–15 мин." -> 13, "5 мин." -> 5, "20-25 мин" -> 22
     * 
     * @param string|null $walkTimeStr Строка с временем
     * @return int|null Время в минутах или null
     */
    private function parseWalkTime(?string $walkTimeStr): ?int
    {
        if (empty($walkTimeStr)) {
            return null;
        }
        
        // Ищем диапазон: "11–15", "20-25" (разные виды тире)
        if (preg_match('/(\d+)[–\-−](\d+)/', $walkTimeStr, $matches)) {
            $min = (int) $matches[1];
            $max = (int) $matches[2];
            return (int) round(($min + $max) / 2); // Среднее значение
        }
        
        // Ищем одиночное число: "5 мин"
        if (preg_match('/(\d+)/', $walkTimeStr, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }

    /**
     * Обновление PostGIS point полей для записей с координатами
     */
    private function updatePointFields(array $items): void
    {
        foreach ($items as $item) {
            if (!empty($item['lat']) && !empty($item['lng'])) {
                try {
                    DB::statement(
                        /** @lang text */ "UPDATE listings 
                         SET point = ST_SetSRID(ST_MakePoint(?, ?), 4326) 
                         WHERE external_id = ? AND source_id = ? AND point IS NULL",
                        [$item['lng'], $item['lat'], $item['external_id'], $item['source_id']]
                    );
                } catch (Throwable $e) {
                    // Игнорируем ошибки обновления point
                }
            }
        }
    }

    /**
     * Очистка заголовка от служебных меток
     * Удаляет метки вида (1), (25), (s), (m) и т.д.
     */
    private function cleanTitle(string $title): string
    {
        // Удаляем любые цифры в скобках: (1), (25), (100) и т.д.
        $title = preg_replace('/\(\d+\)/', '', $title);
        
        // Удаляем буквенные метки: (s), (m) и т.д.
        $title = preg_replace('/\([a-z]\)/i', '', $title);
        
        return trim($title);
    }

    /**
     * Проверка соединения с удалённой БД
     * 
     * @throws PDOException Если соединение потеряно
     */
    private function checkRemoteConnection(): void
    {
        if ($this->remoteDb === null) {
            throw new PDOException('Соединение с удалённой БД не установлено');
        }
        
        $this->remoteDb->query(/** @lang text */ "SELECT 1")->fetch();
    }

    /**
     * Переподключение к удалённой БД
     * 
     * @throws PDOException Если не удалось переподключиться
     */
    private function reconnectToRemoteDb(): void
    {
        $this->logger->info('Переподключение к удалённой БД...', [], self::COMMAND_NAME);
        
        $this->remoteDb = null;
        sleep(self::RECONNECT_DELAY);
        
        $this->connectToRemoteDb();
    }

    /**
     * Загрузка паттернов комнат из БД
     * Создаёт маппинг паттернов поиска к room_id
     */
    private function loadRoomPatterns(): void
    {
        try {
            $rooms = DB::table('rooms')->get(['id', 'code']);
            
            foreach ($rooms as $room) {
                // Паттерны для поиска в заголовке -> room_id
                switch ($room->code) {
                    case 'studio':
                        $this->roomPatterns['студия'] = $room->id;
                        break;
                    case '1':
                        $this->roomPatterns['1-к.'] = $room->id;
                        $this->roomPatterns['1-комн'] = $room->id;
                        $this->roomPatterns['однокомн'] = $room->id;
                        break;
                    case '2':
                        $this->roomPatterns['2-к.'] = $room->id;
                        $this->roomPatterns['2-комн'] = $room->id;
                        $this->roomPatterns['двухкомн'] = $room->id;
                        break;
                    case '3':
                        $this->roomPatterns['3-к.'] = $room->id;
                        $this->roomPatterns['3-комн'] = $room->id;
                        $this->roomPatterns['трёхкомн'] = $room->id;
                        $this->roomPatterns['трехкомн'] = $room->id;
                        break;
                    case '4':
                        $this->roomPatterns['4-к.'] = $room->id;
                        $this->roomPatterns['4-комн'] = $room->id;
                        $this->roomPatterns['четырёхкомн'] = $room->id;
                        $this->roomPatterns['четырехкомн'] = $room->id;
                        break;
                    case '5+':
                        // 5+ комнат — все варианты 5, 6, 7 и более
                        $this->roomPatterns['5-к.'] = $room->id;
                        $this->roomPatterns['5-комн'] = $room->id;
                        $this->roomPatterns['6-к.'] = $room->id;
                        $this->roomPatterns['6-комн'] = $room->id;
                        $this->roomPatterns['7-к.'] = $room->id;
                        $this->roomPatterns['7-комн'] = $room->id;
                        $this->roomPatterns['многокомн'] = $room->id;
                        break;
                    case 'free':
                        $this->roomPatterns['свободн'] = $room->id;
                        break;
                }
            }
            
            $this->logger->info('Загружено паттернов комнат: ' . count($this->roomPatterns), [], self::COMMAND_NAME);
        } catch (Throwable $e) {
            $this->logger->warning('Не удалось загрузить паттерны комнат: ' . $e->getMessage(), [], self::COMMAND_NAME);
            // Продолжаем работу без паттернов — room_id будет null
        }
    }

    /**
     * Загрузка станций метро в кэш
     * Ключ: "line_id:station_id", значение: id станции в локальной БД
     */
    private function loadMetroStations(): void
    {
        try {
            $stations = DB::table('metro_stations')
                ->whereNotNull('line_id')
                ->whereNotNull('station_id')
                ->get(['id', 'line_id', 'station_id']);
            
            foreach ($stations as $station) {
                $key = $station->line_id . ':' . $station->station_id;
                $this->metroStationsCache[$key] = $station->id;
            }
            
            $this->logger->info('Загружено станций метро в кэш: ' . count($this->metroStationsCache), [], self::COMMAND_NAME);
        } catch (Throwable $e) {
            $this->logger->warning('Не удалось загрузить станции метро: ' . $e->getMessage(), [], self::COMMAND_NAME);
            // Продолжаем работу без кэша — связи с метро не будут создаваться
        }
    }

    /**
     * Поиск станции метро в кэше по line_id и station_id
     * 
     * @param string $lineId ID линии из API hh.ru
     * @param string $stationId ID станции из API hh.ru
     * @return int|null ID станции в локальной БД или null
     */
    private function findMetroStationId(string $lineId, string $stationId): ?int
    {
        $key = $lineId . ':' . $stationId;
        return $this->metroStationsCache[$key] ?? null;
    }

    /**
     * Определение room_id по заголовку объявления
     * 
     * @param string $title Заголовок объявления
     * @return int|null room_id или null если не определено
     */
    private function detectRoomIdFromTitle(string $title): ?int
    {
        $titleLower = mb_strtolower($title);
        
        foreach ($this->roomPatterns as $pattern => $roomId) {
            if (mb_strpos($titleLower, $pattern) !== false) {
                return $roomId;
            }
        }
        
        return null;
    }

    /**
     * Очистка URL от параметров запроса
     * Для CIAN: https://www.cian.ru/rent/flat/326209530/?params -> https://www.cian.ru/rent/flat/326209530/
     * 
     * @param string $url Исходный URL
     * @param int $sourceId ID источника
     * @return string|null Очищенный URL
     */
    private function cleanUrl(string $url, int $sourceId): ?string
    {
        if (empty($url)) {
            return null;
        }
        
        // Для CIAN удаляем параметры запроса
        if ($sourceId === self::SOURCE_CIAN) {
            $parsedUrl = parse_url($url);
            if ($parsedUrl !== false && isset($parsedUrl['scheme'], $parsedUrl['host'], $parsedUrl['path'])) {
                return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'];
            }
        }
        
        return $url;
    }
}
