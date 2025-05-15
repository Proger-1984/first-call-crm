<?php

use Illuminate\Database\Capsule\Manager;

class CreateUserLocationPolygonsTable
{
    public function getTableName(): string
    {
        return 'user_location_polygons';
    }

    public function up()
    {
        // Включаем расширение PostGIS, если еще не включено
        $this->enablePostGIS();
        
        // Создаем таблицу для хранения пользовательских локаций и полигонов
        Manager::schema()->create('user_location_polygons', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();          // ID пользователя
            $table->integer('subscription_id')->unsigned();  // ID подписки
            $table->string('name');                         // Название локации
            $table->json('polygon_coordinates');            // Координаты полигона в формате GeoJSON (для совместимости)
            $table->decimal('center_lat', 10, 6)->nullable(); // Широта центральной точки
            $table->decimal('center_lng', 10, 6)->nullable(); // Долгота центральной точки
            $table->json('bounds')->nullable();              // Границы локации {north, east, south, west}
            $table->timestamps();
            
            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('subscription_id')->references('id')->on('user_subscriptions')->onDelete('cascade');
        });
        
        // Добавляем PostGIS-специфичные геометрические колонки
        Manager::statement('ALTER TABLE user_location_polygons ADD COLUMN polygon GEOMETRY(Polygon, 4326)');
        Manager::statement('ALTER TABLE user_location_polygons ADD COLUMN center_point GEOMETRY(Point, 4326)');
        
        // Создаем индексы для ускорения геопространственных запросов
        Manager::statement('CREATE INDEX idx_user_location_polygons_polygon ON user_location_polygons USING GIST(polygon)');
        Manager::statement('CREATE INDEX idx_user_location_polygons_center_point ON user_location_polygons USING GIST(center_point)');
    }

    public function down()
    {
        // Удаляем индексы и геометрические колонки PostGIS
        Manager::statement('DROP INDEX IF EXISTS idx_user_location_polygons_polygon');
        Manager::statement('DROP INDEX IF EXISTS idx_user_location_polygons_center_point');
        
        // Удаляем таблицу при откате миграции
        Manager::schema()->drop('user_location_polygons');
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
} 