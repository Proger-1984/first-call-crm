<?php

use Illuminate\Database\Capsule\Manager;

class CreateMetroStationsTable
{
    public function getTableName(): string
    {
        return 'metro_stations';
    }

    public function up()
    {
        Manager::schema()->create('metro_stations', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Внешний ключ к таблице локаций (для группировки станций по городам)
            $table->unsignedInteger('location_id');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            
            // Название станции метро
            $table->string('name', 100);
            
            // Линия метро
            $table->string('line', 100)->nullable();
            
            // Цвет линии метро для отображения в UI
            $table->string('color', 20)->nullable();
            
            // Широта и долгота для показа на карте
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            
            // Уникальность станции метро в пределах города
            $table->unique(['location_id', 'name', 'line']);

        });
    }

    public function down()
    {
        Manager::schema()->drop('metro_stations');
    }
} 