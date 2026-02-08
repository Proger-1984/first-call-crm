<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Миграция для удаления таблицы bookmarklet_tokens
 * 
 * Функционал bookmarklet был удалён, так как не работает с HttpOnly куками.
 * Теперь используется только ручной ввод кук.
 */
class DropBookmarkletTokensTable
{
    public function getTableName(): string
    {
        return 'bookmarklet_tokens';
    }

    /**
     * Указывает что миграция модифицирует существующую таблицу (удаляет её)
     */
    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up(): void
    {
        Manager::schema()->dropIfExists('bookmarklet_tokens');
    }

    public function down(): void
    {
        // Восстанавливаем таблицу при откате
        if (!Manager::schema()->hasTable('bookmarklet_tokens')) {
            Manager::schema()->create('bookmarklet_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token', 64)->unique();
                $table->enum('source_type', ['cian', 'avito'])->default('cian');
                $table->boolean('is_used')->default(false);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
                
                $table->index(['token', 'is_used', 'expires_at']);
            });
        }
    }
}
