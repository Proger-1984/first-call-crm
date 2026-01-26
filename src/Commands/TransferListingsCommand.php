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

    private const COMMAND_NAME = 'transfer-listings';
    
    // Настройки подключения к удалённой БД
    private const REMOTE_DB_HOST = '89.248.193.168';
    private const REMOTE_DB_PORT = '3306';
    private const REMOTE_DB_NAME = 'avito';
    private const REMOTE_DB_USER = 'sokol';
    private const REMOTE_DB_PASS = '3eRT_6RcVm/)_tyM';

    // Настройки переноса
    private const BATCH_SIZE = 200;           // Количество записей за один запрос
    private const SLEEP_INTERVAL = 2000000;   // Пауза между итерациями (2 секунды)
    private const KEEP_DAYS = 31;             // Хранить объявления за последние N дней
    
    // Локальные значения по умолчанию
    private const DEFAULT_LOCATION_ID = 1;        // Москва и область
    private const DEFAULT_CATEGORY_ID = 1;        // Категория по умолчанию
    private const DEFAULT_LISTING_STATUS_ID = 1;  // Статус "Новое"

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
            $this->connectToRemoteDb();
            
            // Удаляем старые объявления
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

        while (true) {
            try {
                $limit = self::BATCH_SIZE;
                
                // Проверка соединения каждые 3 минуты
                if (time() - $connectionCheckTime >= 180) {
                    $connectionCheckTime = time();
                    $limit = 50; // Меньше записей при проверке соединения
                    $this->checkRemoteConnection();
                }

                // Получаем новые объявления с удалённого сервера
                $items = $this->fetchRemoteListings($limit);

                // Фильтруем объявления по условиям
                $items = $this->filterItems($items);

                if (empty($items)) {
                    usleep(self::SLEEP_INTERVAL);
                    continue;
                }

                // Преобразуем и вставляем в локальную БД
                $insertedCount = $this->insertListings($items);

                if ($insertedCount > 0) {
                    $this->logger->info("Перенесено объявлений: {$insertedCount}", [], self::COMMAND_NAME);
                }

            } catch (PDOException $e) {
                $this->logger->warning('Ошибка БД: ' . $e->getMessage(), [], self::COMMAND_NAME);
                $this->reconnectToRemoteDb();
            } catch (Throwable $e) {
                $this->logger->warning('Ошибка: ' . $e->getMessage(), [], self::COMMAND_NAME);
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
                    id, category_id, group_id, source_id, city_id, 
                    title, address, price, url, lat, lng, phone, 
                    metro_id, room_id, raised_id, created_at, 
                    removed, is_paid, status_id
                FROM items 
                WHERE (TIMESTAMPDIFF(SECOND, created_at, NOW()) > 2) 
                  AND status_id != 2
                ORDER BY created_at DESC 
                LIMIT $limit";

        $stmt = $this->remoteDb->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Фильтрация объявлений по условиям из старого скрипта
     * 
     * - Платные объявления (is_paid=1) с телефоном
     * - Статус 1 или 7
     * - group_id=1 и определённые city_id
     * - Созданные более 2 минут назад
     */
    private function filterItems(array $items): array
    {
        $allowedCityIds = range(1, 24);

        foreach ($items as $key => $item) {
            // Фильтр для платных объявлений с телефоном
            if ($item['is_paid'] == 1 && !empty($item['phone'])) {
                $dateFromDb = $item['created_at'];
                $targetTimestamp = strtotime($dateFromDb);
                $currentTimestamp = time();

                $diffSeconds = $currentTimestamp - $targetTimestamp;
                $diffMinutes = floor($diffSeconds / 60);

                // Статус 1 или 7, group_id=1, определённые города
                if (in_array($item['status_id'], [1, 7])) {
                    if ($item['group_id'] == 1 && in_array($item['city_id'], $allowedCityIds)) {
                        // Пропускаем объявления младше 2 минут
                        if ($diffMinutes <= 2) {
                            unset($items[$key]);
                        }
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
            
            // Сохраняем связь с метро для последующей вставки
            if (!empty($item['metro_id'])) {
                $metroLinks[$item['id']] = $item['metro_id'];
            }
        }

        // Upsert: вставляем или обновляем по source_id + external_id
        DB::table('listings')->upsert(
            $mappedItems,
            ['source_id', 'external_id'],  // Уникальный ключ
            ['phone', 'price', 'is_paid', 'updated_at']  // Поля для обновления
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

        return [
            'external_id'       => (string) $item['id'],
            'source_id'         => $item['source_id'],
            'category_id'       => self::DEFAULT_CATEGORY_ID,
            'listing_status_id' => self::DEFAULT_LISTING_STATUS_ID,  // Всегда "Новое"
            'location_id'       => self::DEFAULT_LOCATION_ID,        // Москва и область
            'title'             => $title,
            'address'           => $item['address'] ?? null,
            'price'             => $item['price'] ?? null,
            'phone'             => $item['phone'] ?? null,
            'url'               => $item['url'] ?? null,
            'lat'               => $item['lat'] ?? null,
            'lng'               => $item['lng'] ?? null,
            'rooms'             => $item['room_id'] ?? null,
            'is_paid'           => (bool) ($item['is_paid'] ?? false),
            'created_at'        => $item['created_at'] ?? Carbon::now(),
            'updated_at'        => Carbon::now(),
        ];
    }

    /**
     * Вставка связей объявлений с метро
     */
    private function insertMetroLinks(array $metroLinks): void
    {
        if (empty($metroLinks)) {
            return;
        }

        foreach ($metroLinks as $externalId => $metroId) {
            try {
                // Находим listing_id по external_id
                $listing = DB::table('listings')
                    ->where('external_id', (string) $externalId)
                    ->first(['id']);

                if (!$listing) {
                    continue;
                }

                // Проверяем, существует ли станция метро
                $metroExists = DB::table('metro_stations')
                    ->where('id', $metroId)
                    ->exists();

                if (!$metroExists) {
                    continue;
                }

                // Вставляем связь (игнорируем дубликаты)
                DB::table('listing_metro')->insertOrIgnore([
                    'listing_id'       => $listing->id,
                    'metro_station_id' => $metroId,
                    'created_at'       => Carbon::now(),
                    'updated_at'       => Carbon::now(),
                ]);
            } catch (Throwable $e) {
                // Игнорируем ошибки вставки метро
            }
        }
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
     */
    private function checkRemoteConnection(): void
    {
        $this->remoteDb->query(/** @lang text */ "SELECT id FROM items LIMIT 1")->fetch();
    }

    /**
     * Переподключение к удалённой БД
     */
    private function reconnectToRemoteDb(): void
    {
        $this->logger->info('Переподключение к удалённой БД...', [], self::COMMAND_NAME);
        
        $this->remoteDb = null;
        sleep(5);
        
        $this->connectToRemoteDb();
    }
}
