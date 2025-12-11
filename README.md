# AwesomeAbac (awesome/abac)

A comprehensive SaaS multi-tenancy and ABAC access control package for Laravel. Features row-level tenancy, database-backed roles/permissions, Zeus (System/Tenant) bypass capability, and automatic caching.

## Features

- **Multi-Tenancy**: Shared database, row-level isolation via `Account` model and `TenantScope`.
- **ABAC/RBAC**: Database-backed roles and permissions with CRUD expansion (`type=crud` expands to 4 permissions).
- **Zeus Capability**:
    - **System Level**: Bypass all permissions globally.
    - **Tenant Level**: Bypass all permissions within a specific tenant.
- **Caching**: Automatic permission caching with invalidation on updates.
- **Activity Logging**: Built-in service to log security events.
- **Developer Friendly**: Facades, Traits, and Middleware included.

## Installation

```bash
composer require awesome/abac
```

Run migrations:

```bash
php artisan migrate
```

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=awesome-abac-config
```

Config allows toggling tenancy (`tenancy_enabled`) and customizing table names.

## Usage

### 1. Setup Models

Add `HasAbac` trait to your User model:
```php
class User extends Authenticatable {
    use \Awesome\Abac\Traits\HasAbac;
}
```

Add `UsesTenant` trait to tenant-aware models:
```php
class Post extends Model {
    use \Awesome\Abac\Tenancy\UsesTenant;
}
```

### 2. Permissions & Roles

Create permissions (supports expansion):
```php
Permission::create(['name' => 'posts', 'type' => 'crud']);
// Generates: posts:create, posts:read, posts:update, posts:delete logic
```

**On-Off Permissions:**
Simple binary permissions that can be toggled on or off:
```php
Permission::create(['name' => 'view.dashboard', 'type' => 'on-off']);
// Can be assigned with access: ['on'] (granted) or ['off'] (denied)
```

Assign to Roles:
```php
$role->permissions()->attach($perm); // Full Access (default)
```

**Granular Access Control:**

For **CRUD permissions**, restrict actions via the `access` field:
```php
// Using AssignedPermission model
AssignedPermission::create([
    'permission_id' => $perm->id,
    'assignee_id' => $role->id,
    'assignee_type' => 'role',
    'access' => ['read', 'create'], // Only these actions allowed
]);
// User will have 'posts:read' and 'posts:create'
```

For **On-Off permissions**, control grant/deny:
```php
// Grant permission
AssignedPermission::create([
    'permission_id' => $dashboardPerm->id,
    'assignee_id' => $user->id,
    'assignee_type' => 'user',
    'access' => ['on'], // Permission granted
]);

// Deny permission
AssignedPermission::create([
    'permission_id' => $dashboardPerm->id,
    'assignee_id' => $user->id,
    'assignee_type' => 'user',
    'access' => ['off'], // Permission denied
]);
// Default (no access specified) = 'on' (granted)
```

Zeus Roles:
```php
Role::create(['name' => 'Super Admin', 'zeus_level' => 'system']); // Global Bypass
Role::create(['name' => 'Owner', 'zeus_level' => 'tenant', 'account_id' => 1]); // Tenant Bypass
```

### 3. Check Permissions

Via Facade:
```php
if (Abac::hasPermission($user, 'posts:create')) { ... }
```

In Controller:
```php
$this->authorizePermission('posts:create');
```

response JSON automatically includes effective permissions in `_permissions` if middleware is enabled.

### 4. Tenancy

Set context via middleware (`DetectAbacTenant`) looking for `X-Account-Slug` header, or manually:

```php
app(\Awesome\Abac\Tenancy\TenantContext::class)->setAccount($account);
```

All `UsesTenant` models will automatically scoped to this account.

### 5. Activity Logging

```php
app(\Awesome\Abac\Logging\ActivityLogger::class)->log('role.created', $role);
```

### 6. Controller Helper Methods

The `AbacControllerHelper` trait provides convenient methods for managing permissions and roles in your controllers.

#### Using the Helper Trait

```php
use Awesome\Abac\Controllers\AbacControllerHelper;

class PermissionController extends Controller
{
    use AbacControllerHelper;

    // Your controller methods...
}
```

#### Permission CRUD

**Create Permission:**
```php
// Create a simple "on-off" permission
$permission = $this->createPermission([
    'name' => 'view.dashboard',
    'type' => 'on-off',
    'description' => 'View dashboard',
    'account_id' => null, // Global permission
]);

// Create a CRUD permission (auto-expands to 4 actions)
$permission = $this->createPermission([
    'name' => 'posts',
    'type' => 'crud',
    'description' => 'Manage posts',
    'account_id' => 1, // Tenant-specific
]);
// Generates: posts:create, posts:read, posts:update, posts:delete
```

**Update Permission:**
```php
$permission = $this->updatePermission($permissionId, [
    'description' => 'Updated description',
]);
// Automatically recaches permission list and flushes affected users
```

**Delete Permission:**
```php
$this->deletePermission($permissionId);
// Automatically detaches from all roles and recaches
```

**Get Permission:**
```php
$permission = $this->getPermission($permissionId);
// Returns permission with roles relationship loaded
```

#### Role CRUD

**Create Role:**
```php
// Regular role
$role = $this->createRole([
    'name' => 'Editor',
    'description' => 'Can edit content',
    'account_id' => 1,
]);

// System Zeus (bypasses all permissions globally)
$role = $this->createRole([
    'name' => 'Super Admin',
    'zeus_level' => 'system',
]);

