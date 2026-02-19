# AbacPermissions (abacpermissions/abacpermissions)

A comprehensive SaaS multi-tenancy and ABAC access control package for Laravel. Features row-level tenancy, database-backed roles/permissions, Zeus (System/Tenant) bypass capability, and automatic caching.

## Features

- **Multi-Tenancy**: Shared database, row-level isolation via `Account` model and `TenantScope`.
- **ABAC/RBAC**: Database-backed roles and permissions with CRUD expansion (`type=crud` expands to 4 permissions).
- **Zeus Capability**:
    - **System Level**: Bypass all permissions globally.
    - **Tenant Level**: Bypass all permissions within a specific tenant.
- **Permission Delegation**: Two-tier cap system — what a tenant account is allowed to distribute, and what each user may further delegate to others.
- **Caching**: Automatic permission caching with invalidation on updates.
- **Activity Logging**: Built-in service to log security events.
- **Developer Friendly**: Facades, Traits, and Middleware included.

## Installation

```bash
composer require abacpermissions/abacpermissions
```

Run migrations:

```bash
php artisan migrate
```

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=abacpermissions-config
```

Config allows toggling tenancy (`tenancy_enabled`) and customizing table names.

## Usage

### Middleware: DetectAbacTenant

The `DetectAbacTenant` middleware automatically detects the current tenant for each request when multi-tenancy is enabled.

**How it works:**
- Checks if tenancy is enabled via `config('abacpermissions.tenancy_enabled')`.
- Looks for the `X-Account-ID` (or `X-Account-Slug`) header in the request.
- If present, finds the corresponding `Account` and sets it in the `TenantContext`.
- **System Zeus Bypass**: If no account header is provided and the user is System Zeus, they are allowed to proceed without an account context and will see ALL data from ALL tenants.
- **Regular Users**: Without an account header, regular users are restricted to global resources only (`account_id IS NULL`).

**Code Overview:**
The middleware now checks if the authenticated user is System Zeus and allows them to bypass the tenant requirement:

```php
// If no account context provided
if (!$account) {
    // System Zeus can proceed without account context
    if ($user && method_exists($user, 'isSystemZeus') && $user->isSystemZeus()) {
        return $next($request); // Access all data
    }
    // Regular users restricted to global resources
}
```

**Usage:**

Register the middleware in your `app/Http/Kernel.php`:
```php
protected $middlewareGroups = [
    'web' => [
        // ...existing middleware...
        \AbacPermissions\Http\Middleware\DetectAbacTenant::class,
    ],
];
```

Send the `X-Account-ID` (preferred) or `X-Account-Slug` header with each request to identify the tenant:
```
X-Account-ID: 1
```

### 1. Setup Models

Add `HasAbac` trait to your User model:
```php
class User extends Authenticatable {
    use \AbacPermissions\Traits\HasAbac;
}
```

Add `UsesTenant` trait to tenant-aware models:
```php
class Post extends Model {
    use \AbacPermissions\Tenancy\UsesTenant;
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
])
// Default (no access specified) = 'on' (granted)
```

Zeus Roles:
```php
Role::create(['name' => 'Super Admin', 'zeus_level' => 'system']); // Global Bypass
Role::create(['name' => 'Owner', 'zeus_level' => 'tenant', 'account_id' => 1]); // Tenant Bypass
```

#### Understanding Zeus Roles

Zeus roles provide powerful override capabilities for privileged users. There are two levels:

**System Zeus** (`zeus_level='system'`)
- Bypasses **ALL** permission checks globally
- Bypasses **ALL** tenant restrictions across the entire system
- Can access data from **ALL** tenants without setting an account context
- Does **NOT** require `X-Account-ID` header (optional for scoping)
- Intended for platform administrators and system maintenance

**Tenant Zeus** (`zeus_level='tenant'`)
- Bypasses **ALL** permission checks within their assigned tenant
- Must have an `account_id` assigned to the role
- Can only access data within their designated tenant
- **STILL** requires `X-Account-ID` header to set tenant context
- Intended for account owners or tenant administrators

**How Zeus Affects Query Scoping:**

When you query a tenant-aware model (using `UsesTenant` trait):

| User Type | Account Context | Data Returned |
|-----------|----------------|---------------|
| System Zeus | Not set | **ALL** data from **ALL** tenants |
| System Zeus | Set (via header) | Data scoped to that tenant only |
| Tenant Zeus | Not set | Only global data (`account_id IS NULL`) |
| Tenant Zeus | Set (their tenant) | **ALL** data within their tenant |
| Regular User | Not set | Only global data (`account_id IS NULL`) |
| Regular User | Set | Data scoped to that tenant only |

**Zeus Helper Methods:**

The `HasAbac` trait provides convenient methods to check Zeus status:

```php
// Check if user is System Zeus
if ($user->isSystemZeus()) {
    // User has global override
}

