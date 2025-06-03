<?php

namespace App\Commands;

use App\Services\LogService;
use App\Services\SubscriptionService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use JetBrains\PhpStorm\ArrayShape;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use parallel\Runtime;
use parallel\Channel;
use Psr\Container\ContainerInterface;

class ParseCianMultiThreadCommand extends Command
{
    private LogService $logger;
    private SubscriptionService $subscriptionService;
    private bool $log_status;
    private int $num_threads;
    private array $futureList = [];
    private array $processedItems = [];

    private const THREADS_COUNT = 1;
    private const COMMAND_NAME = 'parse-cian-multi';
    private const DIR_LOG = 'logs/cian/';
    private const STATUS_LOG = true;
    private const BATCH_SIZE = 50;

    private const MESSAGES = [
        'START'            => 'Начало парсинга Циан',
        'NUMBER_OF_TASKS'  => 'Количество категорий для парсинга: ',
        'NUMBER_OF_THREADS'=> 'Количество потоков в работе: ',
        'TASK_INFO_START'  => 'Обрабатываем категорию',
        'TASK_INFO_ITEMS'  => 'Всего объявлений в выгрузке',
        'TASK_INFO_END'    => 'Обработали категорию',
        'PROXY_EMPTY'      => 'Список прокси пуст, загружаем новые',
    ];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);
        $this->subscriptionService = $container->get(SubscriptionService::class);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->num_threads = self::THREADS_COUNT;
        $this->setName(self::COMMAND_NAME)
             ->setDescription('Многопоточный парсер Циан');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        date_default_timezone_set('Europe/Moscow');

        $cmd = '/usr/bin/supervisorctl stop ' . self::COMMAND_NAME;
        $this->logger->info(self::MESSAGES['START'], [], self::COMMAND_NAME);

        try {
            /** Получаем уникальные комбинации локаций и категорий
             * из активных подписок, запускаем на парсинг локации
             * и категории только активных подписок, чтобы не грузить
             * сервер
             */
            $locationCategoryPairs = $this->subscriptionService->getUniqueLocationCategoryPairs();
            $this->logger->info('Найдено комбинаций локация+категория: ' . count($locationCategoryPairs), [], self::COMMAND_NAME);
            
            if (empty($locationCategoryPairs)) {
                $this->logger->warning('Не найдено активных подписок', [], self::COMMAND_NAME);
                exec($cmd);
                return 0;
            }

            // Получаем параметры запросов для всех комбинаций локаций и категорий
            $requestParams = $this->generateRequestParams($locationCategoryPairs);

            // Получаем список существующих объявлений для проверки дубликатов
            foreach (Items::getItemsBySource(3) as $value) {
                $this->processedItems[$value['id']] = $value['id'];
            }

            // Создаем категории для парсинга на основе сформированных параметров
            $categories = [];
            foreach ($requestParams as $requestId => $requestData) {
                $categories[] = [
                    'name' => $requestData['name'],
                    'type' => $requestData['category']['type'],
                    'region' => $requestData['location']['region'],
                    'room' => $requestData['category']['rooms'],
                    'price' => $requestData['category']['price'],
                    'is_by_homeowner' => true,
                    'request_id' => $requestId
                ];
            }
            
            $this->logger->info(self::MESSAGES['NUMBER_OF_TASKS'] . count($categories), [], self::COMMAND_NAME);

            // Если категорий меньше чем потоков, уменьшаем количество потоков
            if (count($categories) < $this->num_threads) {
                $this->num_threads = count($categories);
            }

            // Запускаем многопоточную обработку
            $this->handleParallelProcessing($categories);

        } catch (Exception $e) {
            $this->logger->error('Ошибка выполнения: ' . $e->getMessage(), [], self::COMMAND_NAME);
        }

        $end_time = microtime(true);
        $execution_time = $end_time - $start_time;

        $minutes = floor($execution_time / 60);
        $seconds = number_format($execution_time - ($minutes * 60));

        $this->logger->info("Время выполнения $minutes минут $seconds секунд", [], self::COMMAND_NAME);
        $this->logger->info('Выполнено ' . date('Y-m-d H:i:s'), [], self::COMMAND_NAME);

        exec($cmd);
        return 0;
    }

    /**
     * Получение локаций для парсинга
     * @return array
     */
    private function getLocationsForParsing(): array
    {
        // Здесь можно добавить логику получения локаций из базы данных
        // Например, из таблицы настроек или из аргументов командной строки
        
        // В простейшем случае возвращаем фиксированный список локаций
        return [
            1, // Москва
            2, // Санкт-Петербург
            // Можно добавить другие города
        ];
    }

    /**
     * Определяет категории для парсинга с учетом локаций
     * @param array $locations Массив локаций для парсинга
     * @param array $categoryTypes Массив типов категорий для парсинга
     * @return array
     */
    private function getCategoriesForParsing(array $locations = [], array $categoryTypes = []): array
    {
        // Если локации не заданы, используем Москву по умолчанию
        if (empty($locations)) {
            $locations = [1]; // ID Москвы
        }
        
        // Если категории не заданы, используем стандартный набор
        if (empty($categoryTypes)) {
            $categoryTypes = ['rent'];
        }
        
        $categories = [];
        
        // Настройки цен по локациям
        $priceSettings = [
            // Москва
            1 => [
                'rent_1room' => ['min' => 17500, 'max' => 80000],
                'rent_2room' => ['min' => 25000, 'max' => 120000],
                'rent_3room' => ['min' => 30000, 'max' => 150000],
                'rent_studio' => ['min' => 17500, 'max' => 80000],
                'sale_1room' => ['min' => 4000000, 'max' => 15000000],
                'sale_2room' => ['min' => 6000000, 'max' => 20000000],
                'sale_3room' => ['min' => 8000000, 'max' => 30000000],
                'sale_studio' => ['min' => 3500000, 'max' => 12000000],
            ],
            // Санкт-Петербург
            2 => [
                'rent_1room' => ['min' => 15000, 'max' => 60000],
                'rent_2room' => ['min' => 20000, 'max' => 90000],
                'rent_3room' => ['min' => 25000, 'max' => 120000],
                'rent_studio' => ['min' => 15000, 'max' => 60000],
                'sale_1room' => ['min' => 3000000, 'max' => 10000000],
                'sale_2room' => ['min' => 4500000, 'max' => 15000000],
                'sale_3room' => ['min' => 6000000, 'max' => 20000000],
                'sale_studio' => ['min' => 2500000, 'max' => 8000000],
            ],
            // Другие города (по умолчанию)
            'default' => [
                'rent_1room' => ['min' => 10000, 'max' => 40000],
                'rent_2room' => ['min' => 15000, 'max' => 60000],
                'rent_3room' => ['min' => 20000, 'max' => 80000],
                'rent_studio' => ['min' => 10000, 'max' => 40000],
                'sale_1room' => ['min' => 1500000, 'max' => 6000000],
                'sale_2room' => ['min' => 2500000, 'max' => 8000000],
                'sale_3room' => ['min' => 3500000, 'max' => 12000000],
                'sale_studio' => ['min' => 1200000, 'max' => 5000000],
            ],
        ];
        
        // Для каждой локации и категории создаем конфигурацию
        foreach ($locations as $locationId) {
            $locationPrices = $priceSettings[$locationId] ?? $priceSettings['default'];
            
            foreach ($categoryTypes as $categoryType) {
                // Аренда квартир
                if ($categoryType === 'rent') {
                    // 1-комнатные
                    $categories[] = [
                        'name' => 'rent_1room_' . $locationId,
                        'type' => 'flatrent',
                        'region' => [$locationId],
                        'room' => [1],
                        'price' => $locationPrices['rent_1room'],
                        'is_by_homeowner' => true
                    ];
                    
                    // 2-комнатные
                    $categories[] = [
                        'name' => 'rent_2room_' . $locationId,
                        'type' => 'flatrent',
                        'region' => [$locationId],
                        'room' => [2],
                        'price' => $locationPrices['rent_2room'],
                        'is_by_homeowner' => true
                    ];
                    
                    // 3-комнатные
                    $categories[] = [
                        'name' => 'rent_3room_' . $locationId,
                        'type' => 'flatrent',
                        'region' => [$locationId],
                        'room' => [3],
                        'price' => $locationPrices['rent_3room'],
                        'is_by_homeowner' => true
                    ];
                    
                    // Студии
                    $categories[] = [
                        'name' => 'rent_studio_' . $locationId,
                        'type' => 'flatrent',
                        'region' => [$locationId],
                        'room' => [9],
                        'price' => $locationPrices['rent_studio'],
                        'is_by_homeowner' => true
                    ];
                }
                
                // Продажа квартир
                if ($categoryType === 'sale') {
                    // 1-комнатные
                    $categories[] = [
                        'name' => 'sale_1room_' . $locationId,
                        'type' => 'flatsale',
                        'region' => [$locationId],
                        'room' => [1],
                        'price' => $locationPrices['sale_1room'],
                        'is_by_homeowner' => true
                    ];
                    
                    // 2-комнатные
                    $categories[] = [
                        'name' => 'sale_2room_' . $locationId,
                        'type' => 'flatsale',
                        'region' => [$locationId],
                        'room' => [2],
                        'price' => $locationPrices['sale_2room'],
                        'is_by_homeowner' => true
                    ];
                    
                    // 3-комнатные
                    $categories[] = [
                        'name' => 'sale_3room_' . $locationId,
                        'type' => 'flatsale',
                        'region' => [$locationId],
                        'room' => [3],
                        'price' => $locationPrices['sale_3room'],
                        'is_by_homeowner' => true
                    ];
                    
                    // Студии
                    $categories[] = [
                        'name' => 'sale_studio_' . $locationId,
                        'type' => 'flatsale',
                        'region' => [$locationId],
                        'room' => [9],
                        'price' => $locationPrices['sale_studio'],
                        'is_by_homeowner' => true
                    ];
                }
            }
        }
        
        return $categories;
    }

    /**
     * Запускает многопоточную обработку категорий
     * @param array $categories
     */
    private function handleParallelProcessing(array $categories): void
    {
        // Создаем рантаймы для каждого потока
        $runtimeList = array_map(fn() => new Runtime(), range(0, $this->num_threads - 1));
        $this->logger->info(self::MESSAGES['NUMBER_OF_THREADS'] . count($runtimeList), [], self::COMMAND_NAME);

        // Разбиваем категории на группы по количеству потоков
        $categoryChunks = array_chunk($categories, ceil(count($categories) / $this->num_threads));
        
        // Получаем данные, необходимые для всех потоков
        $metroStations = Metro::findByCoordinatesAll();
        $regions = Cities::findAllByParentId();
        $cities = Cities::findAll();
        $proxies = Proxy::findAllByNumber([1]);
        $simpleToken = CianAuthorizedUsers::findOne(1)['simple'];
        
        // Запускаем задачи в каждом потоке
        foreach ($categoryChunks as $index => $chunk) {
            $this->futureList[] = $runtimeList[$index]->run(function ($index, $chunk, $proxies, $simpleToken, $metroStations, $regions, $cities) {
                try {
                    // Инициализация в отдельном потоке
                    ini_set('memory_limit', '2G');
                    require_once __DIR__ . '/../../vendor/autoload.php';
                    $container = require __DIR__ . '/../../src/Config/container.php';
                    
                    // Создаем экземпляр текущего класса для обработки в потоке
                    $parser = new ParseCianMultiThread();
                    
                    // Создаем логгер для этого потока
                    $tempLog = new Logger('info');
                    $tempLog->pushHandler(new RotatingFileHandler(self::PATH_TO_TEMP_LOG . $index . '.log', 2, Logger::INFO));
                    $tempLog->pushHandler(new StreamHandler('php://stdout'));
                    
                    // Парсим каждую категорию в этом потоке
                    $results = [];
                    foreach ($chunk as $category) {
                        $tempLog->info(self::MESSAGES['TASK_INFO_START'], [
                            'category' => $category['name'],
                            'thread' => $index
                        ]);
                        
                        $items = $parser->parseCategory($category, $proxies, $simpleToken, $metroStations, $regions, $cities, $tempLog);
                        
                        $tempLog->info(self::MESSAGES['TASK_INFO_ITEMS'], [
                            'category' => $category['name'],
                            'thread' => $index,
                            'count' => count($items)
                        ]);
                        
                        $results[$category['name']] = count($items);
                        
                        $tempLog->info(self::MESSAGES['TASK_INFO_END'], [
                            'category' => $category['name'],
                            'thread' => $index
                        ]);
                    }
                    
                    return $results;
                } catch (\Throwable $e) {
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            }, [$index, $chunk, $proxies, $simpleToken, $metroStations, $regions, $cities]);
        }
        
        // Ожидаем завершения всех задач
        foreach ($this->futureList as $future) {
            $result = $future->value();
            $this->logger->info('Результаты парсинга: ', $result, self::COMMAND_NAME);
        }
    }
    
    /**
     * Парсит одну категорию
     * @param array $category Параметры категории
     * @param array $proxies Список прокси
     * @param string $simpleToken Токен авторизации
     * @param array $metroStations Станции метро
     * @param array $regions Регионы
     * @param array $cities Города
     * @param Logger $log Логгер для потока
     * @return array Обработанные объявления
     */
    public function parseCategory(array $category, array $proxies, string $simpleToken, array $metroStations, array $regions, array $cities, Logger $log): array
    {
        $client = new Client();
        $items = [];
        $itemsBatch = [];
        $processedItems = [];
        $metroCache = [];
        $regionCache = [];
        $startTime = microtime(true);
        $gaid = $this->gaid();
        $proxy = $this->getAndRemoveRandomProxy($proxies);
        $simple = $simpleToken;
        
        // Получение токена
        try {
            $tokenData = $this->getAuthToken($client, $simple, $gaid, $proxy);
            if ($tokenData) {
                $simple = 'simple ' . $tokenData;
            }
        } catch (Exception $e) {
            $log->error('Ошибка получения токена: ' . $e->getMessage());
        }
        
        // Основной цикл парсинга
        $page = 1;
        $maxPages = 20; // Ограничим количество страниц для каждой категории
        
        while ($page <= $maxPages) {
            try {
                if (empty($proxies)) {
                    $log->info(self::MESSAGES['PROXY_EMPTY']);
                    $proxies = Proxy::findAllByNumber([1]);
                    if (empty($proxies)) {
                        break; // Если прокси всё равно нет, прерываем цикл
                    }
                }
                
                $proxy = $this->getAndRemoveRandomProxy($proxies);
                
                // Подготовка параметров запроса
                $postParams = $this->preparePostParams($category, $page);
                
                // Выполнение запроса к API
                $response = $client->request('POST', 'https://api.cian.ru/search-offers/v4/search-offers-mobile-apps/', [
                    'body' => $postParams,
                    'headers' => $this->getHeaders($simple, $gaid, strlen($postParams)),
                    'timeout' => 3.0,
                    'connect_timeout' => 3.0,
                    'allow_redirects' => true,
                    'proxy' => $proxy,
                    'http_errors' => false
                ]);
                
                $statusCode = $response->getStatusCode();
                
                if ($statusCode != 200) {
                    $log->error("Ошибка запроса: $statusCode", ['proxy' => $proxy]);
                    usleep(rand(200000, 500000));
                    continue;
                }
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!isset($data['items']) || empty($data['items'])) {
                    $log->info("Нет объявлений на странице $page");
                    break; // Если нет объявлений, значит достигли конца выдачи
                }
                
                // Обработка объявлений
                foreach ($data['items'] as $item) {
                    // Проверка на дубликаты
                    if (isset($processedItems[$item['offer']['id']])) {
                        continue;
                    }
                    
                    // Проверка даты
                    $inputDate = Carbon::parse($item['offer']['creationDate'])->format('Y-m-d');
                    $currentDate = Carbon::now('Europe/Moscow')->format('Y-m-d');
                    $sevenDaysAgo = Carbon::now('Europe/Moscow')->subDays(7)->format('Y-m-d');
                    
                    $isInRange = $inputDate >= $sevenDaysAgo && $inputDate <= $currentDate;
                    if (!$isInRange) {
                        $processedItems[$item['offer']['id']] = true;
                        continue;
                    }
                    
                    // Получение местоположения
                    $locationId = $this->getCachedRegion(
                        $item['offer']['geo']['userInput'],
                        $regions,
                        $cities,
                        $regionCache
                    );
                    
                    if ($locationId === 0) {
                        $processedItems[$item['offer']['id']] = true;
                        continue;
                    }
                    
                    // Получение метро
                    $metroId = $this->getCachedMetroStation(
                        $item['offer']['geo']['coordinates']['lat'],
                        $item['offer']['geo']['coordinates']['lng'],
                        $metroStations,
                        $metroCache
                    );
                    
                    if (is_null($metroId)) {
                        $processedItems[$item['offer']['id']] = true;
                        continue;
                    }
                    
                    // Добавляем в пакет
                    $itemsBatch[] = $this->getItemData($item, $metroId['id'], $locationId);
                    $processedItems[$item['offer']['id']] = true;
                    
                    // Когда пакет заполнен - обрабатываем
                    if (count($itemsBatch) >= self::BATCH_SIZE) {
                        $this->processBatch($itemsBatch, $log);
                        $items = array_merge($items, $itemsBatch);
                        $itemsBatch = [];
                    }
                }
                
                // Переход на следующую страницу
                $page++;
                
                // Задержка между запросами
                usleep(rand(500000, 1000000));
                
            } catch (Exception $e) {
                $log->error("Ошибка парсинга: " . $e->getMessage());
                usleep(rand(500000, 1000000));
            }
        }
        
        // Обрабатываем оставшиеся элементы
        if (!empty($itemsBatch)) {
            $this->processBatch($itemsBatch, $log);
            $items = array_merge($items, $itemsBatch);
        }
        
        return $items;
    }

    /**
     * Получает токен авторизации
     * @param Client $client
     * @param string $simple
     * @param string $gaid
     * @param string $proxy
     * @return string|null
     * @throws GuzzleException
     */
    private function getAuthToken(Client $client, string $simple, string $gaid, string $proxy): ?string
    {
        try {
            $response = $client->request('GET', 'https://api.cian.ru/mobile-assist/token/', [
                'headers' => $this->getHeaders($simple, $gaid, false),
                'timeout' => 3.0,
                'connect_timeout' => 3.0,
                'allow_redirects' => true,
                'verify' => false,
                'http_errors' => false,
                'proxy' => $proxy,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!empty($data) && array_key_exists('guid', $data)) {
                $hash = $this->encryptToHash($data['guid'], $gaid);
                
                $response = $client->request('POST', 'https://api.cian.ru/mobile-assist/token/', [
                    'headers' => $this->getHeaders($simple, $gaid, 75),
                    'body' => json_encode(['hash' => $hash]),
                    'timeout' => 3.0,
                    'connect_timeout' => 3.0,
                    'allow_redirects' => true,
                    'verify' => false,
                    'http_errors' => false,
                    'proxy' => $proxy,
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data) && array_key_exists('token', $data)) {
                    return $data['token'];
                }
            }
        } catch (Exception $e) {
            // Ошибка получения токена
        }
        
        return null;
    }
    
    /**
     * Формирует параметры запроса на основе локации и категории
     * @param array $locationConfig Настройки локации
     * @param array $categoryConfig Настройки категории
     * @param int $page Номер страницы
     * @param array $options Дополнительные опции
     * @return string JSON строка с параметрами запроса
     */
    private function buildPostParams(array $locationConfig, array $categoryConfig, int $page = 1, array $options = []): string
    {
        // Базовые параметры запроса
        $params = [
            'query' => [
                '_type' => $categoryConfig['type'] ?? 'flatrent',
                'region' => ['type' => 'terms', 'value' => $locationConfig['region'] ?? [-1]],
                'room' => ['type' => 'terms', 'value' => $categoryConfig['rooms'] ?? [1, 2, 3, 9]],
                'object_type' => ['type' => 'terms', 'value' => [0]],
                'building_status' => ['type' => 'term', 'value' => 0],
                'engine_version' => ['type' => 'term', 'value' => '2'],
                'page' => ['type' => 'term', 'value' => $page],
                'limit' => ['type' => 'term', 'value' => $options['limit'] ?? 20],
                'commission_type' => ['type' => 'term', 'value' => 0],
                'is_by_homeowner' => ['type' => 'term', 'value' => $options['is_by_homeowner'] ?? true],
                'for_day' => ['type' => 'term', 'value' => '0'],
                'with_neighbors' => ['type' => 'term', 'value' => false],
                'newbuilding_results_type' => ['type' => 'term', 'value' => "offers"],
            ]
        ];
        
        // Настройка цен
        if (isset($categoryConfig['price'])) {
            $minPrice = $categoryConfig['price']['min'];
            $maxPrice = $categoryConfig['price']['max'];
            
            // Если установлена опция случайных цен, генерируем случайные значения в заданном диапазоне
            if (!empty($options['randomize_price'])) {
                $minPriceRange = isset($options['min_price_range']) ? $options['min_price_range'] : 500;
                $maxPriceRange = isset($options['max_price_range']) ? $options['max_price_range'] : 5000;
                
                $minPrice = rand($minPrice, $minPrice + $minPriceRange);
                $maxPrice = rand($maxPrice - $maxPriceRange, $maxPrice);
            }
            
            $params['query']['price'] = [
                'type' => 'range',
                'value' => [
                    'gte' => $minPrice,
                    'lte' => $maxPrice
                ]
            ];
        }
        
        // Настройка сортировки
        if (isset($options['sort'])) {
            $params['query']['sort'] = ['type' => 'term', 'value' => $options['sort']];
        } else {
            $params['query']['sort'] = ['type' => 'term', 'value' => 'creation_date_desc'];
        }
        
        // Период публикации (если указан)
        if (isset($options['publish_period'])) {
            $params['query']['publish_period'] = ['type' => 'term', 'value' => $options['publish_period']];
        }
        
        // Дополнительные параметры, которые могут быть переданы
        if (isset($options['additional_params']) && is_array($options['additional_params'])) {
            foreach ($options['additional_params'] as $key => $value) {
                $params['query'][$key] = $value;
            }
        }
        
        return json_encode($params);
    }

    /**
     * @param string $locationName
     * @return int Возвращает идентификатор локации для поиска
     *
     */
    private function getRegionIdForLocationName(string $locationName): int
    {
        $regionIds = [
            'Москва' => -1,
            'Санкт-Петербург' => -2,
            'Новосибирск' => 4897,
            'Екатеринбург' => 4743,
            'Казань' => 4777,
            'Красноярск' => 4827,
            'Нижний Новгород' => 4885,
            'Челябинск' => 5048,
            'Уфа' => 176245,
            'Самара' => 4966,
            'Ростов-на-Дону' => 4959,
            'Краснодар' => 4820,
            'Омск' => 4914,
            'Воронеж' => 4713,
            'Пермь' => 4927,
            'Волгоград' => 4704,
            'Саратов' => 4969,
            'Тюмень' => 5024,
            'Тверь' => 176083,
        ];

        return $regionIds[$locationName];
    }

    /**
     * Создает набор параметров запросов для всех комбинаций локаций и категорий
     * @param array $locationCategoryPairs
     * @return array Массив параметров запросов
     * room 1, 2, 3, 4, 5, 6 (комнаты), 7 - Свободная планировка, 9 - Студия
     * Категория _type (аренда/продажа)
     * Условия сделки commission_type (0 - Без комиссии)
     * Срок аренды for_day (0 - От года)
     * Тип сделки is_by_homeowner (true -От собственника)
     * Цена price
     * Об объявлении publish_period (Объявления за последний час)
     * Регион region
     * @throws Exception
     */
    private function generateRequestParams(array $locationCategoryPairs): array
    {
        foreach ($locationCategoryPairs AS $key => $value) {
            $locationName = $value['location_name'];
            $categoryId = $value['category_id'];

            $region = $this->getRegionIdForLocationName($locationName);
            $price  = $this->getPriceRangeForLocationAndCategory($locationName, $categoryId);

            $baseRequestParams = [
                'query' => [
                    '_type'           => $categoryId == 1 ? 'flatrent': 'flatsale',
                    'region'          => ['type' => 'terms', 'value' => [$region]],
                    'room'            => ['type' => 'terms', 'value' => [1, 2, 3, 4, 5, 6, 7, 9]],
                    'object_type'     => ['type' => 'terms', 'value' => [0]],
                    'building_status' => ['type' => 'term', 'value' => 0],
                    'engine_version'  => ['type' => 'term', 'value' => '2'],
                    'page'            => ['type' => 'term', 'value' => 1],
                    'limit'           => ['type' => 'term', 'value' => 10],
                    'price'           => ['type' => 'range', 'value' => $price],
                    'is_by_homeowner' => ['type' => 'term', 'value' => true],
                    'publish_period'  => ['type' => 'term', 'value' => 3600],
                    'sort'            => ['type' => 'term', 'value' => 'creation_date_desc'],
                    'with_neighbors'  => ['type' => 'term', 'value' => false],
                    'newbuilding_results_type'  => ['type' => 'term', 'value' => "offers"],
                ]
            ];

            if ($categoryId == 1) {
                $baseRequestParams['query']['commission_type'] = ['type' => 'term', 'value' => 0];
                $baseRequestParams['query']['for_day'] = ['type' => 'term', 'value' => '0'];
            }

            $locationCategoryPairs[$key]['json'] = json_encode($baseRequestParams);
        }

        return $locationCategoryPairs;
    }

    /**
     * Возвращает ценовой диапазон для указанной локации и категории
     * @param string $locationName
     * @param int $categoryId
     * @return array Ценовой диапазон random [min, max]
     *
     * @throws Exception
     */
    private function getPriceRangeForLocationAndCategory(string $locationName, int $categoryId): array
    {
        $priceSettings = [
            'Москва' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Санкт-Петербург' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Новосибирск' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Екатеринбург' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Казань' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Красноярск' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Нижний Новгород' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Челябинск' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Уфа' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Самара' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Ростов-на-Дону' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Краснодар' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Омск' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Воронеж' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Пермь' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Волгоград' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Саратов' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Тюмень' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
            'Тверь' => [
                1 => ['gte' => rand(17500, 18000), 'lte' => rand(145000, 150000)],
                2 => ['gte' => random_int(5000, 7050) * 1000, 'lte' => random_int(800000, 900000) * 1000]
            ],
        ];

        return $priceSettings[$locationName][$categoryId];
    }

    /**
     * Формирует заголовки для запроса
     * @param string $simple
     * @param string $gaid
     * @param bool|int $contentLength
     * @return array
     */
    private function getHeaders(string $simple, string $gaid, bool|int $contentLength): array
    {
        $headers = [
            'Host' => 'api.cian.ru',
            'authorization' => $simple,
            'os' => 'android',
            'buildnumber' => '2.302.0',
            'versioncode' => '23020300',
            'device' => 'Phone',
            'applicationid' => $gaid,
            'crossapplicationid' => $gaid,
            'package' => 'ru.cian.main',
            'user-agent' => "Cian/2.302.0 (Android; 23020300; Phone; sdk_gphone64_x86_64; 32; $gaid)",
            'accept' => '*/*',
            'content-type' => 'application/json; charset=utf-8',
            'accept-encoding' => 'gzip',
        ];
        
        if ($contentLength !== false) {
            $headers['Content-Length'] = $contentLength;
        }
        
        return $headers;
    }
    
    /**
     * Генерация уникального идентификатора
     * @return string
     */
    private function gaid(): string
    {
        return strtolower(sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535)
        ));
    }
    
    /**
     * Шифрование хэша для авторизации
     * @param string $guid
     * @param string $gaid
     * @return string
     */
    private function encryptToHash(string $guid, string $gaid): string
    {
        $user_agent = "Cian/2.302.0 (Android; 23020300; Phone; sdk_gphone64_x86_64; 32; $gaid)";
        $hash = $guid . "_" . $user_agent . "_ac83d1d66254adbc668fd4667e2517614d861641";
        
        return hash('sha256', $hash);
    }
    
    /**
     * Получает и удаляет случайный прокси из списка
     * @param array &$proxies
     * @return string
     */
    private function getAndRemoveRandomProxy(array &$proxies): string
    {
        if (empty($proxies)) {
            return '';
        }
        
        $index = array_rand($proxies);
        $proxy = $proxies[$index];
        unset($proxies[$index]);
        $proxies = array_values($proxies);
        
        return $proxy;
    }
    
    /**
     * Получение данных объявления для сохранения
     * @param array $item
     * @param int $metroId
     * @param array $locationId
     * @return array
     */
    private function getItemData(array $item, int $metroId, array $locationId): array
    {
        $title = $this->functions->clearTitle($item['offer']['formattedFullInfo']);
        $param = $this->parseApartmentString($item['offer']['formattedFullInfo']);
        $address = $this->extractStreet($item['offer']['geo']['address']);
        
        return [
            'id' => $item['offer']['id'],
            'category_id' => 24,
            'group_id' => 1,
            'source_id' => 3,
            'room_id' => $this->functions->getRoomId($title),
            'city_id' => $locationId[0],
            'title' => $title,
            'address' => $item['offer']['geo']['userInput'],
            'price' => $this->functions->getInt($item['offer']['formattedFullPrice']),
            'phone' => $this->functions->clearPhone($item['offer']['agent']['phones'][0]),
            'url' => $item['offer']['siteUrl'],
            'lat' => $item['offer']['geo']['coordinates']['lat'],
            'lng' => $item['offer']['geo']['coordinates']['lng'],
            'metro_id' => $metroId,
            'raised_id' => 1,
            'status_id' => 1,
            'square_meters' => $param['square_meters'],
            'total_floors' => $param['total_floors'],
            'floor' => $param['floor'],
            'locality_id' => $locationId[1],
            'street' => $address['street'],
            'house' => $address['house'],
        ];
    }
    
    /**
     * Пакетная обработка объявлений
     * @param array $items
     * @param Logger $log
     */
    private function processBatch(array $items, Logger $log): void
    {
        try {
            DB::table('items')->upsert($items, ['id', 'source_id'],
                ['updated_at', 'square_meters', 'total_floors', 'floor', 'locality_id', 'street', 'house']);
            
            $log->info("Добавлено объявлений", [
                'count' => count($items),
            ]);
            
        } catch (Exception $e) {
            $log->error("Ошибка пакетной обработки", [
                'error' => $e->getMessage(),
                'count' => count($items)
            ]);
        }
    }
    
    /**
     * Получение ближайшего метро с кэшированием
     */
    private function getCachedMetroStation(float $lat, float $lng, array $metroStations, array &$metroCache): ?array
    {
        $key = "$lat:$lng";
        
        if (!isset($metroCache[$key])) {
            $metroCache[$key] = $this->functions->getNearestMetroStation($lat, $lng, $metroStations);
            
            if (count($metroCache) > 1000) {
                array_shift($metroCache);
            }
        }
        
        return $metroCache[$key];
    }
    
    /**
     * Получение региона с кэшированием
     */
    private function getCachedRegion(string $userInput, array $regions, array $cities, array &$regionCache): int|array
    {
        $key = md5($userInput);
        
        if (!isset($regionCache[$key])) {
            $regionCache[$key] = $this->functions->filterRegion($regions, $cities, $userInput);
            
            if (count($regionCache) > 1000) {
                array_shift($regionCache);
            }
        }
        
        return $regionCache[$key];
    }
    
    /**
     * Извлекает параметры квартиры из строки
     */
    #[ArrayShape(['square_meters' => "float|null", 'floor' => "int|null", 'total_floors' => "int|null"])]
    private function parseApartmentString(string $str): array
    {
        $square = null;
        $floor = null;
        $totalFloors = null;
        
        $parts = array_map('trim', explode('•', $str));
        
        foreach ($parts as $part) {
            if (str_contains($part, 'м²') || str_contains($part, 'м2')) {
                if (preg_match('/(\d+(?:[.,]\d+)?)/', $part, $matches)) {
                    $square = (float)str_replace(',', '.', $matches[1]);
                }
            }
            
            if (str_contains($part, 'этаж')) {
                if (preg_match('/(\d+)\/(\d+)/', $part, $matches)) {
                    $floor = (int)$matches[1];
                    $totalFloors = (int)$matches[2];
                }
            }
        }
        
        return [
            'square_meters' => $square,
            'floor' => $floor,
            'total_floors' => $totalFloors
        ];
    }
    
    /**
     * Извлекает название улицы из массива адресных данных
     */
    #[ArrayShape(['street' => "mixed|null", 'house' => "mixed|null"])]
    private function extractStreet(array $addressParts): array
    {
        $streetKeywords = [
            'улица',
            'проспект',
            'переулок',
            'бульвар',
            'набережная',
            'площадь',
            'аллея',
            'шоссе'
        ];
        
        $result = [
            'street' => null,
            'house' => null
        ];
        
        $lastElement = end($addressParts);
        
        if (isset($lastElement['name'])) {
            if (preg_match('/^(\d+(?:[АА-Яа-я])?(?:к\d+|с\d+|\/\d+)?|\d+[АА-Яа-я]к\d+)$/u', $lastElement['name'])) {
                $result['house'] = $lastElement['name'];
                array_pop($addressParts);
            }
        }
        
        foreach ($addressParts as $part) {
            if (!isset($part['fullName']) || !isset($part['name'])) {
                continue;
            }
            
            $fullName = mb_strtolower($part['fullName'], 'UTF-8');
            
            foreach ($streetKeywords as $keyword) {
                if (mb_stripos($fullName, $keyword) !== false) {
                    $result['street'] = $part['name'];
                    break 2;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Логирование сообщений
     */
    private function toLog(string $level, string $message, array $context = []): void
    {
        if ($this->log_status) {
            $this->log->log($level, $message, $context);
        }
    }
} 