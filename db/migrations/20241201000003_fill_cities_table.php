<?php

use Illuminate\Database\Capsule\Manager;

class FillCitiesTable
{
    public function getTableName(): string
    {
        return 'cities';
    }

    public function up()
    {
        // Получаем все локации из таблицы locations
        $locations = Manager::table('locations')->get();
        
        if (empty($locations)) {
            throw new Exception('Не найдено локаций в таблице locations');
        }
        
        $cities = [];
        $cityId = 1;
        
        foreach ($locations as $location) {
            if ($location->city === 'Москва') {
                // Специальные данные для Московской области
                $moscowCities = $this->getMoscowCities($location->id, $cityId);
                $cities = array_merge($cities, $moscowCities);
                $cityId += count($moscowCities);
            } else {
                // Для остальных локаций создаем основной город
                $cities[] = [
                    'name' => $location->city,
                    'city_parent_id' => $cityId,
                    'location_parent_id' => $location->id
                ];
                $cityId++;
            }
        }

        // Добавляем timestamps к каждой записи
        $timestamp = date('Y-m-d H:i:s');
        foreach ($cities as &$city) {
            $city['created_at'] = $timestamp;
            $city['updated_at'] = $timestamp;
        }

        // Вставляем данные
        Manager::table('cities')->insert($cities);
    }

    /**
     * Получить города Московской области
     */
    private function getMoscowCities(int $moscowLocationId, int $startCityId): array
    {
        return [
            // Группа "Москва" (основной город и его районы)
            ['name' => 'Москва', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Мосрентген', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Реутов', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рабочий пос. Лопатино', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пгт. Дрожжино', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рп. Лопатино', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рп. Дрожжино', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пос. Битца', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рабочий пос. Дрожжино', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Московский', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пос. Коммунарка', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Коммунарка', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пос. Нагорное', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Лесной Городок', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пос. Внуково', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Воскресенское', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'с. Ангелово', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'д. Путилково', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'поселение Воскресенское', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пос. Отрадное', 'city_parent_id' => $startCityId, 'location_parent_id' => $moscowLocationId],
            
            // Остальные группы городов (каждый сам себе parent)
            ['name' => 'Химки', 'city_parent_id' => $startCityId + 20, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Долгопрудный', 'city_parent_id' => $startCityId + 21, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Зеленоград', 'city_parent_id' => $startCityId + 22, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Красногорск', 'city_parent_id' => $startCityId + 23, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Мытищи', 'city_parent_id' => $startCityId + 24, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Королев', 'city_parent_id' => $startCityId + 25, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Балашиха"
            ['name' => 'Балашиха', 'city_parent_id' => $startCityId + 26, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Железнодорожный', 'city_parent_id' => $startCityId + 26, 'location_parent_id' => $moscowLocationId],
            
            ['name' => 'Люберцы', 'city_parent_id' => $startCityId + 28, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Котельники', 'city_parent_id' => $startCityId + 29, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Дзержинский', 'city_parent_id' => $startCityId + 30, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Развилка"
            ['name' => 'Развилка', 'city_parent_id' => $startCityId + 31, 'location_parent_id' => $moscowLocationId],
            ['name' => 'пос. Совхоза имени Ленина', 'city_parent_id' => $startCityId + 31, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Видное"
            ['name' => 'Видное', 'city_parent_id' => $startCityId + 33, 'location_parent_id' => $moscowLocationId],
            ['name' => 'д. Суханово', 'city_parent_id' => $startCityId + 33, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рабочий пос. Боброво', 'city_parent_id' => $startCityId + 33, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рп. Боброво', 'city_parent_id' => $startCityId + 33, 'location_parent_id' => $moscowLocationId],
            ['name' => 'д. Сапроново', 'city_parent_id' => $startCityId + 33, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Щербинка"
            ['name' => 'Щербинка', 'city_parent_id' => $startCityId + 38, 'location_parent_id' => $moscowLocationId],
            ['name' => 'поселение Рязановское', 'city_parent_id' => $startCityId + 38, 'location_parent_id' => $moscowLocationId],
            
            ['name' => 'Подольск', 'city_parent_id' => $startCityId + 40, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Пос. Газопровод', 'city_parent_id' => $startCityId + 41, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Десна', 'city_parent_id' => $startCityId + 42, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Троицк"
            ['name' => 'Троицк', 'city_parent_id' => $startCityId + 43, 'location_parent_id' => $moscowLocationId],
            ['name' => 'с. Троицкое', 'city_parent_id' => $startCityId + 43, 'location_parent_id' => $moscowLocationId],
            
            ['name' => 'Ватутинки', 'city_parent_id' => $startCityId + 45, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Одинцово"
            ['name' => 'Одинцово', 'city_parent_id' => $startCityId + 46, 'location_parent_id' => $moscowLocationId],
            ['name' => 'поселение Кокошкино', 'city_parent_id' => $startCityId + 46, 'location_parent_id' => $moscowLocationId],
            ['name' => 'д. Солманово', 'city_parent_id' => $startCityId + 46, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Нахабино"
            ['name' => 'Нахабино', 'city_parent_id' => $startCityId + 49, 'location_parent_id' => $moscowLocationId],
            ['name' => 'рабочий пос. Нахабино', 'city_parent_id' => $startCityId + 49, 'location_parent_id' => $moscowLocationId],
            
            ['name' => 'Опалила', 'city_parent_id' => $startCityId + 51, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Сходня', 'city_parent_id' => $startCityId + 52, 'location_parent_id' => $moscowLocationId],
            
            // Группа "Лобня"
            ['name' => 'Лобня', 'city_parent_id' => $startCityId + 53, 'location_parent_id' => $moscowLocationId],
            ['name' => 'Лобня, ПСК Ягодка', 'city_parent_id' => $startCityId + 53, 'location_parent_id' => $moscowLocationId]
        ];
    }

    public function down()
    {
        // Очищаем таблицу cities
        Manager::table('cities')->truncate();
    }
} 