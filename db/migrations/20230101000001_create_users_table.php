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
            $table->string('password');
            $table->string('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_photo_url')->nullable();
            $table->integer('telegram_auth_date')->nullable();
            $table->string('telegram_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Manager::schema()->drop('users');
    }
} 