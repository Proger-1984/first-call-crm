<?php

use Illuminate\Database\Capsule\Manager;

class CreateRoomsTable
{
    public function getTableName(): string
    {
        return 'rooms';
    }

    public function up()
    {
        // Таблица типов комнат
        Manager::schema()->create('rooms', function ($table) {
            $table->increments('id');
            $table->string('name', 50);           // Название: "1-комн", "2-комн", "Студия" и т.д.
            $table->string('code', 20)->unique(); // Код: "1", "2", "studio", "free"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Связь комнат с категориями (какие типы комнат доступны для какой категории)
        Manager::schema()->create('category_rooms', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('room_id');
            
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
            
            $table->unique(['category_id', 'room_id']);
        });

        // Заполняем типы комнат
        $rooms = [
            ['name' => 'Студия', 'code' => 'studio', 'sort_order' => 1],
            ['name' => '1-комн', 'code' => '1', 'sort_order' => 2],
            ['name' => '2-комн', 'code' => '2', 'sort_order' => 3],
            ['name' => '3-комн', 'code' => '3', 'sort_order' => 4],
            ['name' => '4-комн', 'code' => '4', 'sort_order' => 5],
            ['name' => '5+ комн', 'code' => '5+', 'sort_order' => 6],
            ['name' => 'Свободная планировка', 'code' => 'free', 'sort_order' => 7],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($rooms as &$room) {
            $room['created_at'] = $now;
            $room['updated_at'] = $now;
        }
        Manager::table('rooms')->insert($rooms);

        // Связываем комнаты с категориями жилой недвижимости (id=1 и id=3)
        // Аренда жилая (Квартиры) - id=1
        // Продажа жилая (Квартиры) - id=3
        $roomIds = Manager::table('rooms')->pluck('id')->toArray();
        $categoryRooms = [];
        
        foreach ([1, 3] as $categoryId) {
            foreach ($roomIds as $roomId) {
                $categoryRooms[] = [
                    'category_id' => $categoryId,
                    'room_id' => $roomId,
                ];
            }
        }
        
        Manager::table('category_rooms')->insert($categoryRooms);
    }

    public function down()
    {
        Manager::schema()->dropIfExists('category_rooms');
        Manager::schema()->dropIfExists('rooms');
    }
}
