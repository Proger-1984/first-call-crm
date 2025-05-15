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
        Manager::table('users')->upsert([
            'name' => 'Sergey Sokolov',
            'telegram_id' => '726266737',
            'telegram_username' => 'sokolovserge',
            'telegram_photo_url' => null,
            'telegram_auth_date' => '1747294567',
            'telegram_hash' => '41560c73676d1e33b7ac88afe774a701f67c1d500d5c1f4de0adc35d38b0d40b',
            'phone' => null,
            'phone_status' => false,
            'password_hash' => password_hash('admin', PASSWORD_DEFAULT),
            'role' => 'admin',
            'is_trial_used' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['telegram_id'], ['updated_at']);
    }

    public function down()
    {
        Manager::schema()->drop('users');
    }
} 