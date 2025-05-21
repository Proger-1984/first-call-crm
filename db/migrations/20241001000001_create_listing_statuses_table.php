<?php

use Illuminate\Database\Capsule\Manager;

class CreateListingStatusesTable
{
    public function getTableName(): string
    {
        return 'listing_statuses';
    }

    public function up()
    {
        Manager::schema()->create('listing_statuses', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Название статуса
            $table->string('name', 50)->unique();
            
            // Цвет для отображения в интерфейсе
            $table->string('color', 20)->nullable();
            
            // Порядок сортировки в UI
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
        
        // Добавляем начальные статусы
        Manager::table('listing_statuses')->insert([
            ['name' => 'Новое', 'color' => '#4CAF50', 'sort_order' => 10],
            ['name' => 'Поднятое', 'color' => '#2196F3', 'sort_order' => 20],
            ['name' => 'Удалено', 'color' => '#F44336', 'sort_order' => 30],
        ]);
    }

    public function down()
    {
        Manager::schema()->drop('listing_statuses');
    }
} 