<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Создаёт таблицу для одноразовых токенов bookmarklet
 * Токены используются для безопасной передачи кук с внешних сайтов
 */
class CreateBookmarkletTokensTable
{
    public function getTableName(): string
    {
        return 'bookmarklet_tokens';
    }

    public function up(): void
    {
        if (Manager::schema()->hasTable('bookmarklet_tokens')) {
            return;
        }

        Manager::schema()->create('bookmarklet_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 64)->unique(); // Уникальный токен
            $table->enum('source_type', ['cian', 'avito'])->default('cian');
            $table->boolean('is_used')->default(false); // Использован ли
            $table->timestamp('expires_at'); // Срок действия (короткий, ~10 минут)
            $table->timestamp('used_at')->nullable(); // Когда использован
            $table->timestamps();

            // Индексы
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            
            $table->index(['token', 'is_used', 'expires_at']);
        });
    }

    public function down(): void
    {
        Manager::schema()->dropIfExists('bookmarklet_tokens');
    }
}
