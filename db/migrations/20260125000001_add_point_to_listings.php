<?php

use Illuminate\Database\Capsule\Manager;

class AddPointToListings
{
    public function getTableName(): string
    {
        return 'listings_point_migration';
    }

    /**
     * Указывает, что эта миграция модифицирует существующие таблицы
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Проверяем, есть ли уже колонка point в таблице listings
        $hasPointField = $this->checkIfColumnExists('listings', 'point');
        
        if (!$hasPointField) {
            // Добавляем PostGIS поле для точки
            Manager::statement('ALTER TABLE listings ADD COLUMN point GEOMETRY(Point, 4326)');
            
            // Создаем GIST индекс для ускорения геопространственных запросов
            Manager::statement('CREATE INDEX idx_listings_point ON listings USING GIST(point)');
        }
        
        // Заполняем point для существующих записей с координатами
        $this->updateExistingListingsWithPoint();
    }

    public function down()
    {
        $hasPointField = $this->checkIfColumnExists('listings', 'point');
        
        if ($hasPointField) {
            Manager::statement('DROP INDEX IF EXISTS idx_listings_point');
            Manager::statement('ALTER TABLE listings DROP COLUMN IF EXISTS point');
        }
    }
    
    /**
     * Обновляет PostGIS point для существующих объявлений с координатами
     */
    private function updateExistingListingsWithPoint()
    {
        // Обновляем все записи, у которых есть lat и lng, но нет point
        Manager::statement("
            UPDATE listings 
            SET point = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE lat IS NOT NULL 
            AND lng IS NOT NULL 
            AND point IS NULL
        ");
    }
    
    /**
     * Проверяет, существует ли колонка в таблице
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