// Tenant Zeus (bypasses all permissions in tenant)
$role = $this->createRole([
    'name' => 'Account Owner',
    'zeus_level' => 'tenant',
    'account_id' => 1,
]);
```

**Update Role:**
```php
$role = $this->updateRole($roleId, [
    'name' => 'Senior Editor',
]);
// Automatically flushes cache for all users with this role
```

**Delete Role:**
```php
$this->deleteRole($roleId);
// Automatically detaches from all users and permissions
```

**Get Role:**
```php
$role = $this->getRole($roleId);
// Returns role with permissions relationship loaded
```

#### Attach Permissions to Roles

**Attach Single Permission:**
```php
// Attach "on" permission (full access)
$this->attachPermissionToRole($roleId, $permissionId);

// Attach CRUD permission with specific actions
$this->attachPermissionToRole($roleId, $permissionId, ['read', 'create']);
// User will only have posts:read and posts:create
```

**Attach Multiple Permissions:**
```php
$this->attachPermissionsToRole($roleId, [
    1, // Simple permission ID (full access)
    2, // Another permission ID
    ['id' => 3, 'access' => ['read', 'update']], // CRUD with restrictions
    ['id' => 4, 'access' => ['create', 'delete']],
]);
```

**Detach Permissions:**
```php
// Detach single permission
$this->detachPermissionFromRole($roleId, $permissionId);

// Detach all permissions
$this->detachAllPermissionsFromRole($roleId);
```

#### Attach Permissions to Users

**Direct Permission Assignment:**
```php
// Attach permission globally
$this->attachPermissionToUser($user, $permissionId);

// Attach permission for specific account
$this->attachPermissionToUser($user, $permissionId, $accountId);

// Attach CRUD permission with restrictions
$this->attachPermissionToUser($user, $permissionId, $accountId, ['read', 'update']);
```

**Bulk Permission Assignment:**
```php
$this->attachPermissionsToUser($user, [
    1,
    2,
    ['id' => 3, 'access' => ['read']],
], $accountId);
```

**Detach Permissions:**
```php
// Detach globally
$this->detachPermissionFromUser($user, $permissionId);

// Detach for specific account only
$this->detachPermissionFromUser($user, $permissionId, $accountId);

// Detach all permissions
$this->detachAllPermissionsFromUser($user);

// Detach all permissions for specific account
$this->detachAllPermissionsFromUser($user, $accountId);
```

#### Manage User Roles

**Assign Role:**
```php
$this->assignRole($user, $roleId);
// Automatically flushes user cache
```

**Detach Role:**
```php
$this->detachRole($user, $roleId);
// Automatically flushes user cache
```

**Sync Roles (Replace All):**
```php
$this->syncRoles($user, [1, 2, 3]);
// Replaces all existing roles with these ones
```

#### Permission List Caching

**Get Cached Permissions List:**
```php
// Get global permissions list
$permissions = $this->getCachedPermissionsList();
// Returns: ['users:create', 'users:read', 'posts:create', ...]

// Get permissions for specific account (includes global + account-specific)
$permissions = $this->getCachedPermissionsList($accountId);
```

This is useful for:
- Populating permission dropdowns in admin UI
- Displaying available permissions to users
- Validating permission names

**Automatic Recaching:**
The permission list is automatically recached when you:
- Create a new permission
- Update a permission
- Delete a permission

**Cache Invalidation:**
User permission caches are automatically flushed when:
- Permissions are attached/detached from users
- Permissions are attached/detached from roles
- Roles are assigned/removed from users
- Permissions or roles are updated/deleted

#### Complete Example

```php
use Awesome\Abac\Controllers\AbacControllerHelper;

class AdminController extends Controller
{
    use AbacControllerHelper;

    public function setupUserPermissions(Request $request)
    {
        // 1. Create permissions
        $postsPermission = $this->createPermission([
            'name' => 'posts',
            'type' => 'crud',
            'account_id' => $request->account_id,
        ]);

        // 2. Create role
        $editorRole = $this->createRole([
            'name' => 'Editor',
            'account_id' => $request->account_id,
        ]);

        // 3. Attach permissions to role (read and create only)
        $this->attachPermissionToRole(
            $editorRole->id,
            $postsPermission->id,
            ['read', 'create']
        );

        // 4. Assign role to user
        $user = User::find($request->user_id);
        $this->assignRole($user, $editorRole->id);

        // 5. Add extra direct permission
        $this->attachPermissionToUser(
            $user,
            $postsPermission->id,
            $request->account_id,
            ['update'] // Give this user update as well
        );

        // User now has: posts:read, posts:create (from role)
        //               posts:update (direct permission)

        return response()->json([
            'message' => 'Permissions configured',
            'permissions' => $this->getCachedPermissionsList($request->account_id),
        ]);
    }
}


### 7. Seeding

The package includes a seeder to generate basic permissions, roles, and a demo tenant.

```php
// In database/seeders/DatabaseSeeder.php
public function run()
{
    $this->call(\Awesome\Abac\Seeders\AwesomeAbacSeeder::class);
}
```

This will create:
- `users`, `roles`, `permissions` permissions (CRUD expanded).
- `System Zeus` role (Global).
- `Demo Corporation` account.
- `Tenant Owner` role (Tenant Zeus).
- Users: `zeus@system.com`, `owner@demo.com`.

## Testing

Run `vendor/bin/phpunit` to execute the test suite.

## License

MIT
