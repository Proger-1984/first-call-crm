<?php

use Illuminate\Database\Capsule\Manager;

class CreateCategoriesTable
{
    public function getTableName(): string
    {
        return 'categories';
    }

    public function up()
    {
        Manager::schema()->create('categories', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        $categories = [
            ['name' => 'Аренда жилая (Квартиры)', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => 'Аренда (Коммерческая недвижимость)', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => 'Продажа жилая (Квартиры)', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => 'Продажа (Коммерческая недвижимость)', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        ];

        Manager::table('categories')->insert($categories);
    }

    public function down()
    {
        Manager::schema()->drop('categories');
    }
} 