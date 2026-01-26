<?php

use Illuminate\Database\Capsule\Manager;

class AddAppConnectedToUsers
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
        // Проверяем, существуют ли уже поля
        $hasAppConnected = $this->checkIfColumnExists('users', 'app_connected');
        $hasAppLastPingAt = $this->checkIfColumnExists('users', 'app_last_ping_at');

        Manager::schema()->table('users', function ($table) use ($hasAppConnected, $hasAppLastPingAt) {
            // Статус подключения к приложению (WebSocket)
            if (!$hasAppConnected) {
                $table->boolean('app_connected')->default(false)->after('phone_status');
            }

            // Время последнего пинга от приложения
            if (!$hasAppLastPingAt) {
                $table->timestamp('app_last_ping_at')->nullable()->after('app_connected');
            }
        });
    }

    public function down()
    {
        Manager::schema()->table('users', function ($table) {
            $table->dropColumn(['app_connected', 'app_last_ping_at']);
        });
    }

    /**
     * Проверяет, существует ли колонка в таблице
     */
    private function checkIfColumnExists(string $table, string $column): bool
    {
        $result = Manager::select("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = ? 
            AND column_name = ?
        ", [$table, $column]);

        return !empty($result);
    }
}
