<?php

use Illuminate\Database\Capsule\Manager;

/**
 * Добавляет поля для отслеживания отправленных уведомлений о подписках
 * 
 * Это позволяет избежать повторной отправки уведомлений при частом запуске скриптов.
 */
class AddNotificationTrackingToSubscriptions
{
    public function getTableName(): string
    {
        return 'user_subscriptions';
    }

    /**
     * Указывает, что эта миграция модифицирует существующую таблицу
     * и должна выполняться, даже если таблица уже существует
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up(): void
    {
        // Проверяем, существуют ли уже колонки
        $has3d = $this->checkIfColumnExists('user_subscriptions', 'notified_expiring_3d_at');
        $has1d = $this->checkIfColumnExists('user_subscriptions', 'notified_expiring_1d_at');
        $has1h = $this->checkIfColumnExists('user_subscriptions', 'notified_expiring_1h_at');
        $has15m = $this->checkIfColumnExists('user_subscriptions', 'notified_expiring_15m_at');
        $hasExpired = $this->checkIfColumnExists('user_subscriptions', 'notified_expired_at');
        
        Manager::schema()->table('user_subscriptions', function ($table) use ($has3d, $has1d, $has1h, $has15m, $hasExpired) {
            // Уведомление за 3 дня до окончания (для премиум)
            if (!$has3d) {
                $table->timestamp('notified_expiring_3d_at')->nullable()->after('admin_notes');
            }
            // Уведомление за 1 день до окончания (для премиум)
            if (!$has1d) {
                $table->timestamp('notified_expiring_1d_at')->nullable()->after('admin_notes');
            }
            // Уведомление за 1 час до окончания (для демо)
            if (!$has1h) {
                $table->timestamp('notified_expiring_1h_at')->nullable()->after('admin_notes');
            }
            // Уведомление за 15 минут до окончания (для демо)
            if (!$has15m) {
                $table->timestamp('notified_expiring_15m_at')->nullable()->after('admin_notes');
            }
            // Уведомление об истечении подписки
            if (!$hasExpired) {
                $table->timestamp('notified_expired_at')->nullable()->after('admin_notes');
            }
        });
    }

    public function down(): void
    {
        Manager::schema()->table('user_subscriptions', function ($table) {
            $table->dropColumn([
                'notified_expiring_3d_at',
                'notified_expiring_1d_at',
                'notified_expiring_1h_at',
                'notified_expiring_15m_at',
                'notified_expired_at',
            ]);
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
