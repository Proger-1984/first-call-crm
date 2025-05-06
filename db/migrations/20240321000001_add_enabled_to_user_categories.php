<?php

use Illuminate\Database\Capsule\Manager;

class AddEnabledToUserCategories
{
    public function getTableName(): string
    {
        return 'user_categories';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        Manager::schema()->table('user_categories', function ($table) {
            if (!Manager::schema()->hasColumn('user_categories', 'enabled')) {
                $table->boolean('enabled')->default(true)->after('category_id');
            }
        });
    }

    public function down()
    {
        Manager::schema()->table('user_categories', function ($table) {
            $table->dropColumn('enabled');
        });
    }
} 