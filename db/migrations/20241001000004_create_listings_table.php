<?php

use Illuminate\Database\Capsule\Manager;

class CreateListingsTable
{
    public function getTableName(): string
    {
        return 'listings';
    }

    public function up()
    {
        Manager::schema()->create('listings', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Внешний ключ к источнику объявления (Авито, ЦИАН, Юла и т.д.)
            $table->unsignedInteger('source_id');
            $table->foreign('source_id')->references('id')->on('sources');
            
            // Внешний ключ к категории объявления
            $table->unsignedInteger('category_id');
            $table->foreign('category_id')->references('id')->on('categories');
            
            // Внешний ключ к статусу объявления
            $table->unsignedInteger('listing_status_id')->default(1);
            $table->foreign('listing_status_id')->references('id')->on('listing_statuses');
            
            // Идентификатор объявления во внешней системе (уникален для конкретного источника)
            $table->string('external_id', 100);
            
            // Заголовок объявления
            $table->string('title')->nullable();
            
            // Полное описание объявления
            $table->text('description')->nullable();
            
            // Количество комнат
            $table->unsignedSmallInteger('rooms')->nullable();
            
            // Цена
            $table->decimal('price', 12, 2)->nullable();
            
            // Площадь в кв.м.
            $table->decimal('square_meters', 8, 2)->nullable();
            
            // Этаж квартиры
            $table->unsignedSmallInteger('floor')->nullable();
            
            // Всего этажей в доме
            $table->unsignedSmallInteger('floors_total')->nullable();
            
            // Контактный телефон продавца/арендодателя
            $table->string('phone', 20)->nullable();
            
            // Город
            $table->string('city', 100)->nullable();
            
            // Улица
            $table->string('street', 150)->nullable();
            
            // Номер дома
            $table->string('building', 20)->nullable();
            
            // Полный адрес (может быть полезен для поиска)
            $table->string('address', 255)->nullable();
            
            // URL оригинального объявления
            $table->string('url', 255)->nullable();
            
            // Географические координаты
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            
            // Флаг поднятого объявления
            $table->boolean('is_promoted')->default(false);
            
            // Флаг платного объявления
            $table->boolean('is_paid')->default(false);
            
            // Дата и время поднятия объявления
            $table->timestamp('promoted_at')->nullable();
            
            // Дата и время обработки автозвонком
            $table->timestamp('auto_call_processed_at')->nullable();
            
            // Уникальный индекс для комбинации источник + внешний ID
            $table->unique(['source_id', 'external_id']);
            
            // Индексы для частых запросов
            $table->index(['listing_status_id', 'created_at']);
            $table->index(['is_promoted', 'promoted_at']);
            $table->index(['is_paid']);
            $table->index(['auto_call_processed_at']);
            $table->index(['category_id', 'created_at']);
            
            // Метки времени создания и обновления записи
            $table->timestamps();
            
            // Метка мягкого удаления
            $table->softDeletes();
        });
    }

    public function down()
    {
        Manager::schema()->drop('listings');
    }
} 