// Check if user is Tenant Zeus for current or specific account
if ($user->isTenantZeus()) {
    // Uses current tenant context
}

if ($user->isTenantZeus($accountId)) {
    // Check for specific account
}

// Check if user has any Zeus level
if ($user->isZeus()) {
    // System or Tenant Zeus
}
```

**Performance Note:**
Zeus status checks are cached at the request level to avoid N+1 query issues. The first check performs a database query, subsequent calls return the cached result.

**Best Practices:**

1. **Limit System Zeus Assignments**: System Zeus is extremely powerful. Only assign to trusted platform administrators.

2. **Use Tenant Zeus for Account Owners**: Most SaaS applications should use Tenant Zeus for account owners rather than System Zeus.

3. **Audit Zeus Actions**: Consider logging when Zeus users perform sensitive operations.

4. **Zeus Cannot Be Overridden**: Once a user has a Zeus role, they bypass all permission checks. Removing permissions from their role has no effect. To revoke access, you must remove the Zeus role entirely.

5. **Optional Account Scoping for System Zeus**: System Zeus users can optionally send `X-Account-ID` header to scope their view to a specific tenant for testing or focused work.

**Example: Creating a Platform Admin (System Zeus)**

```php
// Create System Zeus role (no account_id)
$systemZeus = Role::create([
    'name' => 'Platform Administrator',
    'zeus_level' => 'system',
    'description' => 'Full platform access'
]);

// Assign to user
$admin = User::find($userId);
$admin->roles()->attach($systemZeus->id);

// This user can now:
// - Access all tenants without setting X-Account-ID
// - Bypass all permission checks
// - View/modify data across entire platform
```

**Example: Creating an Account Owner (Tenant Zeus)**

```php
// Create Tenant Zeus role for specific account
$tenantZeus = Role::create([
    'name' => 'Account Owner',
    'zeus_level' => 'tenant',
    'account_id' => $accountId,
    'description' => 'Full access within this account'
]);

// Assign to account owner
$owner = User::find($ownerId);
$owner->roles()->attach($tenantZeus->id);

// This user must set X-Account-ID header
// When set to their account, they bypass all permission checks
// Cannot access other accounts' data
```
---

### Understanding the Permission Delegation System

The delegation system enforces a **two-tier cap** on who can grant what permissions to whom.
This is the core model for multi-tenant SaaS: a platform operator decides what each account (tenant) can distribute, and the account admin distributes within that cap.

#### Mental Model

```
Platform (System Zeus / seeder)
  │
  ├─ assigns permissions to Account (grantable = true)
  │     → this becomes the account's redistributable cap
  │
  └─ Account members receive permissions within that cap
        │
        ├─ Tenant Zeus  → can grant the FULL account cap to any user
        │
        └─ Regular user → can only grant the INTERSECTION of:
                           (permissions they personally hold)
                           ∩
                           (account grantable cap)
```

A user **can never escalate** — they cannot grant a permission they don't hold, and cannot exceed what the account cap allows.

#### The `grantable` Flag

The `assigned_permissions` table has a `grantable` boolean column (added by migration `2024_01_01_000002`).

| `assignee_type` | `grantable = true` means |
|-----------------|---------------------------|
| `account`       | The account may distribute this permission to its users/roles. This is the **account cap**. |
| `user` / `role` | The holder may further delegate this permission to other users when creating/editing accounts. |

#### Seeding Account Caps

When provisioning a new tenant, the platform should assign the permissions it is entitled to, with `grantable = true`:

```php
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Account;

$account  = Account::find($accountId);
$postsPerm = Permission::where('name', 'posts')->first();

// Grant the account the ability to distribute read+update of posts
AssignedPermission::create([
    'permission_id' => $postsPerm->id,
    'assignee_id'   => $account->id,
    'assignee_type' => 'account',   // MUST be 'account'
    'account_id'    => null,         // leave null for account-level assignments
    'access'        => ['read', 'update'],  // restrict to these actions; null = full access
    'grantable'     => true,         // marks this as redistributable
]);

// Grant full access to another permission (null access = all actions)
AssignedPermission::create([
    'permission_id' => Permission::where('name', 'reports')->value('id'),
    'assignee_id'   => $account->id,
    'assignee_type' => 'account',
    'account_id'    => null,
    'access'        => null,   // null = full access for on-off or all CRUD actions
    'grantable'     => true,
]);
```

> **Important:** `Account::getMorphClass()` returns `'account'`, so `assignee_type` must be the string `'account'` (not the fully-qualified class name).

#### Getting What a User Can Delegate

Call `getGrantablePermissions($accountId)` on any user model that uses `HasAbac`.

Returns a **Collection keyed by `permission_id`**, value is `access[]` or `null` (null = full access).

```php
$user = auth()->user();

