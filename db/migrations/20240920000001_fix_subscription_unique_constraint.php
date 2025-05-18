<?php

use Illuminate\Database\Capsule\Manager;

class FixSubscriptionUniqueConstraint
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

    public function up()
    {
        // Проверяем, что таблица существует
        if (Manager::schema()->hasTable('user_subscriptions')) {
            // 1. Удаляем старое ограничение (которое включает status в ключ)
            Manager::connection()->statement("
                ALTER TABLE user_subscriptions 
                DROP CONSTRAINT IF EXISTS unique_active_subscription;
            ");
            
            // 2. Создаем новый условный индекс только для активных и ожидающих подписок
            Manager::connection()->statement("
                CREATE UNIQUE INDEX unique_subscription 
                ON user_subscriptions (user_id, category_id, location_id) 
                WHERE status IN ('active', 'pending');
            ");
        }
    }

    public function down()
    {
        // Проверяем, что таблица существует
        if (Manager::schema()->hasTable('user_subscriptions')) {
            // 1. Удаляем условный индекс
            Manager::connection()->statement("
                DROP INDEX IF EXISTS unique_subscription;
            ");
            
            // 2. Восстанавливаем исходное ограничение
            Manager::connection()->statement("
                ALTER TABLE user_subscriptions 
                ADD CONSTRAINT unique_active_subscription 
                UNIQUE (user_id, category_id, location_id, status);
            ");
        }
    }
}

class AddUniqueSubscriptionIndex
{
    public function up()
    {
        // Эта миграция выполняется независимо от создания таблицы
        Manager::connection()->statement("
            ALTER TABLE user_subscriptions 
            DROP CONSTRAINT IF EXISTS unique_active_subscription;
        ");
        
        Manager::connection()->statement("
            CREATE UNIQUE INDEX unique_subscription 
            ON user_subscriptions (user_id, category_id, location_id) 
            WHERE status IN ('active', 'pending');
        ");
    }

    public function down()
    {
        Manager::connection()->statement("
            DROP INDEX IF EXISTS unique_subscription;
        ");
    }
} 