<?php

use Illuminate\Database\Capsule\Manager;

class AddTelegramBotBlockedToUsers
{
    public function getTableName(): string
    {
        return 'users';
    }

    /**
     * Указывает, что эта миграция модифицирует существующую таблицу
     * и должна выполняться, даже если таблица уже существует
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        Manager::schema()->table('users', function ($table) {
            $table->boolean('telegram_bot_blocked')->default(false)->after('telegram_id');
        });
    }

    public function down()
    {
        Manager::schema()->table('users', function ($table) {
            $table->dropColumn('telegram_bot_blocked');
        });
    }
} 