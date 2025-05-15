<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class UpdateTverCoordinates extends Migration
{
    /**
     * Обновляет координаты для Твери и Тверской области, чтобы исключить пересечение с Москвой
     */
    public function up()
    {
        // Обновляем границы для Твери, чтобы они не пересекались с Москвой
        // Сдвигаем восточную границу западнее
        DB::table('locations')
            ->where('name', 'like', '%Тверь%')
            ->orWhere('name', 'like', '%Тверская область%')
            ->update([
                'bounds' => json_encode([
                    'north' => 58.8740, // Северная граница
                    'south' => 55.6255, // Южная граница
                    'east' => 37.2,     // Восточная граница - сдвигаем западнее
                    'west' => 31.7466   // Западная граница
                ]),
                'center_lat' => 56.8587, // Центр Твери
                'center_lng' => 35.9208  // Центр Твери
            ]);
    }

    /**
     * Откат миграции
     */
    public function down()
    {
        // Возвращаем прежние значения
        DB::table('locations')
            ->where('name', 'like', '%Тверь%')
            ->orWhere('name', 'like', '%Тверская область%')
            ->update([
                'bounds' => json_encode([
                    'north' => 58.8740,
                    'south' => 55.6255,
                    'east' => 38.2774, // Возвращаем старое значение
                    'west' => 31.7466
                ]),
                'center_lat' => 56.8587,
                'center_lng' => 35.9208
            ]);
    }
} 