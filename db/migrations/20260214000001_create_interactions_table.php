<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы interactions
 *
 * Таймлайн/хронология взаимодействий по связкам объект+контакт.
 * Фиксирует звонки, встречи, показы, заметки, смены стадий.
 */
class CreateInteractionsTable
{
    public function getTableName(): string
    {
        return 'interactions';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('interactions')) {
            Manager::schema()->create('interactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('object_client_id')->comment('Связка объект+контакт');
                $table->unsignedBigInteger('user_id')->comment('Кто создал запись');
                $table->string('type', 20)->comment('Тип: call, meeting, showing, message, note, stage_change');
                $table->text('description')->nullable()->comment('Описание взаимодействия');
                $table->jsonb('metadata')->nullable()->comment('Доп. данные: old_stage_id, new_stage_id, duration_min и т.д.');
                $table->timestamp('interaction_at')->useCurrent()->comment('Дата/время взаимодействия');
                $table->timestamps();

                // Индексы для таймлайна
                $table->index(['object_client_id', 'interaction_at'], 'interactions_oc_time_index');
                $table->index('user_id', 'interactions_user_index');
                $table->index('type', 'interactions_type_index');

                // Внешние ключи
                $table->foreign('object_client_id')
                    ->references('id')
                    ->on('object_clients')
                    ->onDelete('cascade');

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('interactions');
    }
}
