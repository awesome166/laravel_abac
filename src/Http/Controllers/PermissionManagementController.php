<?php

namespace AbacPermissions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Facades\AbacPermissions;
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
        $this->resolveSubject($type, $id);
        $accountId = $request->query('account_id');

        $permissions = AssignedPermission::query()
            ->where('assignee_type', $type)
            ->where('assignee_id', $id)
            ->when($type === 'user', function ($query) use ($accountId) {
                if ($accountId === null) {
                    return $query->whereNull('account_id');
                }

                return $query->where('account_id', $accountId);
            }, function ($query) {
                return $query->whereNull('account_id');
            })
            ->with('permission')
            ->get()
            ->map(function ($assignment) {
                $perm = $assignment->permission;
                if (!$perm) {
                    return null;
                }

                $access = is_array($assignment->access) ? $assignment->access : null;
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
                    'access' => $access,
                ];
            })
            ->filter()
            ->values();

        return response()->json($permissions);
    }

    /**
     * Sync permissions to a subject.
     * Payload: list of { id: 1, access: ['read'], grantable: false }
     *
     * The authenticated actor must hold each requested permission within
     * their grantable cap. System Zeus bypasses this check entirely.
     */
    public function sync(Request $request, $type, $id)
    {
        $subject   = $this->resolveSubject($type, $id);
        $input     = $request->input('permissions', []);
        $accountId = $request->input('account_id');

        // --- Delegation cap guard ------------------------------------------------
        $actor = $request->user();
        if ($actor) {
            AbacPermissions::authorizePermissionDelegation($actor, $input, $accountId);
        }
        // -------------------------------------------------------------------------

        // Delete existing assignments for this subject
        $deleteQuery = \AbacPermissions\Models\AssignedPermission::where('assignee_id', $id)
            ->where('assignee_type', $type);

        if ($type === 'user') {
            if ($accountId === null) {
                $deleteQuery->whereNull('account_id');
            } else {
                $deleteQuery->where('account_id', $accountId);
            }
        } else {
            $deleteQuery->whereNull('account_id');
        }

        $deleteQuery->delete();

        // Create new assignments
        foreach ($input as $item) {
            $permId    = $item['id'];
            $access    = $item['access']    ?? null;
            $grantable = $item['grantable'] ?? false;

            AssignedPermission::create([
                'permission_id' => $permId,
                'assignee_id'   => $id,
                'assignee_type' => $type,
                'account_id'    => $type === 'user' ? ($accountId ?? null) : null,
                'access'        => $access,
                'grantable'     => $grantable,
            ]);
        }

        // Flush cache for affected users
        if ($type === 'user') {
            \AbacPermissions\Facades\AbacPermissions::flushCache($subject);
        } elseif ($type === 'role') {
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
     * Get the grantable permissions for the currently authenticated user.
     *
     * Returns the list of permissions (with allowed access) that this user
     * may assign to other users. This is what the frontend should use to
     * populate the "assign permissions" UI when creating/editing sub-users.
     *
     * Response shape:
     *   [
     *     { id, name, type, grantable_access: ['read','update'] | null },
     *     ...
     *   ]
     */
    public function grantable(Request $request)
    {
        $actor = $request->user();

        if (!$actor) {
            return response()->json([]);
        }

        $accountId = $request->query('account_id');
        $cap = AbacPermissions::getGrantablePermissions($actor, $accountId);

        // Hydrate permission records so we can return name + type
        $permissionIds = $cap->keys()->toArray();
        $permissions   = Permission::whereIn('id', $permissionIds)->get()->keyBy('id');

        $result = $cap->map(function ($access, $permId) use ($permissions) {
            $perm = $permissions->get($permId);
            if (!$perm) return null;

            return [
                'id'              => $perm->id,
                'name'            => $perm->name,
                'type'            => $perm->type,
                'grantable_access' => $access, // null = full access allowed
            ];
        })->values()->filter()->values();

        return response()->json($result);
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

        return AbacPermissions::getAccessibleAccounts($user);
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
