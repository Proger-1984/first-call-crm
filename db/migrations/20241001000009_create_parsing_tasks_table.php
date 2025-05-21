<?php

use Illuminate\Database\Capsule\Manager;

class CreateParsingTasksTable
{
    public function getTableName(): string
    {
        return 'listing_photo_tasks';
    }

    public function up()
    {
        Manager::schema()->create('listing_photo_tasks', function ($table) {
            // Первичный ключ - такой же как в таблице listings
            $table->unsignedInteger('id');
            $table->primary('id');
            
            // Внешний ключ к listings (тот же id)
            $table->foreign('id')->references('id')->on('listings')->onDelete('cascade');
            
            // Источник объявления (Авито, ЦИАН, Юла и т.д.)
            $table->unsignedInteger('source_id');
            $table->foreign('source_id')->references('id')->on('sources');
            
            // URL объявления
            $table->string('url', 1000);
            
            // Статус задачи парсинга (pending, processing, completed, failed)
            $table->string('status', 20)->default('pending');
            
            // Метки времени
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            
            // Индексы
            $table->index('status');
            $table->index('created_at');
            $table->index('source_id');
        });
    }

    public function down()
    {
        Manager::schema()->drop('listing_photo_tasks');
    }
} 