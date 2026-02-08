<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы user_favorite_statuses
 * 
 * Таблица для хранения пользовательских статусов избранных объявлений.
 * Каждый пользователь может создавать свои статусы.
 */
class CreateUserFavoriteStatusesTable
{
    public function getTableName(): string
    {
        return 'user_favorite_statuses';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up(): void
    {
        if (!Capsule::schema()->hasTable($this->getTableName())) {
            Capsule::schema()->create($this->getTableName(), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('ID пользователя');
                $table->string('name', 50)->comment('Название статуса');
                $table->string('color', 7)->default('#808080')->comment('Цвет статуса в HEX формате');
                $table->unsignedSmallInteger('sort_order')->default(0)->comment('Порядок сортировки');
                $table->timestamps();
                
                // Индексы
                $table->index('user_id', 'user_favorite_statuses_user_id_index');
                $table->unique(['user_id', 'name'], 'user_favorite_statuses_user_name_unique');
                
                // Внешний ключ
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists($this->getTableName());
    }
}
