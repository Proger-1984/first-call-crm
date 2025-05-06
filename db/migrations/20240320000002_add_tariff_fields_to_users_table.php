<?php

use Illuminate\Database\Capsule\Manager;

class AddTariffFieldsToUsersTable
{
    public function getTableName(): string
    {
        return 'users';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        Manager::schema()->table('users', function ($table) {
            if (!Manager::schema()->hasColumn('users', 'tariff_id')) {
                $table->integer('tariff_id')->unsigned()->nullable();
            }
            if (!Manager::schema()->hasColumn('users', 'tariff_expires_at')) {
                $table->timestamp('tariff_expires_at')->nullable();
            }
            if (!Manager::schema()->hasColumn('users', 'is_trial_used')) {
                $table->boolean('is_trial_used')->default(false);
            }
            
            if (!Manager::schema()->hasColumn('users', 'tariff_id')) {
                $table->foreign('tariff_id')
                      ->references('id')
                      ->on('tariffs')
                      ->onDelete('set null');
            }
        });
    }

    public function down()
    {
        Manager::schema()->table('users', function ($table) {
            $table->dropForeign(['tariff_id']);
            $table->dropColumn(['tariff_id', 'tariff_expires_at', 'is_trial_used']);
        });
    }
} 