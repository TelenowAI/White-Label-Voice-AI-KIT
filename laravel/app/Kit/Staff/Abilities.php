<?php

namespace App\Kit\Staff;

use App\Models\User;

/**
 * What a staff role may do in the admin panel (config('kit.admin.abilities'): ability → roles). Used by
 * App\Http\Middleware\EnsureAbility on every admin route, and by the session answer (the console shows only what the
 * signed-in role may use). A customer has none.
 */
final class Abilities
{
    /** @return list<string> */
    public static function of(?User $user): array
    {
        $role = $user?->role;
        if ($role === null) {
            return [];
        }

        return array_keys(array_filter(config('kit.admin.abilities'), fn (array $roles) => in_array($role, $roles, true)));
    }

    public static function may(?User $user, string $ability): bool
    {
        $roles = config("kit.admin.abilities.{$ability}");
        if (! is_array($roles)) {
            throw new \LogicException("No ability named {$ability} in config('kit.admin.abilities').");
        }

        return $user !== null && in_array($user->role, $roles, true);
    }
}
