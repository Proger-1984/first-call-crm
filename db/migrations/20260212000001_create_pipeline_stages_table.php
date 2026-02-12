<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы pipeline_stages
 *
 * Стадии воронки продаж (per-user).
 * Каждый пользователь имеет свои стадии, создаваемые по умолчанию при первом обращении.
 */
class CreatePipelineStagesTable
{
    public function getTableName(): string
    {
        return 'pipeline_stages';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('pipeline_stages')) {
            Manager::schema()->create('pipeline_stages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('ID пользователя-владельца');
                $table->string('name', 100)->comment('Название стадии');
                $table->string('color', 7)->default('#808080')->comment('Цвет в HEX');
                $table->smallInteger('sort_order')->default(0)->comment('Порядок сортировки');
                $table->boolean('is_system')->default(false)->comment('Системная стадия (нельзя удалить)');
                $table->boolean('is_final')->default(false)->comment('Финальная стадия (сделка/отказ)');
                $table->timestamps();

                // Уникальный индекс — у одного пользователя не может быть двух стадий с одинаковым именем
                $table->unique(['user_id', 'name'], 'pipeline_stages_user_name_unique');

                // Индекс для сортировки стадий пользователя
                $table->index(['user_id', 'sort_order'], 'pipeline_stages_user_sort_index');

                // Внешний ключ
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('pipeline_stages');
    }
}
