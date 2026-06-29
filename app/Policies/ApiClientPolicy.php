<?php

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\User;

class ApiClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function view(User $user, ApiClient $apiClient): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function update(User $user, ApiClient $apiClient): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function delete(User $user, ApiClient $apiClient): bool
    {
        return $this->isAllowedAdmin($user);
    }

    private function isAllowedAdmin(User $user): bool
    {
        // Your current system: role_id = 1 means admin
        if (isset($user->role_id) && (string) $user->role_id === '1') {
            return true;
        }

        // Your current system: unique_id = ADMIN
        if (
            isset($user->unique_id)
            && in_array(strtolower((string) $user->unique_id), ['admin', 'super-admin', 'super_admin'], true)
        ) {
            return true;
        }

        // Optional support if role field exists
        if (
            isset($user->role)
            && is_string($user->role)
            && in_array(strtolower($user->role), ['admin', 'super-admin', 'super_admin'], true)
        ) {
            return true;
        }

        // Optional support if user_type field exists
        if (
            isset($user->user_type)
            && in_array(strtolower((string) $user->user_type), ['admin', 'super-admin', 'super_admin'], true)
        ) {
            return true;
        }

        return false;
    }
}