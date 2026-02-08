<?php

use Illuminate\Database\Capsule\Manager;

/**
 * Добавление полей метро и доступности телефона в таблицу listings
 */
class AddMetroFieldsToListings
{
    public function getTableName(): string
    {
        return 'listings__metro_distance'; // Уникальное имя для проверки колонки
    }

    public function up(): void
    {
        // Проверяем, существует ли колонка metro_distance
        if (Manager::schema()->hasColumn('listings', 'metro_distance')) {
            return;
        }

        Manager::schema()->table('listings', function ($table) {
            // Расстояние до метро (например: "900 м" или "2,7 км")
            $table->string('metro_distance', 50)->nullable();
            
            // Время пешком до метро (например: "11–15 мин.")
            $table->string('metro_walk_time', 50)->nullable();
            
            // Телефон недоступен (1 = только звонки через приложение, 0 = телефон доступен)
            $table->boolean('phone_unavailable')->default(false);
        });
    }

    public function down(): void
    {
        if (!Manager::schema()->hasColumn('listings', 'metro_distance')) {
            return;
        }

        Manager::schema()->table('listings', function ($table) {
            $table->dropColumn(['metro_distance', 'metro_walk_time', 'phone_unavailable']);
        });
    }
}
