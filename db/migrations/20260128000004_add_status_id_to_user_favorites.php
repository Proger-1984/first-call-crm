<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Добавление status_id в таблицу user_favorites
 * 
 * Связывает избранные объявления с пользовательскими статусами.
 */
class AddStatusIdToUserFavorites
{
    public function getTableName(): string
    {
        return 'user_favorites';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up(): void
    {
        if (!Capsule::schema()->hasColumn($this->getTableName(), 'status_id')) {
            Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
                $table->unsignedBigInteger('status_id')
                    ->nullable()
                    ->after('comment')
                    ->comment('ID пользовательского статуса');
                
                $table->index('status_id', 'user_favorites_status_id_index');
                
                $table->foreign('status_id')
                    ->references('id')
                    ->on('user_favorite_statuses')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Capsule::schema()->hasColumn($this->getTableName(), 'status_id')) {
            Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
                $table->dropForeign(['status_id']);
                $table->dropIndex('user_favorites_status_id_index');
                $table->dropColumn('status_id');
            });
        }
    }
}
