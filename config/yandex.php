<?php

declare(strict_types=1);

/**
 * Конфигурация парсера Яндекс.Недвижимости
 *
 * Для добавления нового города достаточно добавить запись в массив `locations`
 * с указанием rgid и категорий с ценовыми диапазонами.
 * Каждая пара location+category = отдельный дочерний процесс (воркер).
 *
 * Все параметры API-запроса задаются в конфиге:
 * - `request` — общие параметры для всех воркеров
 * - `categories[].api_params` — дополнительные параметры конкретной категории
 * Код просто мержит и отправляет, без условной логики.
 */
return [
    // Базовый URL мобильного API Яндекс.Недвижимости
    'api_url' => 'https://api.realty.yandex.net/1.0/offerWithSiteSearch.json',

    // Токен авторизации мобильного приложения (из .env)
    'auth_token' => $_ENV['YANDEX_REALTY_AUTH_TOKEN'] ?? '',

    // User-Agent мобильного приложения Яндекс.Недвижимость
    'user_agent' => 'com.yandex.mobile.realty/6.1.0.10218 (Google sdk_gphone64_x86_64; Android 12)',

    // source_id в таблице sources (Яндекс.Недвижимость)
    'source_id' => 2,

    // Интервал ротации кеша дедупликации (минуты)
    'cache_rotation_minutes' => 60,

    // Прокси (опционально, для ротации при блокировках)
    'proxy' => [
        'enabled' => false,
        'list' => [],  // ['http://user:pass@host:port', ...]
    ],

    // Задержка между запросами по умолчанию (микросекунды)
    // Можно переопределить на уровне категории: 'sleep_min_us' / 'sleep_max_us'
    'sleep_min_us' => 1_000_000,  // 1 сек
    'sleep_max_us' => 2_000_000,  // 2 сек

    // Параметры запроса к API (общие для всех воркеров)
    'request' => [
        'page' => 0,
        'sort' => 'DATE_DESC',
        'category' => 'APARTMENT',
        'currency' => 'RUR',
        'showOnMobile' => 'YES',
        'priceType' => 'PER_OFFER',
        'showSimilar' => 'NO',
        'agents' => 'NO',
        'pageSize' => 20,
        'roomsTotal' => ['STUDIO', '1', '2', '3', 'PLUS_4'],
    ],

    // Конфигурация локаций и категорий для парсинга
    'locations' => [
        1 => [  // location_id = 1 (Москва и область)
            'name' => 'Москва и область',
            'rgid' => 741964,
            'categories' => [
                1 => [  // category_id = 1 (Аренда жилая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'RENT',
                        'rentTime' => 'LARGE',
                        'priceMin' => [15000, 25000],    // рандом кратный 1000
                        'priceMax' => [120000, 155000],
                    ],
                ],
                3 => [  // category_id = 3 (Продажа жилая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'SELL',
                        'objectType' => 'OFFER',
                        'priceMin' => [400000, 600000],
                        'priceMax' => [850000000, 950000000],
                    ],
                ],
                2 => [  // category_id = 2 (Аренда коммерческая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'RENT',
                        'rentTime' => 'LARGE',
                        'category' => 'COMMERCIAL',
                        'roomsTotal' => false,
                        'priceMin' => [10000, 25000],
                        'priceMax' => [4500000, 5500000],
                        'commercialType' => [
                            'OFFICE', 'RETAIL', 'FREE_PURPOSE', 'WAREHOUSE',
                            'MANUFACTURING', 'PUBLIC_CATERING', 'AUTO_REPAIR',
                            'HOTEL', 'BUSINESS',
                        ],
                    ],
                ],
                4 => [  // category_id = 4 (Продажа коммерческая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'SELL',
                        'objectType' => 'OFFER',
                        'category' => 'COMMERCIAL',
                        'roomsTotal' => false,
                        'priceMin' => [400000, 600000],
                        'priceMax' => [850000000, 950000000],
                        'commercialType' => [
                            'OFFICE', 'RETAIL', 'FREE_PURPOSE', 'WAREHOUSE',
                            'MANUFACTURING', 'PUBLIC_CATERING', 'AUTO_REPAIR',
                            'HOTEL', 'BUSINESS',
                        ],
                    ],
                ],
            ],
        ],
        2 => [  // location_id = 2 (Санкт-Петербург и область)
            'name' => 'Санкт-Петербург и область',
            'rgid' => 741965,
            'categories' => [
                1 => [  // category_id = 1 (Аренда жилая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'RENT',
                        'rentTime' => 'LARGE',
                        'priceMin' => [13000, 25000],
                        'priceMax' => [145000, 155000],
                    ],
                ],
                3 => [  // category_id = 3 (Продажа жилая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'SELL',
                        'objectType' => 'OFFER',
                        'priceMin' => [400000, 600000],
                        'priceMax' => [850000000, 950000000],
                    ],
                ],
                2 => [  // category_id = 2 (Аренда коммерческая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'RENT',
                        'rentTime' => 'LARGE',
                        'category' => 'COMMERCIAL',
                        'roomsTotal' => false,
                        'priceMin' => [8000, 25000],
                        'priceMax' => [4500000, 5500000],
                        'commercialType' => [
                            'OFFICE', 'RETAIL', 'FREE_PURPOSE', 'WAREHOUSE',
                            'MANUFACTURING', 'PUBLIC_CATERING', 'AUTO_REPAIR',
                            'HOTEL', 'BUSINESS',
                        ],
                    ],
                ],
                4 => [  // category_id = 4 (Продажа коммерческая)
                    'filter_today_only' => false,
                    'api_params' => [
                        'type' => 'SELL',
                        'objectType' => 'OFFER',
                        'category' => 'COMMERCIAL',
                        'roomsTotal' => false,
                        'priceMin' => [400000, 600000],
                        'priceMax' => [850000000, 950000000],
                        'commercialType' => [
                            'OFFICE', 'RETAIL', 'FREE_PURPOSE', 'WAREHOUSE',
                            'MANUFACTURING', 'PUBLIC_CATERING', 'AUTO_REPAIR',
                            'HOTEL', 'BUSINESS',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
