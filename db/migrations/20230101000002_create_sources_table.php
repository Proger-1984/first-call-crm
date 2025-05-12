<?php

use Illuminate\Database\Capsule\Manager;

class CreateSourcesTable
{
    public function getTableName(): string
    {
        return 'sources';
    }

    public function up()
    {
        Manager::schema()->create('sources', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $sources = [
            ['name' => 'Авито', 'is_active' => true, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => 'Яндекс.Н', 'is_active' => true, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => 'Циан', 'is_active' => true, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['name' => 'ЮЛА', 'is_active' => true, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        ];

        Manager::table('sources')->insert($sources);
    }

    public function down()
    {
        Manager::schema()->drop('sources');
    }
} 