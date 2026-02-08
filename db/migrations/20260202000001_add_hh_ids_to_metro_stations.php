<?php

use Illuminate\Database\Capsule\Manager;

/**
 * Добавление ID линии и станции из API hh.ru в таблицу metro_stations
 */
class AddHhIdsToMetroStations
{
    public function getTableName(): string
    {
        return 'metro_stations__line_id'; // Уникальное имя для проверки колонки
    }

    public function up(): void
    {
        // Проверяем, существует ли колонка line_id
        if (Manager::schema()->hasColumn('metro_stations', 'line_id')) {
            return;
        }

        Manager::schema()->table('metro_stations', function ($table) {
            // ID линии из API hh.ru (например: 137)
            $table->string('line_id', 20)->nullable()->after('line');
            
            // ID станции из API hh.ru (например: 137.961)
            $table->string('station_id', 20)->nullable()->after('line_id');
            
            // Индекс для быстрого поиска по station_id
            $table->index('station_id');
        });
    }

    public function down(): void
    {
        if (!Manager::schema()->hasColumn('metro_stations', 'line_id')) {
            return;
        }

        Manager::schema()->table('metro_stations', function ($table) {
            $table->dropIndex(['station_id']);
            $table->dropColumn(['line_id', 'station_id']);
        });
    }
}
