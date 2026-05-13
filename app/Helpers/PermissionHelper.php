<?php

namespace App\Helpers;

use App\Enums\ScopeType;
use App\Models\Permission;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 권한 체크 헬퍼
 *
 * AuthServiceProvider의 권한 체크 메서드를 간결하게 호출할 수 있는 API를 제공합니다.
 * scope_type 기반 스코프 체크 기능을 포함합니다.
 */
class PermissionHelper
{
    /**
     * Permission 캐시 (resource_route_key, owner_key)
     *
     * @var array<string, array{resource_route_key: string|null, owner_key: string|null}>
     */
    protected static array $permissionCache = [];

    /**
     * 단일 권한 체크
     *
     * @param  string  $ability  권한 식별자
     * @param  User|null  $user  사용자 (null이면 현재 인증 사용자 또는 guest)
     * @return bool 권한 보유 여부
     */
    public static function check(string $ability, ?User $user = null): bool
    {
        $user = $user ?? Auth::user();

        return AuthServiceProvider::checkPermission($ability, $user);
    }

    /**
     * 구조화 권한 체크 (OR/AND 중첩)
     *
     * @param  array|object  $permissions  권한 구조 (flat array=AND, {"or":[...]}, {"and":[...]})
     * @param  User|null  $user  사용자
     * @return bool 권한 보유 여부
     */
    public static function checkWithLogic(array|object $permissions, ?User $user = null): bool
    {
        $user = $user ?? Auth::user();

        return AuthServiceProvider::checkPermissionsWithLogic($permissions, $user);
    }

    /**
     * 해당 권한의 effective scope를 조회합니다.
     *
     * @param  string  $permission  권한 식별자
     * @param  User|null  $user  사용자 (null이면 현재 인증 사용자)
     * @return string|null null(전체), 'role'(소유역할), 'self'(본인)
     */
    public static function getEffectiveScope(string $permission, ?User $user = null): ?string
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return null;
        }

        return $user->getEffectiveScopeForPermission($permission);
    }

    /**
     * 해당 권한의 owner_key를 조회합니다 (static 캐시).
     *
     * @param  string  $permission  권한 식별자
     * @return string|null 소유자 식별 컬럼명
     */
    public static function getOwnerKey(string $permission): ?string
    {
        $cached = self::getPermissionScopeData($permission);

        return $cached['owner_key'];
    }

    /**
     * 해당 권한의 resource_route_key를 조회합니다 (static 캐시).
     *
     * @param  string  $permission  권한 식별자
     * @return string|null 리소스 라우트 파라미터명
     */
    public static function getResourceRouteKey(string $permission): ?string
    {
        $cached = self::getPermissionScopeData($permission);

        return $cached['resource_route_key'];
    }

    /**
     * 목록 엔드포인트의 쿼리에 권한 스코프 필터를 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  string  $permission  권한 식별자
     * @param  User|null  $user  사용자 (null이면 현재 인증 사용자)
     * @return void
     */
    public static function applyPermissionScope(Builder $query, string $permission, ?User $user = null): void
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return;
        }

        $effectiveScope = $user->getEffectiveScopeForPermission($permission);

        if ($effectiveScope === null) {
            return; // 전체 접근
        }

        $ownerKey = self::getOwnerKey($permission);

        if (! $ownerKey) {
            return; // owner_key 없음 → 스코프 체크 불필요
        }

        if ($effectiveScope === 'self') {
            $query->where($ownerKey, $user->id);
        } elseif ($effectiveScope === 'role') {
            $myRoleIds = $user->roles()->pluck('roles.id');
            $query->whereIn($ownerKey, function ($sub) use ($myRoleIds) {
                $sub->select('user_id')
                    ->from('user_roles')
                    ->whereIn('role_id', $myRoleIds);
            });
        }
    }

    /**
     * 상세 엔드포인트의 모델에 대한 스코프 접근 권한을 체크합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  string  $permission  권한 식별자
     * @param  User|null  $user  사용자 (null이면 현재 인증 사용자)
     * @return bool 접근 허용 여부
     */
    public static function checkScopeAccess(Model $model, string $permission, ?User $user = null): bool
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return true; // 비인증 사용자는 스코프 체크 스킵 (권한 체크는 미들웨어)
        }

        $scopeData = self::getPermissionScopeData($permission);
        $resourceRouteKey = $scopeData['resource_route_key'];
        $ownerKey = $scopeData['owner_key'];

        // resource_route_key 또는 owner_key가 없으면 스코프 체크 불필요
        if (! $resourceRouteKey || ! $ownerKey) {
            return true;
        }

        $effectiveScope = $user->getEffectiveScopeForPermission($permission);

        // scope=null → 전체 접근
        if ($effectiveScope === null) {
            return true;
        }

        $resourceOwnerId = $model->{$ownerKey};

        if ($effectiveScope === 'self') {
            return $resourceOwnerId !== null && (int) $resourceOwnerId === (int) $user->id;
        }

        if ($effectiveScope === 'role') {
            if ($resourceOwnerId === null) {
                return false;
            }

            $myRoleIds = $user->roles()->pluck('roles.id');

            return DB::table('user_roles')
                ->where('user_id', $resourceOwnerId)
                ->whereIn('role_id', $myRoleIds)
                ->exists();
        }

        return false;
    }

    /**
     * Permission 스코프 데이터를 static 캐시와 함께 조회합니다.
     *
     * @param  string  $permission  권한 식별자
     * @return array{resource_route_key: string|null, owner_key: string|null}
     */
    protected static function getPermissionScopeData(string $permission): array
    {
        if (! isset(self::$permissionCache[$permission])) {
            $data = Permission::where('identifier', $permission)
                ->select('resource_route_key', 'owner_key')
                ->first();

            self::$permissionCache[$permission] = [
                'resource_route_key' => $data?->resource_route_key,
                'owner_key' => $data?->owner_key,
            ];
        }

        return self::$permissionCache[$permission];
    }
}
