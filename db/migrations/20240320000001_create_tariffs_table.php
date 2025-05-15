<?php

use Illuminate\Database\Capsule\Manager;

class CreateTariffsTable
{
    public function getTableName(): string
    {
        return 'tariffs';
    }

    public function up()
    {
        Manager::schema()->create('tariffs', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code');
            $table->integer('duration_hours');
            $table->decimal('price', 10, 2);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /** Заполняем таблицу тарифами
         * 30 дней * 24 часа
         */
        Manager::table('tariffs')->insert([
            [
                'name' => 'Demo',
                'code' => 'demo',
                'duration_hours' => 3,
                'price' => 0,
                'description' => 'Демо-версия с ограниченным функционалом на 3 часа',
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => '1 день',
                'code' => 'day1',
                'duration_hours' => 24,
                'price' => 1000.00,
                'description' => 'Полный доступ ко всем функциям на 1 день',
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => '3 дня',
                'code' => 'day3',
                'duration_hours' => 72,
                'price' => 3000.00,
                'description' => 'Полный доступ ко всем функциям на 3 дня',
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => '7 дней',
                'code' => 'day7',
                'duration_hours' => 168,
                'price' => 5000.00,
                'description' => 'Полный доступ ко всем функциям на 7 дней',
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Premium',
                'code' => 'premium',
                'duration_hours' => 744,
                'price' => 5000.00,
                'description' => 'Полный доступ ко всем функциям на 31 день',
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function down()
    {
        Manager::schema()->drop('tariffs');
    }
} 