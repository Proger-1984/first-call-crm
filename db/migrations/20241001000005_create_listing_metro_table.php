<?php

use Illuminate\Database\Capsule\Manager;

class CreateListingMetroTable
{
    public function getTableName(): string
    {
        return 'listing_metro';
    }

    public function up()
    {
        Manager::schema()->create('listing_metro', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Внешний ключ к объявлению
            $table->unsignedInteger('listing_id');
            $table->foreign('listing_id')->references('id')->on('listings')->onDelete('cascade');
            
            // Внешний ключ к станции метро
            $table->unsignedInteger('metro_station_id');
            $table->foreign('metro_station_id')->references('id')->on('metro_stations')->onDelete('cascade');
            
            // Время пешком до метро в минутах
            $table->unsignedSmallInteger('travel_time_min')->nullable();
            
            // Способ передвижения до метро: 'walk', 'car', 'public_transport'
            $table->string('travel_type', 20)->default('walk');
            
            // Уникальность связи объявление-метро
            $table->unique(['listing_id', 'metro_station_id']);
            
            // Метки времени создания и обновления записи
            $table->timestamps();
        });
    }

    public function down()
    {
        Manager::schema()->drop('listing_metro');
    }
} 