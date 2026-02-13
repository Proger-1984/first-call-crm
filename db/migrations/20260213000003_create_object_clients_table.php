<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы object_clients
 *
 * Связка Объект + Контакт — именно здесь живёт воронка.
 * Drag-n-drop на kanban перемещает эту связку между стадиями.
 */
class CreateObjectClientsTable
{
    public function getTableName(): string
    {
        return 'object_clients';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('object_clients')) {
            Manager::schema()->create('object_clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('property_id')->comment('ID объекта недвижимости');
                $table->unsignedBigInteger('contact_id')->comment('ID контакта');
                $table->unsignedBigInteger('pipeline_stage_id')->comment('Стадия воронки для этой пары');
                $table->text('comment')->nullable()->comment('Комментарий к связке');
                $table->timestamp('next_contact_at')->nullable()->comment('Дата следующего контакта');
                $table->timestamp('last_contact_at')->nullable()->comment('Дата последнего контакта');
                $table->timestamps();

                // Уникальная пара: один контакт не привязывается к одному объекту дважды
                $table->unique(['property_id', 'contact_id'], 'object_clients_property_contact_unique');

                // Индексы для фильтрации
                $table->index('pipeline_stage_id', 'object_clients_stage_index');
                $table->index('contact_id', 'object_clients_contact_index');
                $table->index('next_contact_at', 'object_clients_next_contact_index');

                // Внешние ключи
                $table->foreign('property_id')
                    ->references('id')
                    ->on('properties')
                    ->onDelete('cascade');

                $table->foreign('contact_id')
                    ->references('id')
                    ->on('contacts')
                    ->onDelete('cascade');

                $table->foreign('pipeline_stage_id')
                    ->references('id')
                    ->on('pipeline_stages')
                    ->onDelete('restrict');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('object_clients');
    }
}
