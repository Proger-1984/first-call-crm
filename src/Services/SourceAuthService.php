<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Listing;
use App\Models\UserSourceCookie;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;

/**
 * Сервис для работы с авторизацией на источниках (CIAN, Avito)
 */
class SourceAuthService
{
    private const CIAN_PROXY_URL = 'http://cian-proxy:4829/handle';
    
    // Прокси для запросов к CIAN (можно вынести в конфиг)
    private const DEFAULT_PROXY = 'http://y4l7hTYp8s:BhRGBFh2mf@95.182.79.172:11223';
    
    // JA3 fingerprint для имитации Chrome
    private const DEFAULT_JA3 = '771,4865-4866-4867-49195-49199-49196-49200-52393-52392-49171-49172-156-157-47-53,51-10-65281-11-16-18-43-5-27-35-0-13-23-65037-17513-45,29-23-24,0';

    private Client $httpClient;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->httpClient = new Client([
            'timeout' => 30,
            'http_errors' => false,
            'verify' => false,
        ]);
    }

    /**
     * Сохраняет куки пользователя (ручной ввод)
     */
    public function saveCookies(int $userId, string $sourceType, string $cookies): array
    {
        $this->log('info', 'Сохранение кук', [
            'user_id' => $userId,
            'source_type' => $sourceType,
            'cookies_length' => strlen($cookies),
        ]);

        // Валидируем куки
        $validationResult = $this->validateCookies($sourceType, $cookies);

        if (!$validationResult['valid']) {
            $this->log('warning', 'Куки невалидны', [
                'user_id' => $userId,
                'source_type' => $sourceType,
                'error' => $validationResult['error'] ?? 'unknown',
            ]);

            // НЕ перезаписываем старые рабочие куки невалидными новыми
            // Просто возвращаем ошибку

            return [
                'success' => false,
                'error' => 'invalid_cookies',
                'message' => $validationResult['message'] ?? 'Куки недействительны или истекли',
            ];
        }

        // Парсим дату истечения из subscription_info
        $expiresAt = null;
        if (!empty($validationResult['subscription_info']['expire_text'])) {
            $expiresAt = $this->parseExpireDate($validationResult['subscription_info']['expire_text']);
        }

        // Сохраняем валидные куки
        UserSourceCookie::saveCookies(
            $userId,
            $sourceType,
            $cookies,
            true,
            $validationResult['subscription_info'],
            $expiresAt
        );

        $this->log('info', 'Куки сохранены успешно', [
            'user_id' => $userId,
            'source_type' => $sourceType,
            'subscription_info' => $validationResult['subscription_info'],
        ]);

        return [
            'success' => true,
            'message' => 'Куки сохранены и проверены',
            'auth_status' => true,
            'subscription_info' => $validationResult['subscription_info'],
        ];
    }

    /**
     * Получает статус авторизации пользователя
     */
    public function getAuthStatus(int $userId, string $sourceType): array
    {
        $userCookie = UserSourceCookie::getForUser($userId, $sourceType);

        if (!$userCookie || !$userCookie->cookies) {
            return [
                'is_authorized' => false,
                'has_cookies' => false,
                'subscription_info' => null,
            ];
        }

        return [
            'is_authorized' => $userCookie->is_valid,
            'has_cookies' => true,
            'is_expired' => $userCookie->isExpired(),
            'last_validated_at' => $userCookie->last_validated_at?->toIso8601String(),
            'expires_at' => $userCookie->expires_at?->toIso8601String(),
            'subscription_info' => $userCookie->subscription_info,
        ];
    }

    /**
     * Перепроверяет текущие куки и обновляет их (добавляет новые из ответа)
     */
    public function revalidateCookies(int $userId, string $sourceType): array
    {
        $this->log('info', 'Перепроверка кук', [
            'user_id' => $userId,
            'source_type' => $sourceType,
        ]);

        // Получаем текущие куки пользователя
        $userCookie = UserSourceCookie::getForUser($userId, $sourceType);

        if (!$userCookie || !$userCookie->cookies) {
            return [
                'success' => false,
                'error' => 'no_cookies',
                'message' => 'Нет сохранённых кук для перепроверки',
            ];
        }

        $currentCookies = $userCookie->cookies;

        // Валидируем куки и получаем новые из ответа
        $validationResult = $this->validateCookiesWithNewCookies($sourceType, $currentCookies);

        if (!$validationResult['valid']) {
            // Помечаем куки как невалидные
            $userCookie->is_valid = false;
            $userCookie->last_validated_at = Carbon::now();
            $userCookie->save();

            return [
                'success' => false,
                'error' => 'invalid_cookies',
                'message' => $validationResult['message'] ?? 'Куки недействительны или истекли',
            ];
        }

        // Объединяем старые куки с новыми
        $mergedCookies = $currentCookies;
        if (!empty($validationResult['new_cookies'])) {
            $mergedCookies = $this->mergeCookies($currentCookies, $validationResult['new_cookies']);
            $this->log('info', 'Куки обновлены', [
                'user_id' => $userId,
                'source_type' => $sourceType,
                'new_cookies_count' => count($validationResult['new_cookies']),
            ]);
        }

        // Парсим дату истечения из subscription_info
        $expiresAt = null;
        if (!empty($validationResult['subscription_info']['expire_text'])) {
            $expiresAt = $this->parseExpireDate($validationResult['subscription_info']['expire_text']);
        }

        // Обновляем куки в БД
        $userCookie->cookies = $mergedCookies;
        $userCookie->is_valid = true;
        $userCookie->last_validated_at = Carbon::now();
        $userCookie->subscription_info = $validationResult['subscription_info'];
        if ($expiresAt) {
            $userCookie->expires_at = $expiresAt;
        }
        $userCookie->save();

        $this->log('info', 'Перепроверка успешна', [
            'user_id' => $userId,
            'source_type' => $sourceType,
            'subscription_info' => $validationResult['subscription_info'],
        ]);

        return [
            'success' => true,
            'message' => 'Авторизация подтверждена',
            'auth_status' => true,
            'subscription_info' => $validationResult['subscription_info'],
            'cookies_updated' => !empty($validationResult['new_cookies']),
        ];
    }

    /**
     * Объединяет старые куки с новыми (добавляет новые, обновляет существующие)
     */
    private function mergeCookies(string $oldCookies, array $newCookies): string
    {
        // Парсим старые куки в ассоциативный массив
        $cookiesMap = [];
        $pairs = explode('; ', $oldCookies);
        
        foreach ($pairs as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookiesMap[trim($parts[0])] = trim($parts[1]);
            }
        }

        // Добавляем/обновляем новые куки
        foreach ($newCookies as $cookie) {
            if (!empty($cookie['name']) && isset($cookie['value'])) {
                $cookiesMap[$cookie['name']] = $cookie['value'];
            }
        }

        // Собираем обратно в строку
        $result = [];
        foreach ($cookiesMap as $name => $value) {
            $result[] = $name . '=' . $value;
        }

        return implode('; ', $result);
    }

    /**
     * Валидирует куки и возвращает новые куки из ответа
     */
    private function validateCookiesWithNewCookies(string $sourceType, string $cookies): array
    {
        if ($sourceType === 'cian') {
            return $this->validateCianCookiesWithNewCookies($cookies);
        }

        if ($sourceType === 'avito') {
            return $this->validateAvitoCookiesWithNewCookies($cookies);
        }

        return ['valid' => false, 'message' => 'Неизвестный источник'];
    }

    /**
     * Валидирует куки CIAN и возвращает новые куки
     */
    private function validateCianCookiesWithNewCookies(string $cookies): array
    {
        // Используем существующую валидацию
        $result = $this->validateCianCookies($cookies);
        
        // TODO: Извлечь новые куки из ответа прокси (set-cookie заголовки)
        // Пока возвращаем без новых кук
        $result['new_cookies'] = [];
        
        return $result;
    }

    /**
     * Валидирует куки Avito и возвращает новые куки
     */
    private function validateAvitoCookiesWithNewCookies(string $cookies): array
    {
        // Используем существующую валидацию
        $result = $this->validateAvitoCookies($cookies);
        
        // TODO: Извлечь новые куки из ответа прокси (set-cookie заголовки)
        // Пока возвращаем без новых кук
        $result['new_cookies'] = [];
        
        return $result;
    }

    /**
     * Валидирует куки
     */
    public function validateCookies(string $sourceType, string $cookies): array
    {
        if ($sourceType === 'cian') {
            return $this->validateCianCookies($cookies);
        }

        if ($sourceType === 'avito') {
            return $this->validateAvitoCookies($cookies);
        }

        return ['valid' => false, 'message' => 'Неизвестный источник'];
    }

    /**
     * Валидирует куки CIAN через прокси
     */
    private function validateCianCookies(string $cookies): array
    {
        try {
            // Парсим куки в массив
            $cookiesArray = $this->parseCookies($cookies, '.cian.ru');

            // Получаем случайное объявление для проверки
            $offerId = $this->getRandomCianOfferId();
            
            if (!$offerId) {
                $this->log('warning', 'Не найдено объявление для проверки кук');
                // Пробуем без проверки на конкретном объявлении
                $offerId = '123456789';
            }

            $this->log('info', 'Проверка кук CIAN', ['offer_id' => $offerId]);

            // Проверяем доступ к контактам
            $requestData = [
                'url' => 'https://api.cian.ru/early-access/v1/check-contacts-access/?offerId=' . $offerId,
                'method' => 'GET',
                'headers' => [
                    'origin' => 'https://www.cian.ru',
                    'referer' => 'https://www.cian.ru/',
                    'x-laas-timezone' => 'Europe/Moscow',
                ],
                'cookies' => $cookiesArray,
                'proxy' => self::DEFAULT_PROXY,
                'ja3' => self::DEFAULT_JA3,
                'timeout' => 30000,
            ];

            $response = $this->httpClient->post(self::CIAN_PROXY_URL, [
                'json' => $requestData,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!$result) {
                return ['valid' => false, 'message' => 'Ошибка прокси-сервера'];
            }

            $statusCode = $result['status'] ?? 0;

            $this->log('info', 'Ответ от CIAN API', [
                'status_code' => $statusCode,
                'body' => substr($result['body'] ?? '', 0, 500),
            ]);

            if ($statusCode === 200) {
                $data = json_decode($result['body'], true);
                
                $this->log('info', 'Распарсенный ответ CIAN', [
                    'data_status' => $data['status'] ?? 'not set',
                    'data' => $data,
                ]);
                
                // Принимаем куки если статус confirm, free или needPay
                // needPay означает что куки валидны, но нет активного пакета контактов
                $validStatuses = ['confirm', 'free', 'needPay'];
                
                if (isset($data['status']) && in_array($data['status'], $validStatuses)) {
                    // Куки рабочие, получаем информацию о подписке
                    $subscriptionInfo = $this->getCianSubscriptionInfo($cookiesArray);
                    
                    return [
                        'valid' => true,
                        'subscription_info' => $subscriptionInfo,
                    ];
                }
                
                // Если статус неизвестный
                return [
                    'valid' => false, 
                    'message' => 'Неизвестный статус ответа: ' . ($data['status'] ?? 'unknown'),
                ];
            }

            // Обрабатываем ошибки
            if ($statusCode === 400) {
                return ['valid' => false, 'message' => 'Куки недействительны'];
            }
            if ($statusCode === 403) {
                return ['valid' => false, 'message' => 'Доступ заблокирован'];
            }

            return ['valid' => false, 'message' => 'Не удалось проверить куки'];

        } catch (GuzzleException $e) {
            $this->log('error', 'Ошибка при проверке кук CIAN', ['error' => $e->getMessage()]);
            return ['valid' => false, 'message' => 'Ошибка соединения: ' . $e->getMessage()];
        }
    }

    /**
     * Получает информацию о подписке CIAN Early Access
     */
    private function getCianSubscriptionInfo(array $cookiesArray): ?array
    {
        try {
            $requestData = [
                'url' => 'https://my.cian.ru/early-access/?source=LK',
                'method' => 'GET',
                'headers' => [
                    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                ],
                'cookies' => $cookiesArray,
                'proxy' => self::DEFAULT_PROXY,
                'ja3' => self::DEFAULT_JA3,
                'timeout' => 30000,
            ];

            $response = $this->httpClient->post(self::CIAN_PROXY_URL, [
                'json' => $requestData,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (($result['status'] ?? 0) !== 200) {
                return null;
            }

            return $this->parseCianEarlyAccessData($result['body']);

        } catch (GuzzleException $e) {
            $this->log('error', 'Ошибка получения информации о подписке CIAN', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Парсит данные Early Access из HTML страницы CIAN
     */
    private function parseCianEarlyAccessData(string $html): ?array
    {
        // Способ 1: Ищем JSON в initialState
        if (preg_match('/"earlyAccessInfo":\s*\{"data":\s*(\{.+?"status":\s*"[^"]+".+?\})\s*,\s*"status"/s', $html, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data) {
                return $this->extractCianSubscriptionInfo($data);
            }
        }

        // Способ 2: Ищем отдельные поля
        $info = [];
        
        if (preg_match('/"earlyAccessInfo":\s*\{[^}]*"data":\s*\{[^}]*"status":\s*"([^"]+)"/s', $html, $m)) {
            $info['status'] = $m[1];
        }
        if (preg_match('/"tariff":\s*"([^"]+)"/s', $html, $m)) {
            $info['tariff'] = $m[1];
        }
        if (preg_match('/"expireText":\s*"([^"]+)"/s', $html, $m)) {
            $info['expire_text'] = $m[1];
        }
        if (preg_match('/"limitInfo":\s*\{\s*"text":\s*"([^"]+)"/s', $html, $m)) {
            $info['limit_info'] = $m[1];
        }
        if (preg_match('/"phone":\s*"(\+7[^"]+)"/s', $html, $m)) {
            $info['phone'] = $m[1];
        }

        return !empty($info['status']) ? $info : null;
    }

    /**
     * Извлекает информацию о подписке из JSON
     */
    #[ArrayShape(['status' => "mixed|null", 'tariff' => "mixed|null", 'expire_text' => "mixed|null", 'limit_info' => "mixed|null", 'phone' => "mixed|null"])]
    private function extractCianSubscriptionInfo(array $data): array
    {
        return [
            'status' => $data['status'] ?? null,
            'tariff' => $data['tariff'] ?? null,
            'expire_text' => $data['expireText'] ?? null,
            'limit_info' => $data['limitInfo']['text'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];
    }

    /**
     * Валидирует куки Avito через Go-прокси (без внешнего прокси)
     */
    private function validateAvitoCookies(string $cookies): array
    {
        try {
            // Парсим куки в массив
            $cookiesArray = $this->parseCookies($cookies, '.avito.ru');

            $this->log('info', 'Проверка кук Avito через Go-прокси');

            // Запрос к API профиля Avito через Go-прокси, но БЕЗ внешнего прокси
            $requestData = [
                'url' => 'https://www.avito.ru/web/2/profileinfo',
                'method' => 'POST',
                'headers' => [
                    'accept' => 'application/json, text/plain, */*',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'content-type' => 'application/json',
                    'origin' => 'https://www.avito.ru',
                    'referer' => 'https://www.avito.ru/',
                    'sec-ch-ua' => '"Google Chrome";v="144", "Chromium";v="144", "Not A(Brand";v="24"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'sec-fetch-dest' => 'empty',
                    'sec-fetch-mode' => 'cors',
                    'sec-fetch-site' => 'same-origin',
                ],
                'body' => '{"withCounts":1}',
                'cookies' => $cookiesArray,
                // НЕ передаём proxy — запрос пойдёт напрямую, но ja3 оставляем
                'ja3' => self::DEFAULT_JA3,
                'timeout' => 30000,
            ];

            $response = $this->httpClient->post(self::CIAN_PROXY_URL, [
                'json' => $requestData,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!$result) {
                return ['valid' => false, 'message' => 'Ошибка прокси-сервера'];
            }

            $statusCode = $result['status'] ?? 0;

            $this->log('info', 'Ответ от Avito API', [
                'status_code' => $statusCode,
                'body' => substr($result['body'] ?? '', 0, 500),
            ]);

            if ($statusCode === 200) {
                $data = json_decode($result['body'], true);
                
                $this->log('info', 'Распарсенный ответ Avito', ['data' => $data]);

                // Проверяем наличие профиля — это означает что куки валидны
                if (isset($data['profile']) && !empty($data['profile']['name'])) {
                    // Куки рабочие, извлекаем информацию о подписке
                    $subscriptionInfo = $this->extractAvitoSubscriptionInfo($data);
                    
                    return [
                        'valid' => true,
                        'subscription_info' => $subscriptionInfo,
                    ];
                }
                
                // Если нет профиля, значит не авторизован
                return ['valid' => false, 'message' => 'Пользователь не авторизован на Avito'];
            }

            // Обрабатываем ошибки
            if ($statusCode === 401 || $statusCode === 403) {
                return ['valid' => false, 'message' => 'Куки недействительны или истекли'];
            }

            return ['valid' => false, 'message' => 'Не удалось проверить куки (код: ' . $statusCode . ')'];

        } catch (GuzzleException $e) {
            $this->log('error', 'Ошибка при проверке кук Avito', ['error' => $e->getMessage()]);
            return ['valid' => false, 'message' => 'Ошибка соединения: ' . $e->getMessage()];
        }
    }

    /**
     * Извлекает информацию о подписке Avito из ответа API
     */
    private function extractAvitoSubscriptionInfo(array $data): array
    {
        $info = [
            'name' => $data['profile']['name'] ?? null,
            'contact_name' => $data['profile']['contactName'] ?? null,
            'position' => $data['profile']['position'] ?? null,
        ];

        // Извлекаем данные из tiles (кошелёк, остаток размещений)
        if (!empty($data['tiles'])) {
            foreach ($data['tiles'] as $tile) {
                $title = $tile['title'] ?? '';
                
                if (str_contains($title, 'Кошелёк') || str_contains($title, 'кошел')) {
                    $info['balance'] = $tile['value'] ?? null;
                    $info['bonuses'] = $tile['details'] ?? null;
                }
                
                if (str_contains($title, 'Остаток') || str_contains($title, 'размещен')) {
                    $info['listings_remaining'] = $tile['value'] ?? null;
                }
            }
        }

        // Извлекаем счётчики (сообщения, избранное, уведомления)
        if (!empty($data['counters'])) {
            foreach ($data['counters'] as $counter) {
                $id = $counter['id'] ?? '';
                
                if ($id === 'id_profile_messenger') {
                    $info['messages_count'] = $counter['count'] ?? 0;
                }
            }
        }

        // Рейтинг
        if (isset($data['stats']['rating'])) {
            $info['rating'] = $data['stats']['rating'];
        }

        return $info;
    }

    /**
     * Парсит строку кук в массив для Go-прокси
     */
    private function parseCookies(string $cookieString, string $domain = '.cian.ru'): array
    {
        $cookies = [];
        $pairs = explode('; ', $cookieString);

        foreach ($pairs as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[] = [
                    'name' => trim($parts[0]),
                    'value' => trim($parts[1]),
                    'domain' => $domain,
                ];
            }
        }

        return $cookies;
    }

    /**
     * Получает случайный ID объявления CIAN для проверки
     */
    private function getRandomCianOfferId(): ?string
    {
        // Ищем объявление с source_id = 3 (CIAN)
        $listing = Listing::where('source_id', 3)
            ->whereNotNull('external_id')
            ->orderByDesc('created_at')
            ->first();

        return $listing?->external_id;
    }

    /**
     * Парсит дату истечения из текста
     */
    private function parseExpireDate(string $expireText): ?Carbon
    {
        // Пример: "До 18 февраля"
        $months = [
            'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4,
            'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8,
            'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
        ];

        if (preg_match('/до\s+(\d+)\s+(\w+)/iu', $expireText, $matches)) {
            $day = (int)$matches[1];
            $monthName = mb_strtolower($matches[2]);
            
            if (isset($months[$monthName])) {
                $month = $months[$monthName];
                $year = Carbon::now()->year;
                
                // Если месяц уже прошёл, значит это следующий год
                if ($month < Carbon::now()->month || ($month === Carbon::now()->month && $day < Carbon::now()->day)) {
                    $year++;
                }
                
                return Carbon::create($year, $month, $day, 23, 59, 59);
            }
        }

        return null;
    }

    /**
     * Логирование
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->$level($message, $context);
    }
}
