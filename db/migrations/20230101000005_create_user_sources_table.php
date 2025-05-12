<?php

use Illuminate\Database\Capsule\Manager;

class CreateUserSourcesTable
{
    public function getTableName(): string
    {
        return 'user_sources';
    }

    public function up()
    {
        Manager::schema()->create('user_sources', function ($table) {
            $table->integer('user_id');
            $table->integer('source_id');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            
            $table->primary(['user_id', 'source_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('source_id')->references('id')->on('sources')->onDelete('cascade');
        });
    }

    public function down()
    {
        Manager::schema()->drop('user_sources');
    }
} 