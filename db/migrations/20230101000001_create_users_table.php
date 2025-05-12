<?php

use Illuminate\Database\Capsule\Manager;

class CreateUsersTable
{
    public function getTableName(): string
    {
        return 'users';
    }

    public function up()
    {
        Manager::schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('password_hash');
            $table->string('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_photo_url')->nullable();
            $table->integer('telegram_auth_date')->nullable();
            $table->string('telegram_hash')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('phone_status')->default(false);
            $table->string('role')->default('user');
            $table->boolean('is_trial_used')->default(false);
            $table->timestamps();
        });
        
        // Создаем администратора
        Manager::table('users')->insert([
            'name' => 'Администратор',
            'telegram_id' => 'admin',
            'password_hash' => password_hash('admin', PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        Manager::schema()->drop('users');
    }
} 