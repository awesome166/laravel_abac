<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tables = config('awesome-abac.tables');

        // 1. Accounts (Tenants)
        Schema::create($tables['accounts'], function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 2. Roles
        Schema::create($tables['roles'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained($tables['accounts'])->onDelete('cascade');
            $table->string('name');
            // Zeus capability: 'none', 'tenant', 'system'
            // system: bypass EVERYTHING.
            // tenant: bypass everything in this tenant.
            $table->string('zeus_level')->default('none');
            $table->text('description')->nullable();
            $table->timestamps();

            // Unique name per account (or global unique if account_id is null)
            $table->unique(['account_id', 'name']);
        });

        // 3. Permissions
        Schema::create($tables['permissions'], function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. "posts" or "settings.view"
            // expansion: 'on-off' (single) or 'crud' (expands to create, read, update, delete)
            $table->string('type')->default('on-off');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 4. Permission Role (Many-to-Many)
        Schema::create($tables['permission_role'], function (Blueprint $table) use ($tables) {
            $table->foreignId('permission_id')->constrained($tables['permissions'])->onDelete('cascade');
            $table->foreignId('role_id')->constrained($tables['roles'])->onDelete('cascade');
            $table->json('access')->nullable(); // Granular access for CRUD (e.g. {"create": true, "read": false})
            $table->primary(['permission_id', 'role_id']);
        });

        // 5. Account User (Membership Pivot)
        Schema::create($tables['account_user'], function (Blueprint $table) use ($tables) {
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('account_id')->constrained($tables['accounts'])->onDelete('cascade');
            // Could add 'is_owner' or similar metadata here if needed
            $table->primary(['user_id', 'account_id']);
        });

        // 6. Role User (Role Assignments)
        // We need a way to assign roles to users.
        // If a role is Tenant-scoped, the assignment is naturally implied for that tenant?
        // Or do we need explicit context?
        // Usually, if Role belongs to Account 1, User-Role link implies scope is Account 1.
        // If Role is Global, User-Role link implies scope is Global.
        Schema::create('role_user', function (Blueprint $table) use ($tables) {
             $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
             $table->foreignId('role_id')->constrained($tables['roles'])->onDelete('cascade');
             $table->primary(['user_id', 'role_id']);
        });

        // 7. Permission User (Direct Assignments)
        Schema::create('permission_user', function (Blueprint $table) use ($tables) {
             $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
             $table->foreignId('permission_id')->constrained($tables['permissions'])->onDelete('cascade');
             // Direct permission assignment might be global or tenant scoped?
             // Prompt doesn't specify deeply, but standard is global or we need 'account_id' here.
             // Let's assume global for direct grants, or we add account_id for context.
             // For simplicity/robustness, let's add nullable account_id to scope direct permission?
             // "A user may belong to many accounts."
             // If I give "edit_posts" to User, is it for Account A or B?
             // It MUST be scoped if tenancy is enabled.
             $table->foreignId('account_id')->nullable()->constrained($tables['accounts'])->onDelete('cascade');
             $table->json('access')->nullable(); // Granular access
        });

        // 8. Activity Logs
        Schema::create($tables['activity_logs'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('tenant_id')->nullable(); // Denormalized account_id
            $table->string('event'); // e.g. "role.created"
            $table->nullableMorphs('causer'); // Who did it
            $table->nullableMorphs('subject'); // What was changed
            $table->json('properties')->nullable(); // Diff/Details
            $table->timestamps();
        });
    }

    public function down()
    {
        $tables = config('awesome-abac.tables');
        Schema::dropIfExists($tables['activity_logs']);
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists($tables['account_user']);
        Schema::dropIfExists($tables['permission_role']);
        Schema::dropIfExists($tables['permissions']);
        Schema::dropIfExists($tables['roles']);
        Schema::dropIfExists($tables['accounts']);
    }
};
