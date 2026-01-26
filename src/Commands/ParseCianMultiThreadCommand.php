<?php

namespace App\Commands;

use App\Services\LogService;
use App\Services\SubscriptionService;
use App\Services\CianParallelParser;
use App\Models\LocationProxy;
use App\Models\CianAuth;
use Exception;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
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
use Throwable;

class ParseCianMultiThreadCommand extends Command
{
    private LogService $logger;
    private SubscriptionService $subscriptionService;
    private int $num_threads;
    private array $futureList = [];

    private const THREADS_COUNT = 19;
    private const SOURCE_ID = 3; // Циан
    private const COMMAND_NAME = 'parse-cian-multi';

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
            unset($locationCategoryPairs);

            // Загружаем прокси/объявления/токены для Циан
            $optimizedParams = [];
            foreach ($requestParams AS $requestData) {
                $locationId = $requestData['location_id'];
                $categoryId = $requestData['category_id'];

                $proxies = $this->loadProxiesForSource(self::SOURCE_ID, $locationId, $categoryId);
                $authToken = $this->loadCianAuthToken($categoryId, $locationId);
                if (empty($proxies) || empty($authToken)) {
                    $this->logger->warning('Не хватает данных для работы с Циан', [
                        'location_id' => $locationId,
                        'category_id' => $categoryId,
                        'count_proxy' => count($proxies),
                        'auth_token'  => $authToken,
                    ], self::COMMAND_NAME);

                    continue;
                }

                // Загружаем существующие объявления для локации/категории
                $requestData['items'] = $this->loadExistingItemsForLocation($locationId, $categoryId, self::SOURCE_ID);

                $requestData['proxies'] = $proxies;
                $requestData['auth_token'] = $authToken;
                $optimizedParams[] = $requestData;
            }

            unset($requestParams);
            if (empty($optimizedParams)) {
                $this->logger->warning('Недостаточно данных для парсинга', [], self::COMMAND_NAME);
                exec($cmd);
                return 0;
            }

            $this->logger->info(self::MESSAGES['NUMBER_OF_TASKS'] . count($optimizedParams), [], self::COMMAND_NAME);

            // Если поисков меньше чем потоков, уменьшаем количество потоков
            if (count($optimizedParams) < $this->num_threads) {
                $this->num_threads = count($optimizedParams);
            }

