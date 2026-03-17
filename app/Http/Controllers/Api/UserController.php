<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List all users (admin/manager only).
     */
    public function index(): JsonResponse
    {
        $users = User::select(['id', 'name', 'username', 'email', 'role', 'is_active', 'current_warehouse_id', 'created_at'])
            ->with(['currentWarehouse:id,name', 'warehouses:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * Create a new user with a role.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'username'              => 'required|string|max:50|unique:users,username',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'role'                  => ['required', Rule::in(User::VALID_ROLES)],
            'current_warehouse_id'  => 'nullable|exists:warehouses,id',
            'is_active'             => 'boolean',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        // Sync allowed warehouses
        if ($request->has('warehouse_ids')) {
            $user->warehouses()->sync($request->input('warehouse_ids', []));
        }

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => $user->load('warehouses:id,name')->makeHidden(['password', 'remember_token']),
        ], 201);
    }

    /**
     * Update an existing user.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'username'              => ['sometimes', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
            'email'                 => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password'              => 'nullable|string|min:8|confirmed',
            'role'                  => ['sometimes', Rule::in(User::VALID_ROLES)],
            'current_warehouse_id'  => 'nullable|exists:warehouses,id',
            'is_active'             => 'sometimes|boolean',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        // Sync allowed warehouses
        if ($request->has('warehouse_ids')) {
            $user->warehouses()->sync($request->input('warehouse_ids', []));
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user'    => $user->fresh()->load('warehouses:id,name')->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * Soft-delete a user by deactivating them.
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent deactivating the last admin
        if ($user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return response()->json([
                'message' => 'Cannot deactivate the last active administrator.',
            ], 422);
        }

        $user->update(['is_active' => false]);

        return response()->json([
            'message' => 'User deactivated successfully.',
        ]);
    }

    /**
     * Set the current warehouse context for the authenticated user.
     * Now validates that the user has access to the requested warehouse.
     */
    public function setWarehouseContext(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message'              => 'Not authenticated',
                'current_warehouse_id' => null,
            ], 200);
        }

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        // Validate access
        if (! $user->hasWarehouseAccess((int) $validated['warehouse_id'])) {
            return response()->json([
                'message' => 'No tienes acceso a esta sucursal.',
            ], 403);
        }

        $user->update([
            'current_warehouse_id' => $validated['warehouse_id'],
        ]);

        return response()->json([
            'message'              => 'Warehouse context updated successfully',
            'current_warehouse_id' => $user->current_warehouse_id,
        ]);
    }

    /**
     * Get the current warehouse context for the authenticated user.
     * Now also returns the list of warehouses the user has access to.
     */
    public function getWarehouseContext(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'current_warehouse_id' => null,
                'current_warehouse'    => null,
                'allowed_warehouses'   => [],
            ]);
        }

        $allowedWarehouses = $user->isAdmin()
            ? \App\Models\Warehouse::select('id', 'name')->orderBy('name')->get()
            : $user->warehouses()->select('warehouses.id', 'warehouses.name')->orderBy('warehouses.name')->get();

        return response()->json([
            'current_warehouse_id' => $user->current_warehouse_id,
            'current_warehouse'    => $user->currentWarehouse,
            'allowed_warehouses'   => $allowedWarehouses,
        ]);
    }
}
