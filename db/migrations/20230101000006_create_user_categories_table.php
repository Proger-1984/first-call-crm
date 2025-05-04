<?php

use Illuminate\Database\Capsule\Manager;

class CreateUserCategoriesTable
{
    public function getTableName(): string
    {
        return 'user_categories';
    }

    public function up()
    {
        Manager::schema()->create('user_categories', function ($table) {
            $table->integer('user_id');
            $table->integer('category_id');
            $table->timestamps();
            
            $table->primary(['user_id', 'category_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    public function down()
    {
        Manager::schema()->drop('user_categories');
    }
} 