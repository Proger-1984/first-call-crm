<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class CianParallelParser
{
    private ContainerInterface $container;
    private Logger $logger;
    private const TIMEOUT = 5000000; // 5 секунд

    public function __construct(ContainerInterface $container, Logger $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }


    /**
     * Парсит один поисковый запрос к API Циан
     * @throws GuzzleException
     */
    public function parseRequestData(array $requestData): int
    {
        $this->logger->info('Обрабатываем поиск: ', [
            'location_id'   => $requestData['location_id'],
            'location_name' => $requestData['location_name'],
            'category_id'   => $requestData['category_id'],
            'category_name' => $requestData['category_name']
        ]);

        print_r($requestData);

        $client = new Client();
        $processedItems = 0;
        $itemsBatch = [];
        $existingItems = $requestData['items'];
        $proxies = $requestData['proxies'];
        $authToken = $requestData['auth_token'];
        $searchParams = $requestData['json'];

        // Генерируем уникальный ID для запросов
        $gaid = $this->generateGuid();
        
        // Получаем случайный прокси для первоначального запроса токена
        $initialProxy = $this->getAndRemoveRandomProxy($proxies);

        while (true) {

            usleep(self::TIMEOUT);

            // Пытаемся получить новый токен через API
            try {
                $tokenData = $this->getAuthToken($client, $authToken, $gaid, $initialProxy);
                var_dump($tokenData);
                exit;
                if ($tokenData) {
                    $simple = 'simple ' . $tokenData;
                    $this->logger->info('Получен новый токен авторизации');
                } else {
                    $this->logger->warning('Не удалось получить новый токен, используем исходный');
                }
            } catch (Exception $e) {
                $this->logger->error('Ошибка получения токена: ' . $e->getMessage());
            }

        }



        // Парсим несколько страниц
        $maxPages = 5;
        for ($page = 1; $page <= $maxPages; $page++) {
            
            // Получаем случайный прокси для каждого запроса
            if (empty($proxies)) {
                $this->logger->warning('Закончились прокси для парсинга');
                break;
            }
            
            $proxy = $this->getAndRemoveRandomProxy($proxies);

            try {
                // Модифицируем параметры поиска для текущей страницы
                $params = json_decode($searchParams, true);
                $params['query']['page']['value'] = $page;
                $postBody = json_encode($params);

                $this->logger->info("Запрос к API Циан: страница {$page}, прокси: {$proxy}");

                // Выполняем запрос к API Циан
                $response = $client->request('POST', 'https://api.cian.ru/search-offers/v4/search-offers-mobile-apps/', [
                    'body' => $postBody,
                    'headers' => $this->getHeaders($simple, $gaid, strlen($postBody)),
                    'timeout' => 10.0,
                    'connect_timeout' => 5.0,
                    'allow_redirects' => true,
                    'proxy' => $proxy,
                    'http_errors' => false
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode !== 200) {
                    $this->logger->warning("Ошибка API: {$statusCode}, прокси: {$proxy}");
                    continue;
                }

                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['items']) || empty($data['items'])) {
                    $this->logger->info("Нет объявлений на странице {$page}");
                    break; // Достигли конца выдачи
                }

                // Обрабатываем объявления
                foreach ($data['items'] as $item) {
                    $itemId = $item['offer']['id'] ?? null;

                    if (!$itemId || in_array($itemId, $existingItems)) {
                        continue; // Пропускаем дубликаты
                    }

                    // Проверяем дату публикации (только свежие объявления)
                    $creationDate = $item['offer']['creationDate'] ?? null;
                    if ($creationDate) {
                        $itemDate = Carbon::parse($creationDate);
                        $weekAgo = Carbon::now()->subDays(7);

                        if ($itemDate->lt($weekAgo)) {
                            continue; // Пропускаем старые объявления
                        }
                    }

                    // Добавляем в пакет для сохранения
                    $itemsBatch[] = [
                        'external_id' => $itemId,
                        'title' => $item['offer']['formattedFullInfo'] ?? '',
                        'price' => $this->extractPrice($item['offer']['formattedFullPrice'] ?? ''),
                        'url' => $item['offer']['siteUrl'] ?? '',
                        'address' => $item['offer']['geo']['userInput'] ?? '',
                        'lat' => $item['offer']['geo']['coordinates']['lat'] ?? null,
                        'lng' => $item['offer']['geo']['coordinates']['lng'] ?? null,
                        'source_id' => 3, // Циан
                        'location_id' => $requestData['location_id'] ?? null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ];

                    // Сохраняем пакетами по 20 объявлений
                    if (count($itemsBatch) >= 20) {
                        $this->saveBatch($itemsBatch);
                        $processedItems += count($itemsBatch);
                        $itemsBatch = [];
                    }
                }

                // Задержка между запросами
                usleep(rand(500000, 1500000)); // 0.5-1.5 секунды

            } catch (Exception $e) {
                $this->logger->error("Ошибка парсинга страницы {$page}: " . $e->getMessage());
                continue;
            }
        }

        // Сохраняем оставшиеся элементы
        if (!empty($itemsBatch)) {
            $this->saveBatch($itemsBatch);
            $processedItems += count($itemsBatch);
        }

        $this->logger->info("Обработано объявлений: {$processedItems} для поиска: " . ($requestData['name'] ?? 'unknown'));

        return $processedItems;
    }

    /**
     * Сохраняет пакет объявлений в базу данных
     */
    private function saveBatch(array $items): void
    {
        try {
            DB::table('listings')->upsert($items, ['external_id', 'source_id'],
                ['updated_at', 'title', 'price', 'url', 'address', 'lat', 'lng']);

            $this->logger->info("Сохранен пакет из " . count($items) . " объявлений");
        } catch (Exception $e) {
            $this->logger->error("Ошибка сохранения пакета: " . $e->getMessage());
        }
    }

    /**
     * Извлекает цену из строки
     */
    private function extractPrice(string $priceString): ?int
    {
        $numbers = preg_replace('/[^\d]/', '', $priceString);
        return $numbers ? (int)$numbers : null;
    }

    /**
     * Генерирует GUID
     */
    private function generateGuid(): string
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
     * Получает заголовки для API запроса
     */
    private function getApiHeaders(string $authToken, string $gaid, int $contentLength): array
    {
        return [
            'Host' => 'api.cian.ru',
            'authorization' => $authToken,
            'os' => 'android',
            'buildnumber' => '2.302.0',
            'versioncode' => '23020300',
            'device' => 'Phone',
            'applicationid' => $gaid,
            'crossapplicationid' => $gaid,
            'package' => 'ru.cian.main',
            'user-agent' => "Cian/2.302.0 (Android; 23020300; Phone; sdk_gphone64_x86_64; 32; {$gaid})",
            'accept' => '*/*',
            'content-type' => 'application/json; charset=utf-8',
            'accept-encoding' => 'gzip',
            'Content-Length' => $contentLength
        ];
    }

    /**
     * Формирует заголовки для запроса (общий метод)
     */
    private function getHeaders(string $authToken, string $gaid, bool|int $contentLength): array
    {
        $headers = [
            'Host' => 'api.cian.ru',
            'authorization' => $authToken,
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
     * Получает токен авторизации
     * @throws GuzzleException
     */
    private function getAuthToken(Client $client, string $authToken, string $gaid, string $proxy): ?string
    {
        try {
            $response = $client->request('GET', 'https://api.cian.ru/mobile-assist/token/', [
                'headers' => $this->getHeaders($authToken, $gaid, false),
                'timeout' => 3.0,
                'connect_timeout' => 3.0,
                'allow_redirects' => true,
                'verify' => false,
                'http_errors' => false,
              //  'proxy' => $proxy,
                'proxy' => 'http://192.168.0.107:8866',
//                'curl' => [
//                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
//                    CURLOPT_SSL_CIPHER_LIST => "AESGCM:CHACHA20:POLY1305",
//                ]
            ]);

            $code = $response->getStatusCode();
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!empty($data) && array_key_exists('guid', $data)) {
                $hash = $this->encryptToHash($data['guid'], $gaid);
                
                $response = $client->request('POST', 'https://api.cian.ru/mobile-assist/token/', [
                    'headers' => $this->getHeaders($authToken, $gaid, 75),
                    'body' => json_encode(['hash' => $hash]),
                    'timeout' => 3.0,
                    'connect_timeout' => 3.0,
                    'allow_redirects' => true,
                    'verify' => false,
                    'http_errors' => false,
                  //  'proxy' => $proxy,
                    'proxy' => 'http://192.168.0.107:8866',
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data) && array_key_exists('token', $data)) {
                    return $data['token'];
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Ошибка получения токена: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Шифрование хэша для авторизации
     */
    private function encryptToHash(string $guid, string $gaid): string
    {
        $user_agent = "Cian/2.302.0 (Android; 23020300; Phone; sdk_gphone64_x86_64; 32; $gaid)";
        $hash = $guid . "_" . $user_agent . "_ac83d1d66254adbc668fd4667e2517614d861641";
        
        return hash('sha256', $hash);
    }

    /**
     * Получает и удаляет случайный прокси из списка
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
} 