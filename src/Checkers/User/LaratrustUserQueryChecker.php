<?php

namespace Laratrust\Checkers\User;

use Laratrust\Helper;
use Illuminate\Support\Facades\Config;

class LaratrustUserQueryChecker extends LaratrustUserChecker
{
    /**
     * Checks if the user has a role by its name.
     *
     * @param  string|array  $name       Role name or array of role names.
     * @param  string|bool   $team      Team name or requiredAll roles.
     * @param  bool          $requireAll All roles in the array are required.
     * @return bool
     */
    public function currentUserHasRole($name, $team = null, $requireAll = false)
    {
        if (empty($name)) {
            return true;
        }

        $name = Helper::standardize($name);
        $rolesNames = is_array($name) ? $name : [$name];
        list($team, $requireAll) = Helper::assignRealValuesTo($team, $requireAll, 'is_bool');
        $useTeams = Config::get('laratrust.use_teams');
        $teamStrictCheck = Config::get('laratrust.teams_strict_check');

        $rolesCount = $this->user->roles()
            ->whereIn('name', $rolesNames)
            ->when($useTeams && ($teamStrictCheck || !is_null($team)), function ($query) use ($team) {
                $teamId = Helper::fetchTeam($team);

                return $query->where(Config::get('laratrust.foreign_keys.team'), $teamId);
            })
            ->count();

        return $requireAll ? $rolesCount == count($rolesNames) : $rolesCount > 0;
    }

    /**
     * Check if user has a permission by its name.
     *
     * @param  string|array  $permission Permission string or array of permissions.
     * @param  string|bool  $team      Team name or requiredAll roles.
     * @param  bool  $requireAll All roles in the array are required.
     * @return bool
     */
    public function currentUserHasPermission($permission, $team = null, $requireAll = false)
    {
        if (empty($permission)) {
            return true;
        }

        $permission = Helper::standardize($permission);
        $permissionsNames = is_array($permission) ? $permission : [$permission];
        list($team, $requireAll) = Helper::assignRealValuesTo($team, $requireAll, 'is_bool');
        $useTeams = Config::get('laratrust.use_teams');
        $teamStrictCheck = Config::get('laratrust.teams_strict_check');

        list($permissionsWildcard, $permissionsNoWildcard) = $this
            ->getPermissionWithAndWithoutWildcards($permissionsNames);

        $rolesPermissionsCount = $this->user->roles()
            ->withCount(['permissions' =>
                function ($query) use ($permissionsNoWildcard, $permissionsWildcard) {
                    $query->whereIn('name', $permissionsNoWildcard);
                    foreach ($permissionsWildcard as $permission) {
                        $query->orWhere('name', 'like', $permission);
                    }
                }
            ])
            ->when($useTeams && ($teamStrictCheck || !is_null($team)), function ($query) use ($team) {
                $teamId = Helper::fetchTeam($team);

                return $query->where(Config::get('laratrust.foreign_keys.team'), $teamId);
            })
            ->pluck('permissions_count')
            ->sum();

        $directPermissionsCount = $this->user->permissions()
            ->whereIn('name', $permissionsNoWildcard)
            ->when($permissionsWildcard, function ($query) use ($permissionsWildcard) {
                foreach ($permissionsWildcard as $permission) {
                    $query->orWhere('name', 'like', $permission);
                }

                return $query;
            })
            ->when($useTeams && ($teamStrictCheck || !is_null($team)), function ($query) use ($team) {
                $teamId = Helper::fetchTeam($team);

                return $query->where(Config::get('laratrust.foreign_keys.team'), $teamId);
            })
            ->count();

        return $requireAll
            ? $rolesPermissionsCount + $directPermissionsCount >= count($permissionsNames)
            : $rolesPermissionsCount + $directPermissionsCount > 0;
    }

    public function currentUserFlushCache()
    {
    }

    /**
     * Return two arrays with the filtered permissions between the permissions
     * with wildcard and the permissions without it.
     *
     * @param array $permissions
     * @return array
     */
    protected function getPermissionWithAndWithoutWildcards($permissions)
    {
        $wildcard = [];
        $noWildcard = [];

        foreach ($permissions as $permission) {
            if (strpos($permission, '*') === false) {
                $noWildcard[] = $permission;
            } else {
                $wildcard[] = str_replace('*', '%', $permission);
            }
        }

        return [$wildcard, $noWildcard];
    }
}