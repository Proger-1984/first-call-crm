<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы clients
 *
 * Карточка клиента — основная сущность CRM модуля.
 * Привязка к пользователю (агенту) и стадии воронки.
 */
class CreateClientsTable
{
    public function getTableName(): string
    {
        return 'clients';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('clients')) {
            Manager::schema()->create('clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('ID агента-владельца');
                $table->unsignedBigInteger('pipeline_stage_id')->comment('Текущая стадия воронки');
                $table->string('name', 255)->comment('ФИО клиента');
                $table->string('phone', 20)->nullable()->comment('Основной телефон');
                $table->string('phone_secondary', 20)->nullable()->comment('Дополнительный телефон');
                $table->string('email', 255)->nullable()->comment('Email');
                $table->string('telegram_username', 100)->nullable()->comment('Telegram username');
                $table->string('client_type', 20)->default('buyer')->comment('Тип: buyer/seller/renter/landlord');
                $table->string('source_type', 50)->nullable()->comment('Откуда пришёл: avito/cian/звонок/рекомендация');
                $table->string('source_details', 255)->nullable()->comment('Детали источника');
                $table->decimal('budget_min', 15, 2)->nullable()->comment('Минимальный бюджет');
                $table->decimal('budget_max', 15, 2)->nullable()->comment('Максимальный бюджет');
                $table->text('comment')->nullable()->comment('Комментарий агента');
                $table->boolean('is_archived')->default(false)->comment('В архиве');
                $table->timestamp('last_contact_at')->nullable()->comment('Дата последнего контакта');
                $table->timestamp('next_contact_at')->nullable()->comment('Дата следующего контакта');
                $table->timestamps();

                // Индексы для фильтрации
                $table->index(['user_id', 'is_archived'], 'clients_user_archived_index');
                $table->index(['user_id', 'pipeline_stage_id'], 'clients_user_stage_index');
                $table->index(['user_id', 'client_type'], 'clients_user_type_index');
                $table->index('phone', 'clients_phone_index');

                // Внешние ключи
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
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
        Manager::schema()->dropIfExists('clients');
    }
}
