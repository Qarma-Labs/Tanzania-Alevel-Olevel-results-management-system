<?php

namespace App\Libraries;

/**
 * Central RBAC helper.
 *
 * Canonical roles: admin, head_of_school, teacher.
 * Legacy `users.role` values ("user", "admin") are normalized so old
 * accounts keep working: "user" maps to "teacher".
 */
class Authorization
{
    public const ADMIN         = 'admin';
    public const HEAD_OF_SCHOOL = 'head_of_school';
    public const TEACHER       = 'teacher';

    /**
     * Normalize any role label coming from DB/session/input.
     */
    public static function normalizeRole(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        $key = strtolower(trim($role));
        $key = str_replace(['-', ' '], '_', $key);
        $key = preg_replace('/__+/', '_', $key);

        $aliases = [
            'administrator'  => self::ADMIN,
            'admins'         => self::ADMIN,
            'head'           => self::HEAD_OF_SCHOOL,
            'hos'            => self::HEAD_OF_SCHOOL,
            'headmaster'     => self::HEAD_OF_SCHOOL,
            'headmistress'   => self::HEAD_OF_SCHOOL,
            'headteacher'    => self::HEAD_OF_SCHOOL,
            'head_of_school' => self::HEAD_OF_SCHOOL,
            'headofschool'   => self::HEAD_OF_SCHOOL,
            'teacher'        => self::TEACHER,
            'teachers'       => self::TEACHER,
            'staff'          => self::TEACHER,
            // Legacy column default.
            'user'           => self::TEACHER,
            'users'          => self::TEACHER,
        ];

        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        if (in_array($key, [self::ADMIN, self::HEAD_OF_SCHOOL, self::TEACHER], true)) {
            return $key;
        }

        return null;
    }

    /**
     * Normalize a mixed list of roles, dropping unknowns and duplicates.
     *
     * @param mixed $roles
     * @return list<string>
     */
    public static function normalizeRoles($roles): array
    {
        if (is_string($roles)) {
            $roles = explode(',', $roles);
        }

        if (! is_array($roles)) {
            return [];
        }

        $out = [];
        foreach ($roles as $role) {
            if (! is_string($role)) {
                continue;
            }
            $normalized = self::normalizeRole($role);
            if ($normalized !== null && ! in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * True when the user holds at least one of the required roles.
     * Admin is a superuser and passes every check.
     *
     * @param mixed $userRoles
     * @param mixed $requiredRoles
     */
    public static function hasRole($userRoles, $requiredRoles): bool
    {
        $userRoles     = self::normalizeRoles($userRoles);
        $requiredRoles = self::normalizeRoles($requiredRoles);

        if ($userRoles === [] || $requiredRoles === []) {
            return false;
        }

        if (in_array(self::ADMIN, $userRoles, true)) {
            return true;
        }

        foreach ($requiredRoles as $required) {
            if (in_array($required, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the user is a global admin (bypasses school scoping).
     *
     * @param mixed $userRoles
     */
    public static function isAdmin($userRoles): bool
    {
        return in_array(self::ADMIN, self::normalizeRoles($userRoles), true);
    }

    /**
     * Merge pivot-table roles with the legacy users.role column.
     *
     * @param list<string> $pivotRoles
     */
    public static function mergeRoles(array $pivotRoles, ?string $legacyRole): array
    {
        $merged = self::normalizeRoles($pivotRoles);

        $legacy = self::normalizeRole($legacyRole ?? '');
        if ($legacy !== null && ! in_array($legacy, $merged, true)) {
            $merged[] = $legacy;
        }

        // Every account needs at least teacher-level access, otherwise a
        // user with no pivot row would be locked out of everything.
        if ($merged === []) {
            $merged[] = self::TEACHER;
        }

        return $merged;
    }

    /**
     * Pick the primary role for backwards-compatible `session('role')` reads.
     *
     * @param list<string> $roles normalized roles
     */
    public static function primaryRole(array $roles): string
    {
        $roles = self::normalizeRoles($roles);

        foreach ([self::ADMIN, self::HEAD_OF_SCHOOL, self::TEACHER] as $preferred) {
            if (in_array($preferred, $roles, true)) {
                return $preferred;
            }
        }

        return self::TEACHER;
    }
}
