<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * Создаёт таблицу для хранения кук авторизации пользователей на источниках (CIAN, Avito)
 */
class CreateUserSourceCookiesTable
{
    public function getTableName(): string
    {
        return 'user_source_cookies';
    }

    public function up(): void
    {
        if (Manager::schema()->hasTable('user_source_cookies')) {
            return;
        }

        Manager::schema()->create('user_source_cookies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('source_type', ['cian', 'avito'])->default('cian');
            $table->text('cookies')->nullable(); // Строка с куками
            $table->boolean('is_valid')->default(false); // Валидны ли куки
            $table->jsonb('subscription_info')->nullable(); // Информация о подписке (тариф, лимит, срок)
            $table->timestamp('last_validated_at')->nullable(); // Когда последний раз проверяли
            $table->timestamp('expires_at')->nullable(); // Когда истекает подписка на источнике
            $table->timestamps();

            // Индексы
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            
            // Уникальный индекс: один пользователь - один источник
            $table->unique(['user_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Manager::schema()->dropIfExists('user_source_cookies');
    }
}
