<?php

use Illuminate\Database\Capsule\Manager;

class CreateLocationProxiesTable
{
    public function getTableName(): string
    {
        return 'location_proxies';
    }

    public function up()
    {
        Manager::schema()->create('location_proxies', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Прокси в формате ip:port
            $table->string('proxy', 255);
            
            // Внешний ключ к источнику (1, 2, 3 и т.д.)
            $table->unsignedInteger('source_id');
            $table->foreign('source_id')->references('id')->on('sources');

            // Внешний ключ к локации (1, 2, 3 и т.д.)
            $table->unsignedInteger('location_id');
            $table->foreign('location_id')->references('id')->on('locations');

            // Внешний ключ к категории (1, 2)
            $table->unsignedInteger('category_id');
            $table->foreign('category_id')->references('id')->on('categories');
            
            // Уникальный индекс для комбинации прокси + источник
            $table->unique(['proxy', 'source_id', 'location_id', 'category_id']);
            
            // Индекс для поиска по источнику
            $table->index(['source_id']);
            $table->index(['location_id']);
            $table->index(['category_id']);
            
            // Метки времени создания и обновления записи
            $table->timestamps();
        });
    }

    public function down()
    {
        Manager::schema()->drop('location_proxies');
    }
} 