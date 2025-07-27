<?php

use Illuminate\Database\Capsule\Manager;
use Carbon\Carbon;

class CreateCianAuthTable
{
    public function getTableName(): string
    {
        return 'cian_auth';
    }

    public function up()
    {
        Manager::schema()->create('cian_auth', function ($table) {
            // Первичный ключ
            $table->increments('id');
            
            // Логин пользователя
            $table->string('login', 255);
            
            // Пароль (может быть null для токен-аутентификации)
            $table->string('password', 255)->nullable();
            
            // Авторизационная кука/токен (JWT может быть довольно длинным)
            $table->text('auth_token');
            
            // Активность учетной записи
            $table->boolean('is_active')->default(true);
            
            // Дата последнего использования
            $table->timestamp('last_used_at')->nullable();
            
            // Комментарий к учетной записи
            $table->string('comment', 500)->nullable();

            // Внешний ключ к локации (1, 2, 3 и т.д.)
            $table->unsignedInteger('location_id');
            $table->foreign('location_id')->references('id')->on('locations');

            // Внешний ключ к категории (1, 2)
            $table->unsignedInteger('category_id');
            $table->foreign('category_id')->references('id')->on('categories');

            // Уникальный индекс
            $table->unique(['location_id', 'category_id']);
            
            // Индекс для поиска активных записей
            $table->index(['is_active']);
            
            // Метки времени создания и обновления записи
            $table->timestamps();
        });

        // Вставляем тестовую запись с предоставленным токеном
        $currentTime = Carbon::now()->toDateTimeString();

        $locations = Manager::table('locations')->get();

        foreach ($locations as $location) {
            Manager::table('cian_auth')->insert([
                'login' => 'test_user@example.com',
                'password' => null, // Для токен-аутентификации пароль не нужен
                'auth_token' => 'simple eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc1JlZ2lzdGVyZWQiOnRydWUsImlkIjoxMTE0OTAwNjksImFkZGl0aW9uYWxJbmZvIjoie1wiY2FwdGNoYUFtbmVzdHlFbmREYXRlXCI6MTc0OTEzNDIyNCxcImRldmljZUlwXCI6XCIxNDcuNDUuOTUuMTc5XCIsXCJkZXZpY2VJZFwiOlwiODA2YWQ1NzItMzE3OS00YzI2LTlhMTItYzFjMzQyZWY1ZWE5XCJ9IiwiZXhwIjoxNzQ5NTY1MzI0fQ.BWisoPC9giWvUEpJs190lf0vjcuLwz6Q06aMRH15Bdo',
                'is_active' => true,
                'location_id' => $location->id,
                'category_id' => 1, // Аренда
                'comment' => 'Автоматически добавленная тестовая запись',
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ]);

            Manager::table('cian_auth')->insert([
                'login' => 'test_user@example.com',
                'password' => null, // Для токен-аутентификации пароль не нужен
                'auth_token' => 'simple eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc1JlZ2lzdGVyZWQiOnRydWUsImlkIjoxMTE0OTAwNjksImFkZGl0aW9uYWxJbmZvIjoie1wiY2FwdGNoYUFtbmVzdHlFbmREYXRlXCI6MTc0OTEzNDIyNCxcImRldmljZUlwXCI6XCIxNDcuNDUuOTUuMTc5XCIsXCJkZXZpY2VJZFwiOlwiODA2YWQ1NzItMzE3OS00YzI2LTlhMTItYzFjMzQyZWY1ZWE5XCJ9IiwiZXhwIjoxNzQ5NTY1MzI0fQ.BWisoPC9giWvUEpJs190lf0vjcuLwz6Q06aMRH15Bdo',
                'is_active' => true,
                'location_id' => $location->id,
                'category_id' => 2, // Продажа
                'comment' => 'Автоматически добавленная тестовая запись',
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ]);
        }
    }

    public function down()
    {
        Manager::schema()->drop('cian_auth');
    }
} 