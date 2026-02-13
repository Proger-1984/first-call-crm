<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы properties
 *
 * Объект недвижимости — главная сущность новой CRM модели.
 * Может быть привязан к объявлению из парсера (listing_id) или создан вручную.
 */
class CreatePropertiesTable
{
    public function getTableName(): string
    {
        return 'properties';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('properties')) {
            Manager::schema()->create('properties', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('ID агента-владельца');
                $table->unsignedBigInteger('listing_id')->nullable()->comment('Связь с парсером объявлений');
                $table->string('title', 500)->nullable()->comment('Название объекта');
                $table->string('address', 500)->nullable()->comment('Адрес');
                $table->decimal('price', 15, 2)->nullable()->comment('Цена');
                $table->smallInteger('rooms')->nullable()->comment('Количество комнат');
                $table->decimal('area', 10, 2)->nullable()->comment('Площадь, м²');
                $table->smallInteger('floor')->nullable()->comment('Этаж');
                $table->smallInteger('floors_total')->nullable()->comment('Этажей в доме');
                $table->text('description')->nullable()->comment('Описание объекта');
                $table->string('url', 1000)->nullable()->comment('Ссылка на объявление');
                $table->string('deal_type', 10)->default('sale')->comment('Тип сделки: sale/rent');
                $table->string('owner_name', 255)->nullable()->comment('Имя собственника');
                $table->string('owner_phone', 20)->nullable()->comment('Телефон собственника');
                $table->string('owner_phone_secondary', 20)->nullable()->comment('Доп. телефон собственника');
                $table->string('source_type', 50)->nullable()->comment('Источник: avito, cian, звонок и т.д.');
                $table->string('source_details', 255)->nullable()->comment('Детали источника');
                $table->text('comment')->nullable()->comment('Комментарий агента');
                $table->boolean('is_archived')->default(false)->comment('В архиве');
                $table->timestamps();

                // Индексы для фильтрации
                $table->index(['user_id', 'is_archived'], 'properties_user_archived_index');
                $table->index(['user_id', 'deal_type'], 'properties_user_deal_type_index');
                $table->index('listing_id', 'properties_listing_index');
                $table->index('owner_phone', 'properties_owner_phone_index');

                // Внешние ключи
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');

                $table->foreign('listing_id')
                    ->references('id')
                    ->on('listings')
                    ->onDelete('set null');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('properties');
    }
}