// Uses TenantContext if $accountId is omitted
$cap = $user->getGrantablePermissions($accountId);

foreach ($cap as $permissionId => $allowedAccess) {
    // $allowedAccess = ['read', 'update'] or null (= full)
    echo "Can delegate permission {$permissionId}: " . ($allowedAccess ? implode(', ', $allowedAccess) : 'full');
}
```

**Zeus behaviour:**

| Actor | Result |
|-------|--------|
| **System Zeus** | Returns ALL permissions with `null` access (fully uncapped) |
| **Tenant Zeus** | Returns the account's full grantable cap — they are the tenant super-admin |
| **Regular user** | Returns intersection: only permissions they personally hold AND that are in the account cap, access restricted to the minimum of both sides |
| No account context | Returns empty collection (for non-System Zeus) |

#### Validating a Delegation Payload

Before writing permissions on behalf of another user, call `authorizePermissionDelegation()`. It throws `\Illuminate\Auth\Access\AuthorizationException` if anything in the payload exceeds the actor's cap.

```php
$actor = auth()->user();

$payload = [
    ['id' => $postsPermId,   'access' => ['read', 'update']],
    ['id' => $reportsPermId, 'access' => null],  // requesting full access
];

try {
    // Throws if any item is outside the actor's grantable cap
    $actor->authorizePermissionDelegation($payload, $accountId);

    // Safe to persist
    foreach ($payload as $item) {
        AssignedPermission::create([
            'permission_id' => $item['id'],
            'assignee_id'   => $targetUserId,
            'assignee_type' => 'user',
            'account_id'    => $accountId,
            'access'        => $item['access'],
            'grantable'     => $item['grantable'] ?? false,
        ]);
    }
} catch (\Illuminate\Auth\Access\AuthorizationException $e) {
    return response()->json(['error' => $e->getMessage()], 403);
}
```

> **System Zeus auto-bypass:** `authorizePermissionDelegation()` returns immediately without any checks when the actor is System Zeus.

#### What a User Can Receive vs. Grant

This is the critical distinction:

| Method | Purpose | Zeus short-circuit |
|--------|---------|-------------------|
| `getAllPermissions()` | What the user **has** (for auth checks). Always returns only directly assigned permissions. | Zeus bypasses `hasPermission()` checks, does NOT inflate this list. |
| `getGrantablePermissions($accountId)` | What the user **can give** to others. | System Zeus → all. Tenant Zeus → full account cap. |

```php
// What does this user have? (for hasPermission / middleware)
$permissions = $user->getAllPermissions();
// → e.g. "posts:read, posts:update"

// What can this user delegate to a new user they are creating?
$grantableCap = $user->getGrantablePermissions($accountId);
// → Collection keyed by permission_id
```

#### Account Cap Helper

The `Account` model exposes the cap directly:

```php
$account = Account::find($accountId);

// Returns Collection: [permission_id => access[] | null]
$cap = $account->getGrantableCap();
```

This is used internally by `getGrantablePermissions()`, but you can call it directly if you need to display the tenant's permissions ceiling in an admin UI.

#### REST API Endpoints (Built-in Controller)

The `PermissionManagementController` provides ready-to-use endpoints:

**Get what the authenticated actor can delegate**
```
GET /abac/grantable?account_id={accountId}
```

Response:
```json
[
  { "id": "...", "name": "posts",   "type": "crud",   "grantable_access": ["read", "update"] },
  { "id": "...", "name": "reports", "type": "on-off", "grantable_access": null }
]
```

`grantable_access: null` means the actor may delegate this permission with **full** access. Use this endpoint to populate the "assign permissions" UI when creating or editing a sub-user.

**Sync permissions to a user or role (with delegation cap guard)**
```
POST /abac/sync/{type}/{id}
Content-Type: application/json

{
  "account_id": "{accountId}",
  "permissions": [
    { "id": "{permId}", "access": ["read", "update"], "grantable": false },
    { "id": "{permId}", "access": null,               "grantable": true }
  ]
}
```

- `type` must be `user` or `role`.
- `grantable: true` on an item means the target user may further delegate this permission.
- The request is rejected with `403` if any permission/access exceeds the actor's cap.

#### Complete Workflow Example

```php
// 1. Platform seeds account with its entitled permissions
AssignedPermission::create([
    'permission_id' => $postsPerm->id,
    'assignee_id'   => $account->id,
    'assignee_type' => 'account',
    'account_id'    => null,
    'access'        => null,   // full access
    'grantable'     => true,
]);

