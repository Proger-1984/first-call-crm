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
        // Переименовываем коды тарифов на новую структуру
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
        Manager::table('tariffs')
            ->where('code', 'premium_30')
            ->update([
                'code' => 'premium',
                'name' => 'Премиум',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
} 