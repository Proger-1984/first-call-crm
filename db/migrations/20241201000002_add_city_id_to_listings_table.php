<?php

use Illuminate\Database\Capsule\Manager;

class AddCityIdToListingsTable
{
    public function getTableName(): string
    {
        return 'listings';
    }
    
    /**
     * Указывает, что эта миграция модифицирует существующую таблицу
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Проверяем, существуют ли уже поля
        $hasCityId = $this->checkIfColumnExists('listings', 'city_id');
        $hasLocationId = $this->checkIfColumnExists('listings', 'location_id');
        
        Manager::schema()->table('listings', function ($table) use ($hasCityId, $hasLocationId) {
            // Связь с основной локацией
            if (!$hasLocationId) {
                $table->unsignedInteger('location_id')->nullable();
                $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
            }
            
            // Связь с конкретным городом
            if (!$hasCityId) {
                $table->unsignedInteger('city_id')->nullable();
                $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
            }
        });
        
        // Добавляем индексы отдельно
        if (!$hasCityId || !$hasLocationId) {
            Manager::schema()->table('listings', function ($table) {
                $table->index(['location_id']);
                $table->index(['city_id']);
                $table->index(['location_id', 'city_id']);
            });
        }
    }

    public function down()
    {
        Manager::schema()->table('listings', function ($table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['city_id']);
            $table->dropIndex(['location_id']);
            $table->dropIndex(['city_id']);
            $table->dropIndex(['location_id', 'city_id']);
            $table->dropColumn(['location_id', 'city_id']);
        });
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