<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $table = config('abacpermissions.tables.assigned_permissions', 'assigned_permissions');

        Schema::table($table, function (Blueprint $table) {
            // When assignee_type = 'account', grantable = true means the account
            // is allowed to redistribute this permission to its users/roles.
            // For user/role assignments, grantable = true means the holder may
            // further delegate this permission to other users.
            $table->boolean('grantable')->default(false)->after('access');
        });
    }

    public function down()
    {
        $table = config('abacpermissions.tables.assigned_permissions', 'assigned_permissions');

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn('grantable');
        });
    }
};
