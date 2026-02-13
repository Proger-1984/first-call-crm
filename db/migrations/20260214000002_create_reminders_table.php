<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

// Таблица напоминаний CRM
// Агент создаёт напоминание "Перезвонить Иванову через 2 дня", cron проверяет каждые 5 минут
// и отправляет Telegram-уведомление когда время пришло

if (!Capsule::schema()->hasTable('reminders')) {
    Capsule::schema()->create('reminders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('object_client_id')->comment('Связка объект+контакт');
        $table->unsignedBigInteger('user_id')->comment('Кто создал напоминание');
        $table->timestamp('remind_at')->comment('Когда напомнить');
        $table->text('message')->comment('Текст напоминания');
        $table->boolean('is_sent')->default(false)->comment('Отправлено ли уведомление');
        $table->timestamp('sent_at')->nullable()->comment('Время отправки (атомарная блокировка)');
        $table->timestamps();

        // Внешние ключи
        $table->foreign('object_client_id')
            ->references('id')->on('object_clients')
            ->onDelete('cascade');
        $table->foreign('user_id')
            ->references('id')->on('users')
            ->onDelete('cascade');

        // Индексы
        $table->index(['is_sent', 'remind_at'], 'idx_reminders_pending');
        $table->index('object_client_id', 'idx_reminders_object_client');
        $table->index(['user_id', 'is_sent'], 'idx_reminders_user');
    });

    echo "✅ Таблица reminders создана\n";
} else {
    echo "⏭️ Таблица reminders уже существует\n";
}
