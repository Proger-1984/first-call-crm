<?php

use Illuminate\Database\Capsule\Manager;

class CreateTariffPricesTable
{
    public function getTableName(): string
    {
        return 'tariff_prices';
    }

    public function up()
    {
        // Создаем таблицу для хранения цен тарифов в зависимости от локации
        Manager::schema()->create('tariff_prices', function ($table) {
            $table->increments('id');
            $table->integer('tariff_id')->unsigned();     // ID тарифа
            $table->integer('location_id')->unsigned();   // ID локации
            $table->decimal('price', 10, 2);             // Цена для данного тарифа и локации
            $table->timestamps();
            
            // Внешние ключи и уникальный индекс
            $table->foreign('tariff_id')->references('id')->on('tariffs')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->unique(['tariff_id', 'location_id']);
        });
        
        // Добавляем стандартные цены для демо-тарифа (бесплатно для всех локаций)
        $demoTariff = Manager::table('tariffs')->where('code', 'demo')->first();
        
        if ($demoTariff) {
            $locations = Manager::table('locations')->get();
            $demoTariffPrices = [];
            
            foreach ($locations as $location) {
                $demoTariffPrices[] = [
                    'tariff_id' => $demoTariff->id,
                    'location_id' => $location->id,
                    'price' => 0.00,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
            
            Manager::table('tariff_prices')->insert($demoTariffPrices);
        }

        // Добавляем стандартные цены для премиум-тарифа
        $premiumTariff = Manager::table('tariffs')->where('code', 'premium')->first();
        
        if ($premiumTariff) {

            $basePrice = 5000.00; // Базовая цена для локаций
            
            $locations = Manager::table('locations')->get();
            $premiumTariffPrices = [];
            
            foreach ($locations as $location) {
                
                $premiumTariffPrices[] = [
                    'tariff_id' => $premiumTariff->id,
                    'location_id' => $location->id,
                    'price' => $basePrice,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
            
            Manager::table('tariff_prices')->insert($premiumTariffPrices);
        }
    }

    public function down()
    {
        // Удаляем таблицу при откате миграции
        Manager::schema()->drop('tariff_prices');
    }
} 