<?php

use Illuminate\Database\Capsule\Manager;

class AddCategoryToTariffPrices
{
    public function getTableName(): string
    {
        return 'tariff_prices';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // 1. Добавляем колонку category_id
        Manager::schema()->table('tariff_prices', function ($table) {
            $table->integer('category_id')->unsigned()->nullable()->after('location_id');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });

        // 2. Удаляем старый уникальный индекс
        Manager::schema()->table('tariff_prices', function ($table) {
            $table->dropUnique(['tariff_id', 'location_id']);
        });

        // 3. Создаем новый уникальный индекс с category_id
        Manager::schema()->table('tariff_prices', function ($table) {
            $table->unique(['tariff_id', 'location_id', 'category_id'], 'tariff_prices_unique');
        });

        // 4. Получаем ID нужных записей
        $premiumTariff = Manager::table('tariffs')->where('code', 'premium_31')->first();
        $demoTariff = Manager::table('tariffs')->where('code', 'demo')->first();
        $moscowLocation = Manager::table('locations')->where('city', 'Москва')->first();
        $rentalCategory = Manager::table('categories')->where('name', 'like', '%Аренда жилая%')->first();
        
        if (!$premiumTariff || !$moscowLocation || !$rentalCategory) {
            throw new \Exception('Не найдены необходимые записи: tariff, location или category');
        }

        // 5. Удаляем все текущие цены для премиум тарифа (пересоздадим)
        Manager::table('tariff_prices')->where('tariff_id', $premiumTariff->id)->delete();

        // 6. Получаем все локации и категории
        $locations = Manager::table('locations')->get();
        $categories = Manager::table('categories')->get();
        
        $now = date('Y-m-d H:i:s');
        $newPrices = [];

        // 7. Создаем цены для всех комбинаций
        foreach ($locations as $location) {
            foreach ($categories as $category) {
                // Москва + Аренда жилая = 10000, остальные = 5000
                $isMoscowRental = ($location->id === $moscowLocation->id && $category->id === $rentalCategory->id);
                $price = $isMoscowRental ? 10000.00 : 5000.00;
                
                $newPrices[] = [
                    'tariff_id' => $premiumTariff->id,
                    'location_id' => $location->id,
                    'category_id' => $category->id,
                    'price' => $price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 8. Вставляем новые цены
        Manager::table('tariff_prices')->insert($newPrices);

        // 9. Обновляем демо-тариф: добавляем category_id к существующим записям
        // Для демо цена всегда 0, поэтому просто обновим существующие записи
        if ($demoTariff) {
            // Удаляем старые записи демо
            Manager::table('tariff_prices')->where('tariff_id', $demoTariff->id)->delete();
            
            // Создаем новые для всех комбинаций
            $demoPrices = [];
            foreach ($locations as $location) {
                foreach ($categories as $category) {
                    $demoPrices[] = [
                        'tariff_id' => $demoTariff->id,
                        'location_id' => $location->id,
                        'category_id' => $category->id,
                        'price' => 0.00,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            Manager::table('tariff_prices')->insert($demoPrices);
        }

        // 10. Обновляем базовую цену тарифа
        Manager::table('tariffs')
            ->where('id', $premiumTariff->id)
            ->update(['price' => 5000.00, 'updated_at' => $now]);
    }

    public function down()
    {
        // Удаляем новый уникальный индекс
        Manager::schema()->table('tariff_prices', function ($table) {
            $table->dropUnique('tariff_prices_unique');
        });

        // Удаляем внешний ключ и колонку
        Manager::schema()->table('tariff_prices', function ($table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });

        // Восстанавливаем старый уникальный индекс
        Manager::schema()->table('tariff_prices', function ($table) {
            $table->unique(['tariff_id', 'location_id']);
        });
    }
}
