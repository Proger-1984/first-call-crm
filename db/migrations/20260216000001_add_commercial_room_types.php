<?php

use Illuminate\Database\Capsule\Manager;

/**
 * Добавляет типы коммерческой недвижимости в таблицу rooms
 * и связывает их с категориями "Аренда коммерческая" (id=2) и "Продажа коммерческая" (id=4)
 */
class AddCommercialRoomTypes
{
    public function getTableName(): string
    {
        return 'rooms';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up(): void
    {
        // Типы коммерческой недвижимости (код совпадает с Яндекс API commercialType)
        $commercialTypes = [
            ['name' => 'Офис', 'code' => 'office', 'sort_order' => 10],
            ['name' => 'Торговое помещение', 'code' => 'retail', 'sort_order' => 11],
            ['name' => 'Свободного назначения', 'code' => 'free_purpose', 'sort_order' => 12],
            ['name' => 'Склад', 'code' => 'warehouse', 'sort_order' => 13],
            ['name' => 'Производство', 'code' => 'manufacturing', 'sort_order' => 14],
            ['name' => 'Общепит', 'code' => 'public_catering', 'sort_order' => 15],
            ['name' => 'Автосервис', 'code' => 'auto_repair', 'sort_order' => 16],
            ['name' => 'Гостиница', 'code' => 'hotel', 'sort_order' => 17],
            ['name' => 'Готовый бизнес', 'code' => 'business', 'sort_order' => 18],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($commercialTypes as &$type) {
            $type['created_at'] = $now;
            $type['updated_at'] = $now;
        }

        Manager::table('rooms')->insert($commercialTypes);

        // Получаем ID вставленных записей по кодам
        $commercialCodes = array_column($commercialTypes, 'code');
        $commercialRoomIds = Manager::table('rooms')
            ->whereIn('code', $commercialCodes)
            ->pluck('id')
            ->toArray();

        // Связываем с коммерческими категориями (id=2: Аренда, id=4: Продажа)
        $categoryRooms = [];
        foreach ([2, 4] as $categoryId) {
            foreach ($commercialRoomIds as $roomId) {
                $categoryRooms[] = [
                    'category_id' => $categoryId,
                    'room_id' => $roomId,
                ];
            }
        }

        Manager::table('category_rooms')->insert($categoryRooms);
    }

    public function down(): void
    {
        $commercialCodes = [
            'office', 'retail', 'free_purpose', 'warehouse',
            'manufacturing', 'public_catering', 'auto_repair', 'hotel', 'business',
        ];

        // Удаление из category_rooms произойдёт автоматически (ON DELETE CASCADE)
        Manager::table('rooms')->whereIn('code', $commercialCodes)->delete();
    }
}
