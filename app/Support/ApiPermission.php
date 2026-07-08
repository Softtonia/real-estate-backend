<?php

namespace App\Support;

use Illuminate\Support\Str;

class ApiPermission
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const FULL = '*';

    public static function postType(string $postTypeSlug, string $action): string
    {
        return implode('.', [
            'post_types',
            self::cleanSegment($postTypeSlug),
            self::cleanSegment($action),
        ]);
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
        return implode('.', [
            'post_types',
            self::cleanSegment($postTypeSlug),
            self::FULL,
        ]);
    }

    public static function postTypesReadAll(): string
    {
        return 'post_types.*.read';
    }

    public static function postTypesWriteAll(): string
    {
        return 'post_types.*.write';
    }

    public static function postTypesFullAccess(): string
    {
        return 'post_types.*';
    }

    public static function matches(array $grantedPermissions, string $requiredPermission): bool
    {
        $requiredPermission = self::normalize($requiredPermission);

        if ($requiredPermission === '') {
            return false;
        }

        foreach ($grantedPermissions as $permission) {
            $permission = self::normalize((string) $permission);

            if ($permission === '') {
                continue;
            }

            if ($permission === self::FULL) {
                return true;
            }

            if ($permission === $requiredPermission) {
                return true;
            }

            if (self::wildcardMatches($permission, $requiredPermission)) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $permission): string
    {
        $permission = trim(strtolower($permission));

        $permission = preg_replace('/\s+/', '', $permission);

        return $permission ?: '';
    }

    private static function wildcardMatches(string $permission, string $requiredPermission): bool
    {
        if (! str_contains($permission, '*')) {
            return false;
        }

        /*
         * Supported examples:
         * *
         * post_types.*
         * post_types.*.read
         * post_types.*.write
         * post_types.properties.*
         * post_types.properties.read
         */

        return Str::is($permission, $requiredPermission);
    }

    private static function cleanSegment(string $value): string
    {
        $value = trim(strtolower($value));

        $value = preg_replace('/[^a-z0-9_-]+/i', '-', $value);

        $value = trim((string) $value, '-_');

        return $value ?: '*';
    }
}