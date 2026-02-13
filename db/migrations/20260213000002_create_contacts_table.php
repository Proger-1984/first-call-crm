<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция: Создание таблицы contacts
 *
 * Контакт — покупатель/арендатор, привязывается к объектам через object_clients.
 */
class CreateContactsTable
{
    public function getTableName(): string
    {
        return 'contacts';
    }

    public function modifiesExistingTable(): bool
    {
        return false;
    }

    public function up()
    {
        if (!Manager::schema()->hasTable('contacts')) {
            Manager::schema()->create('contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('ID агента-владельца');
                $table->string('name', 255)->comment('ФИО контакта');
                $table->string('phone', 20)->nullable()->comment('Основной телефон');
                $table->string('phone_secondary', 20)->nullable()->comment('Дополнительный телефон');
                $table->string('email', 255)->nullable()->comment('Email');
                $table->string('telegram_username', 100)->nullable()->comment('Telegram username');
                $table->text('comment')->nullable()->comment('Комментарий');
                $table->boolean('is_archived')->default(false)->comment('В архиве');
                $table->timestamps();

                // Индексы для фильтрации
                $table->index(['user_id', 'is_archived'], 'contacts_user_archived_index');
                $table->index('phone', 'contacts_phone_index');

                // Внешние ключи
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Manager::schema()->dropIfExists('contacts');
    }
}
