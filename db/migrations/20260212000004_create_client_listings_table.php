<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы client_listings
 *
 * Привязка объявлений к клиенту (подборка).
 * Связь many-to-many между clients и listings со статусом и комментарием.
 */
class CreateClientListingsTable
{
    public function getTableName(): string
    {
        return 'client_listings';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('client_listings')) {
            Manager::schema()->create('client_listings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id')->comment('ID клиента');
                $table->unsignedBigInteger('listing_id')->comment('ID объявления');
                $table->string('status', 20)->default('proposed')->comment('Статус: proposed/showed/liked/rejected');
                $table->string('comment', 500)->nullable()->comment('Комментарий агента');
                $table->timestamp('showed_at')->nullable()->comment('Дата показа');
                $table->timestamps();

                // Уникальный индекс — одно объявление привязывается к клиенту только один раз
                $table->unique(['client_id', 'listing_id'], 'client_listings_client_listing_unique');

                // Индексы для фильтрации
                $table->index(['client_id', 'status'], 'client_listings_client_status_index');
                $table->index('listing_id', 'client_listings_listing_index');

                // Внешние ключи
                $table->foreign('client_id')
                    ->references('id')
                    ->on('clients')
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
        Manager::schema()->dropIfExists('client_listings');
    }
}
