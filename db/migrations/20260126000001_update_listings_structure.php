<?php

use Illuminate\Database\Capsule\Manager;

class UpdateListingsStructure
{
    public function getTableName(): string
    {
        return 'listings';
    }

    /**
     * Указывает что миграция изменяет существующую таблицу
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Удаляем индексы если есть
        Manager::statement('DROP INDEX IF EXISTS listings_auto_call_processed_at_index');
        Manager::statement('DROP INDEX IF EXISTS listings_city_id_index');
        Manager::statement('DROP INDEX IF EXISTS listings_location_id_city_id_index');
        
        // Удаляем foreign key на city_id если есть
        try {
            Manager::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS listings_city_id_foreign');
        } catch (\Exception $e) {
            // Игнорируем если constraint не существует
        }
        
        // Удаляем ненужные столбцы по одному
        if (Manager::schema()->hasColumn('listings', 'city_id')) {
            Manager::statement('ALTER TABLE listings DROP COLUMN city_id');
        }
        
        if (Manager::schema()->hasColumn('listings', 'deleted_at')) {
            Manager::statement('ALTER TABLE listings DROP COLUMN deleted_at');
        }
        
        if (Manager::schema()->hasColumn('listings', 'auto_call_processed_at')) {
            Manager::statement('ALTER TABLE listings DROP COLUMN auto_call_processed_at');
        }

        // Переименовываем building в house
        if (Manager::schema()->hasColumn('listings', 'building')) {
            Manager::statement('ALTER TABLE listings RENAME COLUMN building TO house');
        }
    }

    public function down()
    {
        Manager::schema()->table('listings', function ($table) {
            $table->unsignedInteger('city_id')->nullable();
            $table->softDeletes();
            $table->timestamp('auto_call_processed_at')->nullable();
            $table->index(['auto_call_processed_at']);
        });

        if (Manager::schema()->hasColumn('listings', 'house')) {
            Manager::statement('ALTER TABLE listings RENAME COLUMN house TO building');
        }
    }
}
