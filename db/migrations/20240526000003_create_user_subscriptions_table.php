<?php

use Illuminate\Database\Capsule\Manager;

class CreateUserSubscriptionsTable
{
    public function getTableName(): string
    {
        return 'user_subscriptions';
    }

    public function up()
    {
        // Создаем таблицу для хранения подписок пользователей
        Manager::schema()->create('user_subscriptions', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();          // ID пользователя
            $table->integer('tariff_id')->unsigned();        // ID тарифа
            $table->integer('category_id')->unsigned();      // ID категории (Аренда.Жилая, Продажа.Жилая и т.д.)
            $table->integer('location_id')->unsigned();      // ID локации (города/региона)
            $table->decimal('price_paid', 10, 2);           // Фактически уплаченная цена 
            $table->timestamp('start_date')->nullable();    // Дата начала подписки (null для pending)
            $table->timestamp('end_date')->nullable();      // Дата окончания подписки (null для pending)
            $table->string('status')->default('pending');   // Статус: pending, active, expired, cancelled
            $table->boolean('is_enabled')->default(true);   // Возможность временно отключить подписку
            $table->string('payment_method')->nullable();   // Метод оплаты (наличные, перевод, и т.д.)
            $table->string('admin_notes')->nullable();      // Заметки администратора
            $table->integer('approved_by')->unsigned()->nullable(); // ID администратора, утвердившего подписку
            $table->timestamp('approved_at')->nullable();   // Когда подписка была утверждена
            $table->timestamps();
            
            // Внешние ключи
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tariff_id')->references('id')->on('tariffs')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            
            // Уникальный индекс: пользователь не может иметь две активные подписки 
            // для одной и той же категории и локации
            $table->unique(['user_id', 'category_id', 'location_id', 'status'], 'unique_active_subscription');
        });
    }

    public function down()
    {
        // Удаляем таблицу при откате миграции
        Manager::schema()->drop('user_subscriptions');
    }
} 