// 2. Tenant admin (Tenant Zeus) creates a Manager user
// Tenant Zeus can grant anything in the account cap
$tenantAdmin = User::find($adminId); // has zeus_level='tenant' role for this account

$managerPayload = [
    ['id' => $postsPerm->id, 'access' => ['read', 'create', 'update'], 'grantable' => true],
    //     ↑ grantable:true means Manager can further delegate these to their own sub-users
];

$tenantAdmin->authorizePermissionDelegation($managerPayload, $account->id); // passes — Tenant Zeus

foreach ($managerPayload as $item) {
    AssignedPermission::create([
        'permission_id' => $item['id'],
        'assignee_id'   => $managerId,
        'assignee_type' => 'user',
        'account_id'    => $account->id,
        'access'        => $item['access'],
        'grantable'     => $item['grantable'],
    ]);
}

// 3. Manager creates a Staff user
// Manager only holds read+create+update, so they cannot grant delete
$manager = User::find($managerId);

$staffPayload = [
    ['id' => $postsPerm->id, 'access' => ['read'], 'grantable' => false],
];

$manager->authorizePermissionDelegation($staffPayload, $account->id); // passes

// This would THROW — manager doesn't hold delete
$manager->authorizePermissionDelegation([
    ['id' => $postsPerm->id, 'access' => ['read', 'delete']]
], $account->id);
// → AuthorizationException: You cannot delegate [delete] for permission [...]
```

---

### 3. Check Permissions

#### Get User Permissions (from Cache)

To retrieve all effective permissions for a user (with caching and auto-recache):

```php
$permissions = AbacPermissions::getPermissions($user);
// Returns an array of permission strings, e.g. ['posts:create', 'posts:read', ...]
```

**How it works:**
- Permissions are cached per user and account context for performance.
- Cache is automatically invalidated when permissions or roles change.
- If the cache is empty, it will auto-recache on the next call.

**Example:**
```php
// Get permissions for the current user
$permissions = AbacPermissions::getPermissions(auth()->user());

// Check for a specific permission
if (in_array('posts:create', $permissions)) {
    // User can create posts
}
```

#### Check a Specific Permission

Via Facade:
```php
if (AbacPermissions::hasPermission($user, 'posts:create')) { ... }
```

In Controller:
```php
$this->authorizePermission('posts:create');
```

Response JSON automatically includes effective permissions in `_permissions` if middleware is enabled.

### 4. Tenancy

Set context via middleware (`DetectAbacTenant`) looking for `X-Account-Slug` header, or manually:

```php
app(\AbacPermissions\Tenancy\TenantContext::class)->setAccount($account);
```

All `UsesTenant` models will automatically scoped to this account.

### 5. Activity Logging

```php
app(\AbacPermissions\Logging\ActivityLogger::class)->log('role.created', $role);
```

### 6. Controller Helper Methods

The `AbacControllerHelper` trait provides convenient methods for managing permissions and roles in your controllers.

#### Using the Helper Trait

```php
use AbacPermissions\Controllers\AbacControllerHelper;

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

#### Account Selection Helper

**Get User Accounts:**
The controller includes a helper to fetch accounts the current user has access to. This is ideal for building frontend account switchers.
- **System Zeus**: Returns ALL accounts.
- **Regular Users**: Returns accounts where they have an assigned role or direct permission.

```php
// GET /api/abac/user-accounts (assuming you routed this method)
$accounts = $this->userAccounts($request);
```

#### Complete Example

```php
use AbacPermissions\Controllers\AbacControllerHelper;

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
    $this->call(\AbacPermissions\Seeders\AbacPermissionsSeeder::class);
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

> **LLM / AI Agent Note:** When using this package in code generation contexts:
> - Call `$user->getGrantablePermissions($accountId)` to determine the bounded set of permissions a user may assign when creating another user in the same account.
> - Call `$user->authorizePermissionDelegation($payload, $accountId)` before writing any `AssignedPermission` records on behalf of a user to enforce the delegation cap automatically.
> - Never set `grantable = true` on an account-level `AssignedPermission` unless you intend that permission to be redistributable within the tenant.
> - `getAllPermissions()` returns what the user **has**; `getGrantablePermissions()` returns what the user **can give**. These are intentionally separate.
> - Zeus checks: use `$user->isSystemZeus()`, `$user->isTenantZeus($accountId)`, and `$user->isZeus()` as guards before expensive queries.

## License

MIT
