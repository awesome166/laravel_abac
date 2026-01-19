<?php

namespace AbacPermissions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\Account;
use AbacPermissions\Tests\Integration\TestUser; // Fallback for test env?
// In Real app, User model is configurable.
use Illuminate\Support\Facades\Config;

class PermissionManagementController extends Controller
{
    /**
     * List all available permissions grouped.
     */
    public function index()
    {
        // Group permissions? Or just list them with metadata?
        // User asked for "for grouping".
        // CRUD permissions are naturally groupable by name ("posts").
        // ON permissions are distinct.

        return Permission::all()->map(function ($perm) {
            return [
                'id' => $perm->id,
                'name' => $perm->name,
                'type' => $perm->type,
                'available_actions' => $perm->type === 'crud'
                    ? ['create', 'read', 'update', 'delete']
                    : ['on', 'off']
            ];
        });
    }

    /**
     * Get assigned permissions for a subject (Role or User).
     */
    public function getAssigned(Request $request, $type, $id)
    {
        $subject = $this->resolveSubject($type, $id);

        $permissions = $subject->permissions()->get()->map(function ($perm) {

            // Decode pivot access
            $access = null;
            if ($perm->pivot && $perm->pivot->access) {
                // Should use the logic we standardized: array of strings.
                $decoded = is_string($perm->pivot->access)
                         ? json_decode($perm->pivot->access, true)
                         : $perm->pivot->access;
                if (is_string($decoded)) $decoded = json_decode($decoded, true);
                $access = $decoded;
            }

            // If access is null, default for CRUD is full access?
            // Or should API return explicit full list to frontend?
            // "NULL implies full access" is backend logic.
            // Frontend prefers explicit list.
            if ($perm->type === 'crud' && is_null($access)) {
                 $access = ['create', 'read', 'update', 'delete'];
            }
            if ($perm->type === 'on-off' && is_null($access)) {
                 $access = ['on'];
            }

            return [
                'id' => $perm->id,
                'name' => $perm->name,
                'type' => $perm->type,
                'access' => $access
            ];
        });

        return response()->json($permissions);
    }

    /**
     * Sync permissions to a subject.
     * Payload: list of { id: 1, access: ['read'] }
     */
    public function sync(Request $request, $type, $id)
    {
        $subject = $this->resolveSubject($type, $id);

        // $request->input('permissions') should be array of objects
        $input = $request->input('permissions', []);

        // Delete existing assignments for this subject
        \AbacPermissions\Models\AssignedPermission::where('assignee_id', $id)
            ->where('assignee_type', $type)
            ->delete();

        // Create new assignments
        foreach ($input as $item) {
            $permId = $item['id'];
            $access = $item['access'] ?? null;

            \AbacPermissions\Models\AssignedPermission::create([
                'permission_id' => $permId,
                'assignee_id' => $id,
                'assignee_type' => $type,
                'account_id' => $type === 'user' ? ($item['account_id'] ?? null) : null,
                'access' => $access,
            ]);
        }

        // Flush cache for affected users
        if ($type === 'user') {
            \AbacPermissions\Facades\AbacPermissions::flushCache($subject);
        } else if ($type === 'role') {
            // Flush cache for all users with this role
            $userIds = \Illuminate\Support\Facades\DB::table('role_user')
                ->where('role_id', $id)
                ->pluck('user_id');

            $userIds->each(function ($userId) {
                $user = (object)['id' => $userId];
                \AbacPermissions\Facades\AbacPermissions::flushCache($user);
            });
        }

        return response()->json(['status' => 'synced']);
    }

    /**
     * Get accessible accounts for the current user.
     * Used for frontend account selection.
     */
    public function userAccounts(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([]);
        }

        // Check for System Level Zeus
        $isSystemZeus = $user->roles->contains(function($role) {
            return $role->zeus_level === 'system';
        });

        if ($isSystemZeus) {
            // Zeus sees all accounts
            return Account::all();
        }

        // Regular users: Get accounts where they have a role or direct permission
        // 1. Accounts via Roles
        $roleAccountIds = $user->roles->pluck('account_id')->filter();

        // 2. Accounts via Direct Permissions (AssignedPermission where account_id is set)
        // We need to query AssignedPermission table directly or via relationship if it exists on User
        // User hasMany AssignedPermission
        $directAccountIds = \AbacPermissions\Models\AssignedPermission::query()
            ->where('assignee_type', 'user')
            ->where('assignee_id', $user->id)
            ->whereNotNull('account_id')
            ->pluck('account_id');

        $allAccountIds = $roleAccountIds->merge($directAccountIds)->unique();

        return Account::whereIn('id', $allAccountIds)->get();
    }

    protected function resolveSubject($type, $id)
    {
        if ($type === 'role') {
            return Role::findOrFail($id);
        }
        if ($type === 'user') {
            $model = Config::get('abacpermissions.models.user', 'App\\Models\\User');
            return $model::findOrFail($id);
        }
        abort(404);
    }
}
