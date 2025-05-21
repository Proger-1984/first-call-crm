<?php

use Illuminate\Database\Capsule\Manager;

class CreateAgentListingsTable
{
    public function getTableName(): string
    {
        return 'agent_listings';
    }

    public function up()
    {
        Manager::schema()->create('agent_listings', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Внешний ключ к пользователю (агенту)
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Внешний ключ к объявлению
            $table->unsignedInteger('listing_id');
            $table->foreign('listing_id')->references('id')->on('listings')->onDelete('cascade');
            
            // Внешний ключ к статусу звонка
            $table->unsignedInteger('call_status_id')->nullable();
            $table->foreign('call_status_id')->references('id')->on('call_statuses');
            
            // Заметки агента об объявлении
            $table->text('notes')->nullable();
            
            // Уникальный индекс для пары агент-объявление
            $table->unique(['user_id', 'listing_id']);
            
            // Индексы для частых запросов
            $table->index(['user_id', 'call_status_id']);
            $table->index(['listing_id', 'call_status_id']);
            
            // Метки времени создания и обновления записи
            $table->timestamps();
        });
    }

    public function down()
    {
        Manager::schema()->drop('agent_listings');
    }
} 