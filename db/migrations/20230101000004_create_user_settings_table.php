<?php

use Illuminate\Database\Capsule\Manager;

class CreateUserSettingsTable
{
    public function getTableName(): string
    {
        return 'user_settings';
    }

    public function up()
    {
        Manager::schema()->create('user_settings', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->boolean('log_events')->default(false);
            $table->boolean('auto_call')->default(false);
            $table->boolean('auto_call_raised')->default(false);
            $table->boolean('telegram_notifications')->default(false);
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Manager::schema()->drop('user_settings');
    }
} 