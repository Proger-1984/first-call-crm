<?php

use Illuminate\Database\Capsule\Manager;

class RemovePromotedFieldsFromListings
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
        // Удаляем индекс
        Manager::statement('DROP INDEX IF EXISTS listings_is_promoted_promoted_at_index');
        
        // Удаляем поля
        Manager::schema()->table('listings', function ($table) {
            $table->dropColumn(['is_promoted', 'promoted_at']);
        });
    }

    public function down()
    {
        Manager::schema()->table('listings', function ($table) {
            $table->boolean('is_promoted')->default(false);
            $table->timestamp('promoted_at')->nullable();
            $table->index(['is_promoted', 'promoted_at']);
        });
    }
}
