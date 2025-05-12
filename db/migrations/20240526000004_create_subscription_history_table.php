<?php

use Illuminate\Database\Capsule\Manager;

class CreateSubscriptionHistoryTable
{
    public function getTableName(): string
    {
        return 'subscription_history';
    }

    public function up()
    {
        // Создаем таблицу для хранения истории подписок (для биллинга)
        Manager::schema()->create('subscription_history', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();            // ID пользователя
            $table->integer('subscription_id')->unsigned()->nullable(); // ID подписки (может быть NULL, если подписка удалена)
            $table->string('action');                         // Тип действия: created, expired, cancelled, renewed
            $table->string('tariff_name');                    // Название тарифа на момент действия
            $table->string('category_name');                  // Название категории на момент действия
            $table->string('location_name');                  // Название локации на момент действия
            $table->decimal('price_paid', 10, 2);            // Уплаченная цена
            $table->timestamp('action_date');                // Дата действия
            $table->text('notes')->nullable();               // Примечания
            $table->timestamps();
            
            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('subscription_id')->references('id')->on('user_subscriptions')->onDelete('set null');
        });
    }

    public function down()
    {
        // Удаляем таблицу при откате миграции
        Manager::schema()->drop('subscription_history');
    }
} 