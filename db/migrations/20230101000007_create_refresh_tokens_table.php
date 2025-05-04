<?php

use Illuminate\Database\Capsule\Manager;

class CreateRefreshTokensTable
{
    public function getTableName(): string
    {
        return 'refresh_tokens';
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('refresh_tokens')) {
            Manager::schema()->create('refresh_tokens', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned();
                $table->string('token', 255);
                $table->string('device_type', 20); // 'web' или 'mobile'
                $table->timestamp('expires_at');
                $table->timestamps();
                
                $table->unique(['user_id', 'device_type']);
                $table->foreign('user_id')
                      ->references('id')
                      ->on('users')
                      ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('refresh_tokens');
    }
} 