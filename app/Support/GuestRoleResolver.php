<?php

namespace App\Support;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;

/**
 * 비회원(guest) 역할 해석기
 *
 * 비회원 권한 판정은 한 요청에서 수십 번 일어난다 — 미들웨어, Gate, 리소스가 각각 묻는다.
 * 매번 역할과 권한을 다시 조회하지 않도록 요청당 한 번만 적재하고 그 결과를 공유한다.
 *
 * 캐시 수명은 **요청 스코프**다. 프로세스 정적으로 두면 상주형 실행 환경에서 권한을
 * 바꿔도 그 프로세스가 살아 있는 동안 예전 권한으로 판정한다 — 권한 변경 즉시 반영이
 * 깨지므로 그렇게 하지 않는다.
 *
 * 미들웨어와 Gate 가 각자 같은 조회를 들고 있으면 한쪽만 고쳐졌을 때 두 경로의 판정이
 * 갈린다. 그래서 해석은 이 클래스 한 곳에서만 한다.
 */
class GuestRoleResolver
{
    /**
     * 해석 결과를 담는 요청 속성 키
     */
    private const CACHE_KEY = '_guest_role_cache';

    /**
     * guest 역할을 권한과 함께 조회합니다 (요청당 1회).
     *
     * @return Role|null guest 역할 (미정의 시 null)
     */
    public static function resolve(): ?Role
    {
        $request = request();

        if ($request->attributes->has(self::CACHE_KEY)) {
            return $request->attributes->get(self::CACHE_KEY);
        }

        $role = Role::where('identifier', 'guest')
            ->with('permissions')
            ->first();

        $request->attributes->set(self::CACHE_KEY, $role);

        return $role;
    }

    /**
     * guest 역할이 특정 권한을 보유하는지 판정합니다.
     *
     * 적재해 둔 권한 컬렉션에서 판정하므로 판정마다 쿼리가 나가지 않습니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  PermissionType|null  $type  권한 타입 (null 이면 타입 무관)
     * @return bool 보유 여부
     */
    public static function hasPermission(string $identifier, ?PermissionType $type = null): bool
    {
        $role = self::resolve();

        if (! $role) {
            return false;
        }

        foreach ($role->permissions as $permission) {
            if ($permission->identifier !== $identifier) {
                continue;
            }

            if ($type === null || self::permissionType($permission) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * 캐시를 비웁니다.
     *
     * 권한 재시드(코어 업데이트 / 확장 설치) 직후처럼 같은 요청 안에서 권한 구성이
     * 바뀌는 경우에 호출합니다.
     */
    public static function flush(): void
    {
        request()->attributes->remove(self::CACHE_KEY);
    }

    /**
     * 권한의 타입을 Enum 으로 정규화합니다.
     *
     * @param  Permission  $permission  권한 모델
     * @return PermissionType|null 정규화된 타입 (해석 불가 시 null)
     */
    private static function permissionType($permission): ?PermissionType
    {
        $type = $permission->type;

        if ($type instanceof PermissionType) {
            return $type;
        }

        return $type === null ? null : PermissionType::tryFrom((string) $type);
    }
}
