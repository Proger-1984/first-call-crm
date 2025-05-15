<?php

use Illuminate\Database\Capsule\Manager;

class AddPostgisFieldsToExistingTables
{
    public function getTableName(): string
    {
        return 'migrate_postgis_fields';
    }

    /**
     * Указывает, что эта миграция модифицирует существующие таблицы
     * и должна выполняться, даже если они уже существуют
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Проверяем, включено ли расширение PostGIS
        $this->enablePostGIS();

        // Добавляем PostGIS поля в таблицу locations, если они еще не существуют
        $this->addPostGisFieldsToLocations();
        
        // Добавляем PostGIS поля в таблицу user_location_polygons, если они еще не существуют
        $this->addPostGisFieldsToUserLocationPolygons();
        
        // Заполняем PostGIS данные для существующих локаций
        $this->updateLocationsWithPostGisData();
    }

    public function down()
    {
        // Удаляем PostGIS поля из таблицы locations
        $this->removePostGisFieldsFromLocations();
        
        // Удаляем PostGIS поля из таблицы user_location_polygons
        $this->removePostGisFieldsFromUserLocationPolygons();
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
     * Добавляет PostGIS поля в таблицу locations
     */
    private function addPostGisFieldsToLocations()
    {
        // Проверяем, есть ли уже колонка center_point в таблице locations
        $hasPostGisFields = $this->checkIfColumnExists('locations', 'center_point');
        
        if (!$hasPostGisFields) {
            // Добавляем PostGIS поля
            Manager::statement('ALTER TABLE locations ADD COLUMN center_point GEOMETRY(Point, 4326)');
            Manager::statement('ALTER TABLE locations ADD COLUMN bounds_polygon GEOMETRY(Polygon, 4326)');
            
            // Создаем индексы для ускорения геопространственных запросов
            Manager::statement('CREATE INDEX idx_locations_center_point ON locations USING GIST(center_point)');
            Manager::statement('CREATE INDEX idx_locations_bounds_polygon ON locations USING GIST(bounds_polygon)');
        }
    }
    
    /**
     * Добавляет PostGIS поля в таблицу user_location_polygons
     */
    private function addPostGisFieldsToUserLocationPolygons()
    {
        // Проверяем, есть ли уже колонка polygon в таблице user_location_polygons
        $hasPostGisFields = $this->checkIfColumnExists('user_location_polygons', 'polygon');
        
        if (!$hasPostGisFields) {
            // Добавляем PostGIS поля
            Manager::statement('ALTER TABLE user_location_polygons ADD COLUMN polygon GEOMETRY(Polygon, 4326)');
            Manager::statement('ALTER TABLE user_location_polygons ADD COLUMN center_point GEOMETRY(Point, 4326)');
            
            // Создаем индексы для ускорения геопространственных запросов
            Manager::statement('CREATE INDEX idx_user_location_polygons_polygon ON user_location_polygons USING GIST(polygon)');
            Manager::statement('CREATE INDEX idx_user_location_polygons_center_point ON user_location_polygons USING GIST(center_point)');
        }
    }
    
    /**
     * Обновляет PostGIS данные для существующих локаций
     */
    private function updateLocationsWithPostGisData()
    {
        // Получаем список всех локаций
        $locations = Manager::table('locations')->get();
        
        foreach ($locations as $location) {
            // Пропускаем локации без координат
            if (!isset($location->center_lat) || !isset($location->center_lng) || !isset($location->bounds)) {
                continue;
            }
            
            $bounds = json_decode($location->bounds, true);
            if (!$bounds) {
                continue;
            }
            
            // Создаем WKT для полигона границ
            $wktPolygon = sprintf(
                'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
                $bounds['west'], $bounds['south'], // левый нижний угол
                $bounds['east'], $bounds['south'], // правый нижний угол
                $bounds['east'], $bounds['north'], // правый верхний угол
                $bounds['west'], $bounds['north'], // левый верхний угол
                $bounds['west'], $bounds['south']  // замыкаем полигон
            );
            
            // Обновляем PostGIS поля
            Manager::statement(
                "UPDATE locations SET 
                center_point = ST_SetSRID(ST_MakePoint(?, ?), 4326),
                bounds_polygon = ST_SetSRID(ST_GeomFromText(?), 4326)
                WHERE id = ?",
                [
                    $location->center_lng, $location->center_lat, // ST_MakePoint принимает (longitude, latitude)
                    $wktPolygon, 
                    $location->id
                ]
            );
        }
    }
    
    /**
     * Удаляет PostGIS поля из таблицы locations
     */
    private function removePostGisFieldsFromLocations()
    {
        // Проверяем, есть ли колонка center_point в таблице locations
        $hasPostGisFields = $this->checkIfColumnExists('locations', 'center_point');
        
        if ($hasPostGisFields) {
            // Удаляем индексы
            Manager::statement('DROP INDEX IF EXISTS idx_locations_center_point');
            Manager::statement('DROP INDEX IF EXISTS idx_locations_bounds_polygon');
            
            // Удаляем колонки
            Manager::statement('ALTER TABLE locations DROP COLUMN IF EXISTS center_point');
            Manager::statement('ALTER TABLE locations DROP COLUMN IF EXISTS bounds_polygon');
        }
    }
    
    /**
     * Удаляет PostGIS поля из таблицы user_location_polygons
     */
    private function removePostGisFieldsFromUserLocationPolygons()
    {
        // Проверяем, есть ли колонка polygon в таблице user_location_polygons
        $hasPostGisFields = $this->checkIfColumnExists('user_location_polygons', 'polygon');
        
        if ($hasPostGisFields) {
            // Удаляем индексы
            Manager::statement('DROP INDEX IF EXISTS idx_user_location_polygons_polygon');
            Manager::statement('DROP INDEX IF EXISTS idx_user_location_polygons_center_point');
            
            // Удаляем колонки
            Manager::statement('ALTER TABLE user_location_polygons DROP COLUMN IF EXISTS polygon');
            Manager::statement('ALTER TABLE user_location_polygons DROP COLUMN IF EXISTS center_point');
        }
    }
    
    /**
     * Проверяет, существует ли колонка в таблице
     * 
     * @param string $table Имя таблицы
     * @param string $column Имя колонки
     * @return bool
     */
    private function checkIfColumnExists(string $table, string $column): bool
    {
        $result = Manager::select("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = ? 
            AND column_name = ?
        ", [$table, $column]);
        
        return !empty($result);
    }
} 