<?php

use Illuminate\Database\Capsule\Manager;

class AddCoordinatesToLocationsTable
{
    public function getTableName(): string
    {
        return 'locations';
    }
    
    /**
     * Указывает, что эта миграция модифицирует существующую таблицу
     * и должна выполняться, даже если таблица уже существует
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Сначала проверим, включено ли расширение PostGIS
        $this->enablePostGIS();

        // Проверка наличия колонок перед их добавлением
        $hasFields = $this->checkIfColumnsExist('locations', ['center_lat', 'center_lng', 'bounds']);
        
        if (!$hasFields) {
            // Добавляем поля для хранения координат центра и границ
            Manager::schema()->table('locations', function ($table) {
                // Обычные колонки для совместимости с фронтендом
                $table->decimal('center_lat', 10, 6)->nullable(); 
                $table->decimal('center_lng', 10, 6)->nullable(); 
                $table->json('bounds')->nullable();               
            });
        }
        
        // Проверка наличия PostGIS полей
        $hasPostGisFields = $this->checkIfColumnsExist('locations', ['center_point', 'bounds_polygon']);
        
        if (!$hasPostGisFields) {
            // PostGIS-специфичные колонки для геопространственных операций
            // Используем DDL запрос для добавления геометрических колонок
            Manager::statement('ALTER TABLE locations ADD COLUMN center_point GEOMETRY(Point, 4326)');
            Manager::statement('ALTER TABLE locations ADD COLUMN bounds_polygon GEOMETRY(Polygon, 4326)');
            
            // Создаем индексы для ускорения геопространственных запросов
            Manager::statement('CREATE INDEX idx_locations_center_point ON locations USING GIST(center_point)');
            Manager::statement('CREATE INDEX idx_locations_bounds_polygon ON locations USING GIST(bounds_polygon)');
        }

    }

    public function down()
    {
        // Удаляем индексы и геометрические колонки PostGIS
        Manager::statement('DROP INDEX IF EXISTS idx_locations_center_point');
        Manager::statement('DROP INDEX IF EXISTS idx_locations_bounds_polygon');
        Manager::statement('ALTER TABLE locations DROP COLUMN IF EXISTS center_point');
        Manager::statement('ALTER TABLE locations DROP COLUMN IF EXISTS bounds_polygon');
        
        // Удаляем обычные колонки
        Manager::schema()->table('locations', function ($table) {
            $table->dropColumn(['center_lat', 'center_lng', 'bounds']);
        });
    }
    
    /**
     * Включает расширение PostGIS в PostgreSQL, если оно еще не включено
     */
    private function enablePostGIS()
    {
        // Проверяем, существует ли расширение PostGIS
        $result = Manager::select("SELECT extname FROM pg_extension WHERE extname = 'postgis'");
        
        if (empty($result)) {
            // Включаем расширение PostGIS
            Manager::statement('CREATE EXTENSION IF NOT EXISTS postgis');
            Manager::statement('CREATE EXTENSION IF NOT EXISTS postgis_topology');
        }
    }
    
    /**
     * Проверяет, существуют ли колонки в таблице
     * 
     * @param string $table Имя таблицы
     * @param array $columns Имена колонок
     * @return bool
     */
    private function checkIfColumnsExist(string $table, array $columns): bool
    {
        // Проверяем каждую колонку
        foreach ($columns as $column) {
            $result = Manager::select("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = ? 
                AND column_name = ?
            ", [$table, $column]);
            
            if (empty($result)) {
                return false;
            }
        }
        
        return true;
    }
} 