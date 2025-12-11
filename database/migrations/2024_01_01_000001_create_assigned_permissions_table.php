<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tables = config('awesome-abac.tables');

        // Drop old pivot tables
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists($tables['permission_role']);

        // Create unified assigned_permissions table with polymorphic support
        Schema::create($tables['assigned_permissions'], function (Blueprint $table) use ($tables) {
            $table->id();

            // Account scoping (nullable for global assignments)
            $table->foreignId('account_id')
                ->nullable()
                ->constrained($tables['accounts'])
                ->onDelete('cascade');

            // Permission being assigned
            $table->foreignId('permission_id')
                ->constrained($tables['permissions'])
                ->onDelete('cascade');

            // Polymorphic assignee (user, token, role)
            $table->string('assignee_id');
            $table->enum('assignee_type', ['user', 'token', 'role']);

            // Granular access for CRUD permissions (e.g., ['create', 'read'])
            $table->json('access')->nullable();

            $table->timestamps();

            // Indexes for performance
            $table->index(['assignee_id', 'assignee_type']);
            $table->index('permission_id');
            $table->index('account_id');

            // Prevent duplicate assignments
            $table->unique(['permission_id', 'assignee_id', 'assignee_type', 'account_id'], 'unique_permission_assignment');
        });

        // Recreate role_user pivot (this one stays as-is)
        Schema::create('role_user', function (Blueprint $table) use ($tables) {
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('role_id')->constrained($tables['roles'])->onDelete('cascade');
            $table->primary(['user_id', 'role_id']);
        });
    }

    public function down()
    {
        $tables = config('awesome-abac.tables');

        // Drop new table
        Schema::dropIfExists($tables['assigned_permissions']);
        Schema::dropIfExists('role_user');

        // Recreate old pivot tables
        Schema::create($tables['permission_role'], function (Blueprint $table) use ($tables) {
            $table->foreignId('permission_id')->constrained($tables['permissions'])->onDelete('cascade');
            $table->foreignId('role_id')->constrained($tables['roles'])->onDelete('cascade');
            $table->json('access')->nullable();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('permission_user', function (Blueprint $table) use ($tables) {
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained($tables['permissions'])->onDelete('cascade');
            $table->foreignId('account_id')->nullable()->constrained($tables['accounts'])->onDelete('cascade');
            $table->json('access')->nullable();
        });

        Schema::create('role_user', function (Blueprint $table) use ($tables) {
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('role_id')->constrained($tables['roles'])->onDelete('cascade');
            $table->primary(['user_id', 'role_id']);
        });
    }
};
