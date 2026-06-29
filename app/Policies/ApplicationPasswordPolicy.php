<?php

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\ApplicationPassword;
use App\Models\User;

class ApplicationPasswordPolicy
{
    public function viewAny(User $user, ApiClient $apiClient): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function create(User $user, ApiClient $apiClient): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function delete(User $user, ApplicationPassword $applicationPassword): bool
    {
        return $this->isAllowedAdmin($user);
    }

    public function rotate(User $user, ApplicationPassword $applicationPassword): bool
    {
        return $this->isAllowedAdmin($user);
    }

    private function isAllowedAdmin(User $user): bool
    {
        if (isset($user->role_id) && (string) $user->role_id === '1') {
            return true;
        }

        if (
            isset($user->unique_id)
            && in_array(strtolower((string) $user->unique_id), ['admin', 'super-admin', 'super_admin'], true)
        ) {
            return true;
        }

        if (
            isset($user->role)
            && is_string($user->role)
            && in_array(strtolower($user->role), ['admin', 'super-admin', 'super_admin'], true)
        ) {
            return true;
        }

        if (
            isset($user->user_type)
            && in_array(strtolower((string) $user->user_type), ['admin', 'super-admin', 'super_admin'], true)
        ) {
            return true;
        }

        return false;
    }
}