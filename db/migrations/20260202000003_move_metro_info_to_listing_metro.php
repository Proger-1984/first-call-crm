<?php

use Illuminate\Database\Capsule\Manager;

/**
 * Перенос информации о метро из listings в listing_metro
 * 
 * - Добавляем поле distance в listing_metro для хранения расстояния
 * - Удаляем metro_distance и metro_walk_time из listings (дублирование)
 * 
 * Теперь вся информация о метро хранится в listing_metro:
 * - travel_time_min — время в минутах
 * - travel_type — тип передвижения (walk, car)
 * - distance — расстояние ("900 м", "2,7 км")
 */
class MoveMetroInfoToListingMetro
{
    public function getTableName(): string
    {
        return 'listing_metro__distance';
    }

    public function up(): void
    {
        // 1. Добавляем поле distance в listing_metro (если ещё нет)
        if (!Manager::schema()->hasColumn('listing_metro', 'distance')) {
            Manager::schema()->table('listing_metro', function ($table) {
                // Расстояние до метро (например: "900 м" или "2,7 км")
                $table->string('distance', 50)->nullable()->after('travel_time_min');
            });
        }

        // 2. Удаляем поля из listings (если есть)
        if (Manager::schema()->hasColumn('listings', 'metro_distance')) {
            Manager::schema()->table('listings', function ($table) {
                $table->dropColumn(['metro_distance', 'metro_walk_time']);
            });
        }
    }

    public function down(): void
    {
        // Возвращаем поля в listings
        if (!Manager::schema()->hasColumn('listings', 'metro_distance')) {
            Manager::schema()->table('listings', function ($table) {
                $table->string('metro_distance', 50)->nullable();
                $table->string('metro_walk_time', 50)->nullable();
            });
        }

        // Удаляем distance из listing_metro
        if (Manager::schema()->hasColumn('listing_metro', 'distance')) {
            Manager::schema()->table('listing_metro', function ($table) {
                $table->dropColumn('distance');
            });
        }
    }
}
