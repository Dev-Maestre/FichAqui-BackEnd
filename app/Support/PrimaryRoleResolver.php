<?php

namespace App\Support;

class PrimaryRoleResolver
{
    /** @var list<string> */
    private const PRIORITY = ['admin', 'organizer', 'stall_manager', 'client'];

    /** @var array<string, string> */
    private const API_MAP = [
        'client' => 'consumer',
        'organizer' => 'organizer',
        'admin' => 'admin',
        'stall_manager' => 'stall_manager',
    ];

    /**
     * @param  list<string>  $roles
     */
    public static function resolve(array $roles): string
    {
        foreach (self::PRIORITY as $role) {
            if (in_array($role, $roles, true)) {
                return self::API_MAP[$role] ?? $role;
            }
        }

        return 'consumer';
    }
}