            // Запускаем многопоточную обработку
            $this->handleParallelProcessing($optimizedParams);

        } catch (Exception $e) {
            $this->logger->error('Ошибка выполнения: ' . $e->getMessage(), [], self::COMMAND_NAME);
        }


        $this->logger->info('Скрипт циан завершил работу', [], self::COMMAND_NAME);

        exec($cmd);
        return 0;
    }

    /**
     * Запускает многопоточную обработку категорий
     * @param array $requestParams
     */
    private function handleParallelProcessing(array $requestParams): void
    {
        // Создаем рантаймы для каждого потока
        $runtimeList = array_map(fn() => new Runtime(), range(0, $this->num_threads - 1));
        $this->logger->info(self::MESSAGES['NUMBER_OF_THREADS'] . count($runtimeList), [], self::COMMAND_NAME);

        // Разбиваем поиски на группы по количеству потоков
        $requestChunks = array_chunk($requestParams, ceil(count($requestParams) / $this->num_threads));
        
        // Запускаем задачи в каждом потоке
        foreach ($requestChunks as $index => $chunk) {
            $this->futureList[] = $runtimeList[$index]->run(function ($index, $chunk) {
                try {
                    // Инициализация в отдельном потоке - аналогично старому коду
                    ini_set('memory_limit', '1G');
                    require_once __DIR__ . '/../../vendor/autoload.php';
                    
                    // Загружаем конфигурацию приложения (инициализирует БД)
                    $config = require __DIR__ . '/../../bootstrap/app.php';
                    
                    // Загружаем контейнер зависимостей
                    $containerLoader = require __DIR__ . '/../../bootstrap/container.php';
                    $container = $containerLoader($config);
                    
                    // Создаем логгер для этого потока
                    $tempLog = new Logger('cian_thread_' . $index);
                    $tempLog->pushHandler(new StreamHandler('php://stdout'));
                    
                    // Создаем парсер
                    $parser = new CianParallelParser($container, $tempLog);
                    
                    // Парсим chunk в этом потоке
                    return $parser->parseRequestData($chunk[$index]);

                } catch (Throwable $e) {
                    return ['success' => false, 'error' => $e->getMessage(), 'thread' => $index];
                }
            }, [$index, $chunk]);
        }
        
        // Ожидаем завершения всех задач
        foreach ($this->futureList as $future) {
            $result = $future->value();
            $this->logger->info('Результаты парсинга: ', $result, self::COMMAND_NAME);
        }
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

            // Добавляем метаданные для поиска
            $locationCategoryPairs[$key]['name'] = $locationName . '_' . ($categoryId == 1 ? 'rent' : 'sale');
            $locationCategoryPairs[$key]['location_id'] = $this->getLocationIdByName($locationName);
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
     * Подгружает список прокси для указанного источника
     * @param int $sourceId ID источника (1-Авито, 2-Юла, 3-Циан)
     * @param int $locationId
     * @param int $categoryId
     * @return array Массив прокси в простом формате
     */
    private function loadProxiesForSource(int $sourceId, int $locationId, int $categoryId): array
    {
        try {
            // Получаем все прокси для источника
            $locationProxies = LocationProxy::getProxiesForSource($sourceId, $locationId, $categoryId);
            
            $proxies = [];
            foreach ($locationProxies as $locationProxy) {
                $proxies[] = $locationProxy->getSimpleFormat();
            }
            
            $this->logger->info(
                "Загружено прокси для источника $sourceId: " . count($proxies),
                ['source_id' => $sourceId, 'proxies_count' => count($proxies)], 
                self::COMMAND_NAME
            );
            
            return $proxies;
            
        } catch (Exception $e) {
            $this->logger->error(
                "Ошибка загрузки прокси для источника $sourceId: " . $e->getMessage(),
                ['source_id' => $sourceId, 'error' => $e->getMessage()], 
                self::COMMAND_NAME
            );
            return [];
        }
    }

    /**
     * Подгружает ID объявлений по указанной локации/категории для проверки дубликатов
     * @param int $locationId ID локации
     * @param int $categoryId
     * @param int $sourceId
     * @return array Массив ID объявлений
     */
    private function loadExistingItemsForLocation(int $locationId, int $categoryId, int $sourceId): array
    {
        try {
            $existingItemIds = DB::table('listings')
                ->where('location_id', $locationId)
                ->where('category_id', $categoryId)
                ->where('source_id', $sourceId)
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->pluck('external_id')
                ->toArray();
            
            $this->logger->info(
                "Загружено существующих объявлений для локации $locationId: " . count($existingItemIds),
                [
                    'location_id' => $locationId,
                    'category_id' => $categoryId,
                    'source_id'   => $sourceId,
                    'items_count' => count($existingItemIds)
                ],
                self::COMMAND_NAME
            );
            
            return $existingItemIds;
            
        } catch (Exception $e) {
            $this->logger->error(
                "Ошибка загрузки объявлений для локации $locationId: " . $e->getMessage(),
                ['location_id' => $locationId, 'category_id' => $categoryId, 'source_id' => $sourceId, 'error' => $e->getMessage()],
                self::COMMAND_NAME
            );
            return [];
        }
    }

    /**
     * Получает ID локации по её названию
     * @param string $locationName Название локации
     * @return int ID локации или 0 если не найдена
     */
    private function getLocationIdByName(string $locationName): int
    {
        try {
            $location = DB::table('locations')
                ->where('city', $locationName)
                ->first();
            
            return $location ? $location->id : 0;
            
        } catch (Exception $e) {
            $this->logger->error(
                "Ошибка поиска локации по названию '$locationName': " . $e->getMessage(),
                ['location_name' => $locationName, 'error' => $e->getMessage()], 
                self::COMMAND_NAME
            );
            return 0;
        }
    }

    /**
     * Загружает токен авторизации для Циан
     * @param int $categoryId
     * @param int $locationId
     * @return string|null Токен авторизации или null если не найден
     */
    private function loadCianAuthToken(int $categoryId, int $locationId): ?string
    {
        try {
            // Получаем активный токен авторизации
            $authToken = CianAuth::getActiveAuthToken($categoryId, $locationId);
            
            if ($authToken) {
                $this->logger->info(
                    'Загружен токен авторизации для Циан', 
                    ['token_length' => strlen($authToken)], 
                    self::COMMAND_NAME
                );
                
                return $authToken;
            }
            
            $this->logger->warning(
                'Не найден активный токен авторизации для Циан', 
                [], 
                self::COMMAND_NAME
            );
            
            return null;
            
        } catch (Exception $e) {
            $this->logger->error(
                'Ошибка загрузки токена авторизации для Циан: ' . $e->getMessage(), 
                ['error' => $e->getMessage()], 
                self::COMMAND_NAME
            );
            
            return null;
        }
    }
} 