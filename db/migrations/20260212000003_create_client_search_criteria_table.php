<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы client_search_criteria
 *
 * Критерии поиска клиента — что ищет клиент (категория, район, метро, площадь, этаж и т.д.).
 * У одного клиента может быть несколько критериев.
 */
class CreateClientSearchCriteriaTable
{
    public function getTableName(): string
    {
        return 'client_search_criteria';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('client_search_criteria')) {
            Manager::schema()->create('client_search_criteria', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id')->comment('ID клиента');
                $table->unsignedBigInteger('category_id')->nullable()->comment('Категория недвижимости');
                $table->unsignedBigInteger('location_id')->nullable()->comment('Локация (город/регион)');
                $table->jsonb('room_ids')->nullable()->comment('Типы комнат (массив ID)');
                $table->decimal('price_min', 15, 2)->nullable()->comment('Минимальная цена');
                $table->decimal('price_max', 15, 2)->nullable()->comment('Максимальная цена');
                $table->decimal('area_min', 10, 2)->nullable()->comment('Минимальная площадь');
                $table->decimal('area_max', 10, 2)->nullable()->comment('Максимальная площадь');
                $table->smallInteger('floor_min')->nullable()->comment('Минимальный этаж');
                $table->smallInteger('floor_max')->nullable()->comment('Максимальный этаж');
                $table->jsonb('metro_ids')->nullable()->comment('Станции метро (массив ID)');
                $table->jsonb('districts')->nullable()->comment('Районы (массив строк)');
                $table->text('notes')->nullable()->comment('Примечания к критерию');
                $table->boolean('is_active')->default(true)->comment('Активен ли критерий');
                $table->timestamps();

                // Индексы
                $table->index('client_id', 'client_search_criteria_client_index');
                $table->index(['category_id', 'location_id', 'is_active'], 'client_search_criteria_cat_loc_active_index');

                // Внешние ключи
                $table->foreign('client_id')
                    ->references('id')
                    ->on('clients')
                    ->onDelete('cascade');

                $table->foreign('category_id')
                    ->references('id')
                    ->on('categories')
                    ->onDelete('set null');

                $table->foreign('location_id')
                    ->references('id')
                    ->on('locations')
                    ->onDelete('set null');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('client_search_criteria');
    }
}
