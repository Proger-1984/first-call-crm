<?php

use Illuminate\Database\Capsule\Manager;

class CreateCallStatusesTable
{
    public function getTableName(): string
    {
        return 'call_statuses';
    }

    public function up()
    {
        Manager::schema()->create('call_statuses', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Отображаемое название статуса
            $table->string('name', 100);
            
            // Цвет для отображения в интерфейсе
            $table->string('color', 20)->nullable();
            
            // Порядок сортировки в UI
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
        
        // Добавляем начальные статусы звонков
        Manager::table('call_statuses')->insert([
            ['name' => 'Наша квартира', 'color' => '#4CAF50', 'sort_order' => 10],
            ['name' => 'Не дозвонился', 'color' => '#FFC107', 'sort_order' => 20],
            ['name' => 'Не снял', 'color' => '#FF9800', 'sort_order' => 30],
            ['name' => 'Агент', 'color' => '#F44336', 'sort_order' => 40],
            ['name' => 'Не первые', 'color' => '#9C27B0', 'sort_order' => 50],
            ['name' => 'Звонок', 'color' => '#2196F3', 'sort_order' => 60],
        ]);
    }

    public function down()
    {
        Manager::schema()->drop('call_statuses');
    }
} 