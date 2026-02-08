<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Добавление price_history в таблицу listings
 * 
 * Поле для хранения истории изменения цен в формате JSON.
 * Формат: [{"price": 50000, "date": "2026-02-01"}, {"price": 45000, "date": "2026-02-05"}]
 */
class AddPriceHistoryToListings
{
    public function getTableName(): string
    {
        return 'listings';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up(): void
    {
        if (!Capsule::schema()->hasColumn($this->getTableName(), 'price_history')) {
            Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
                $table->jsonb('price_history')
                    ->nullable()
                    ->after('price')
                    ->comment('История изменения цен в формате JSON');
            });
        }
    }

    public function down(): void
    {
        if (Capsule::schema()->hasColumn($this->getTableName(), 'price_history')) {
            Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
                $table->dropColumn('price_history');
            });
        }
    }
}
