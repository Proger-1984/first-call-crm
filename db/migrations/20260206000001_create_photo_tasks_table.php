<?php

use Illuminate\Database\Capsule\Manager;

/**
 * Таблица задач на обработку фото (удаление водяных знаков)
 * 
 * Одна задача на объявление. При ошибке — сбрасывается на pending.
 */
class CreatePhotoTasksTable
{
    public function getTableName(): string
    {
        return 'photo_tasks';
    }

    public function up(): void
    {
        Manager::schema()->create('photo_tasks', function ($table) {
            $table->id();
            $table->unsignedBigInteger('listing_id')->unique(); // Одна задача на объявление
            $table->unsignedTinyInteger('source_id');
            $table->string('external_id', 50); // ID объявления на источнике
            $table->string('url', 500); // URL объявления
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('error_message', 500)->nullable();
            $table->unsignedSmallInteger('photos_count')->default(0);
            $table->string('archive_path', 255)->nullable();
            $table->timestamps();

            // Индекс для быстрого поиска задач на обработку
            $table->index(['status', 'created_at']);
            
            // Внешний ключ
            $table->foreign('listing_id')->references('id')->on('listings')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Manager::schema()->dropIfExists('photo_tasks');
    }
}
