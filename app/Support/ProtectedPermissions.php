<?php

namespace App\Support;

/**
 * Permissions that belong exclusively to the global Master Admin and must never
 * be granted to a branch role through any API path (role create/update/sync) or
 * offered by the available-permissions endpoint.
 *
 * Centralised so future owner-only permissions can be added in one place.
 */
final class ProtectedPermissions
{
    /** @var string[] Master-Admin-only permission names. */
    private const MASTER_ONLY = [
        'manage-accounts',
    ];

    /** @return string[] */
    public static function masterOnly(): array
    {
        return self::MASTER_ONLY;
    }

    public static function isProtected(string $permission): bool
    {
        return in_array($permission, self::MASTER_ONLY, true);
    }

    /**
     * Any protected permissions present in the given list.
     *
     * @param  iterable<string>  $permissions
     * @return string[]
     */
    public static function intersect(iterable $permissions): array
    {
        $out = [];
        foreach ($permissions as $p) {
            if (self::isProtected((string) $p)) {
                $out[] = (string) $p;
            }
        }
        return array_values(array_unique($out));
    }
}
