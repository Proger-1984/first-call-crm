<?php

use Illuminate\Database\Capsule\Manager;

class CreateLocationsTable
{
    public function getTableName(): string
    {
        return 'locations';
    }

    public function up()
    {
        // Создаем таблицу локаций для хранения городов и регионов
        Manager::schema()->create('locations', function ($table) {
            $table->increments('id');
            $table->string('city');         // Город
            $table->string('region');       // Регион/область
            $table->timestamps();           // Даты создания и изменения
        });

        // Вставляем все локации в таблицу
        $locations = [
            ['city' => 'Москва', 'region' => 'Московская область'],
            ['city' => 'Санкт-Петербург', 'region' => 'Ленинградская область'],
            ['city' => 'Новосибирск', 'region' => 'Новосибирская область'],
            ['city' => 'Екатеринбург', 'region' => 'Свердловская область'],
            ['city' => 'Казань', 'region' => 'Республика Татарстан'],
            ['city' => 'Красноярск', 'region' => 'Красноярский край'],
            ['city' => 'Нижний Новгород', 'region' => 'Нижегородская область'],
            ['city' => 'Челябинск', 'region' => 'Челябинская область'],
            ['city' => 'Уфа', 'region' => 'Республика Башкортостан'],
            ['city' => 'Самара', 'region' => 'Самарская область'],
            ['city' => 'Ростов-на-Дону', 'region' => 'Ростовская область'],
            ['city' => 'Краснодар', 'region' => 'Краснодарский край'],
            ['city' => 'Омск', 'region' => 'Омская область'],
            ['city' => 'Воронеж', 'region' => 'Воронежская область'],
            ['city' => 'Пермь', 'region' => 'Пермский край'],
            ['city' => 'Волгоград', 'region' => 'Волгоградская область'],
            ['city' => 'Саратов', 'region' => 'Саратовская область'],
            ['city' => 'Тюмень', 'region' => 'Тюменская область'],
            ['city' => 'Тверь', 'region' => 'Тверская область'],
        ];

        foreach ($locations as $key => $location) {
            $locations[$key]['created_at'] = date('Y-m-d H:i:s');
            $locations[$key]['updated_at'] = date('Y-m-d H:i:s');
        }

        Manager::table('locations')->insert($locations);
    }

    public function down()
    {
        // Удаляем таблицу при откате миграции
        Manager::schema()->drop('locations');
    }
} 