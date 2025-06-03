<?php

use Illuminate\Database\Capsule\Manager;

class CreateCitiesTable
{
    public function getTableName(): string
    {
        return 'cities';
    }

    public function up()
    {
        Manager::schema()->create('cities', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Название города/района/поселения
            $table->string('name', 100);
            
            // FK к основному городу в группе (self-reference)
            $table->unsignedInteger('city_parent_id');
            
            // FK к основной локации/региону
            $table->unsignedInteger('location_parent_id');
            $table->foreign('location_parent_id')->references('id')->on('locations')->onDelete('cascade');
            
            // Координаты (опционально)
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            
            // Метки времени
            $table->timestamps();
            
            // Индексы для быстрого поиска
            $table->index(['city_parent_id']);
            $table->index(['location_parent_id']);
            $table->index(['location_parent_id', 'city_parent_id']);
            
            // Уникальность названия в рамках группы
            $table->unique(['name', 'city_parent_id']);
        });
    }

    public function down()
    {
        Manager::schema()->drop('cities');
    }
} 