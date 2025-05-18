<?php

use Illuminate\Database\Capsule\Manager;

class UpdateTariffsStructure
{
    public function getTableName(): string
    {
        return 'tariffs';
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
        // 1. Удаляем тариф на 3 дня
        Manager::table('tariffs')
            ->where('code', 'day3')
            ->delete();
            
        // 2. Переименовываем коды тарифов на новую структуру
        Manager::table('tariffs')
            ->where('code', 'day1')
            ->update([
                'code' => 'premium_1',
                'name' => 'Премиум 1 день',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
        Manager::table('tariffs')
            ->where('code', 'day7')
            ->update([
                'code' => 'premium_7',
                'name' => 'Премиум 7 дней',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
        Manager::table('tariffs')
            ->where('code', 'premium')
            ->update([
                'code' => 'premium_31',
                'name' => 'Премиум 31 день',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }

    public function down()
    {
        // В случае отката создаем удаленный тариф на 3 дня
        Manager::table('tariffs')->insert([
            'name' => '3 дня',
            'code' => 'day3',
            'duration_hours' => 72,
            'price' => 3000.00,
            'description' => 'Полный доступ ко всем функциям на 3 дня',
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
            
        // Восстановление предыдущих кодов и названий
        Manager::table('tariffs')
            ->where('code', 'premium_1')
            ->update([
                'code' => 'day1',
                'name' => '1 день',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
        Manager::table('tariffs')
            ->where('code', 'premium_7')
            ->update([
                'code' => 'day7',
                'name' => '7 дней',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
        Manager::table('tariffs')
            ->where('code', 'premium_30')
            ->update([
                'code' => 'premium',
                'name' => 'Премиум',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
} 