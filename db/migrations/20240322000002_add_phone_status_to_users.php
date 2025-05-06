<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

class AddPhoneStatusToUsers
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
        if (!Capsule::schema()->hasColumn($this->getTableName(), 'phone_status')) {
            Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
                $table->boolean('phone_status')->default(false)->after('phone');
            });
        }
    }

    public function down()
    {
        if (Capsule::schema()->hasColumn($this->getTableName(), 'phone_status')) {
            Capsule::schema()->table($this->getTableName(), function (Blueprint $table) {
                $table->dropColumn('phone_status');
            });
        }
    }
} 