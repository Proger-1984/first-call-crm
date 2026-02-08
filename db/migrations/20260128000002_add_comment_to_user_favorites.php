<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: добавление поля comment в таблицу user_favorites
 */
class AddCommentToUserFavorites
{
    /**
     * Имя таблицы для миграции
     */
    public function getTableName(): string
    {
        return 'user_favorites';
    }

    /**
     * Модифицирует существующую таблицу
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    /**
     * Применить миграцию
     */
    public function up(): void
    {
        Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
            $table->string('comment', 250)->nullable()->after('listing_id');
        });
    }

    /**
     * Откатить миграцию
     */
    public function down(): void
    {
        Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
}
