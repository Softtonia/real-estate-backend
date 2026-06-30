<?php

namespace App\Support;

use Illuminate\Support\Str;

class ApiPermission
{
    public const READ = 'read';
    public const WRITE = 'write';

    public static function postType(string $postTypeSlug, string $action): string
    {
        return 'post_types.' . self::cleanSegment($postTypeSlug) . '.' . self::cleanSegment($action);
    }

    public static function postTypeRead(string $postTypeSlug): string
    {
        return self::postType($postTypeSlug, self::READ);
    }

    public static function postTypeWrite(string $postTypeSlug): string
    {
        return self::postType($postTypeSlug, self::WRITE);
    }

    public static function postTypeAll(string $postTypeSlug): string
    {
        return 'post_types.' . self::cleanSegment($postTypeSlug) . '.*';
    }

    public static function matches(array $grantedPermissions, string $requiredPermission): bool
    {
        $requiredPermission = self::normalize($requiredPermission);

        foreach ($grantedPermissions as $permission) {
            $permission = self::normalize((string) $permission);

            if ($permission === '*') {
                return true;
            }

            if ($permission === $requiredPermission) {
                return true;
            }

            if (Str::is($permission, $requiredPermission)) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $permission): string
    {
        return trim(strtolower($permission));
    }

    private static function cleanSegment(string $value): string
    {
        return trim(strtolower($value));
    }
}