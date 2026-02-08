<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы user_favorites
 * 
 * Таблица для хранения избранных объявлений пользователей.
 * Связь many-to-many между users и listings.
 */
class CreateUserFavoritesTable
{
    public function getTableName(): string
    {
        return 'user_favorites';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('user_favorites')) {
            Manager::schema()->create('user_favorites', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('ID пользователя');
                $table->unsignedBigInteger('listing_id')->comment('ID объявления');
                $table->timestamp('created_at')->useCurrent()->comment('Дата добавления в избранное');
                
                // Уникальный индекс — пользователь не может добавить одно объявление дважды
                $table->unique(['user_id', 'listing_id'], 'user_favorites_user_listing_unique');
                
                // Индексы для быстрого поиска
                $table->index('user_id', 'user_favorites_user_id_index');
                $table->index('listing_id', 'user_favorites_listing_id_index');
                
                // Внешние ключи
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
                    
                $table->foreign('listing_id')
                    ->references('id')
                    ->on('listings')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('user_favorites');
    }
}
