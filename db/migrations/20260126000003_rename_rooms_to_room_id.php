<?php

use Illuminate\Database\Capsule\Manager;

class RenameRoomsToRoomId
{
    public function getTableName(): string
    {
        return 'listings';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Переименовываем колонку rooms в room_id
        if (Manager::schema()->hasColumn('listings', 'rooms')) {
            Manager::statement('ALTER TABLE listings RENAME COLUMN rooms TO room_id');
        }
        
        // Меняем тип на integer для FK
        if (Manager::schema()->hasColumn('listings', 'room_id')) {
            Manager::statement('ALTER TABLE listings ALTER COLUMN room_id TYPE integer USING room_id::integer');
            
            // Добавляем FK на таблицу rooms
            Manager::statement('ALTER TABLE listings ADD CONSTRAINT listings_room_id_foreign FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL');
            
            // Добавляем индекс
            Manager::statement('CREATE INDEX IF NOT EXISTS listings_room_id_index ON listings(room_id)');
        }
    }

    public function down()
    {
        // Удаляем FK и индекс
        Manager::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS listings_room_id_foreign');
        Manager::statement('DROP INDEX IF EXISTS listings_room_id_index');
        
        // Меняем тип обратно на smallint
        if (Manager::schema()->hasColumn('listings', 'room_id')) {
            Manager::statement('ALTER TABLE listings ALTER COLUMN room_id TYPE smallint USING room_id::smallint');
            Manager::statement('ALTER TABLE listings RENAME COLUMN room_id TO rooms');
        }
    }
}
