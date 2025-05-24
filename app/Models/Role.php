<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends SpatieRole
{
    use HasFactory;

    protected $fillable = ['name', 'is_admin_login_permission', 'deletable', 'created_by', 'prefix', 'is_default'];
    protected $table = 'roles';

    /**
     * Define the relationship with permissions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(\Spatie\Permission\Models\Permission::class);
    }

    /**
     * Check if the role is deletable based on 'is_default'.
     *
     * @return bool
     */
    public function isDeletable(): bool
    {
        // The role is deletable only if 'is_default' is 0
        return $this->is_default == 0;
    }

    /**
     * Delete the role if it's deletable.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteRole($id)
    {
        // Find the role
        $role = self::findOrFail($id);

        // Check if the role is deletable based on the 'is_default' field
        if (!$role->isDeletable()) {
            return response()->json(['error' => 'Cannot delete this role. It is a default role.'], 403);
        }

        // Proceed with deleting the role
        $role->delete();

        return response()->json(['message' => 'Role deleted successfully.'], 200);
    }

    /**
     * Define the relationship with users.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
