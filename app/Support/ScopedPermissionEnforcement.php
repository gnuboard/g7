<?php

namespace App\Support;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Extension\HookManager;

/**
 * 스코프가 붙은 권한 부여가 실제로 어디선가 강제되는지 확인합니다.
 *
 * 권한의 self·role 스코프는 두 자리에서만 강제된다.
 *
 * - (a) 라우트 스코프 메타 — 권한에 `resource_route_key`·`owner_key` 가 있으면 PermissionMiddleware
 *   가 상세 경로를, PermissionHelper::applyPermissionScope 가 목록 쿼리를 좁힌다.
 * - (b) 저장소 쿼리 스코프 — 소유 컬럼이 둘 이상이라 단일 owner_key 로 표현할 수 없는 권한은
 *   저장소가 직접 조건을 건다. 이 목록이 그런 권한의 명단이다.
 *
 * 둘 다 아니면 스코프를 붙여도 아무것도 좁혀지지 않는다 — 운영자는 「본인만」 으로 설정했다고
 * 믿지만 실제로는 전체 접근이다. `core.notification-logs.read` 가 메타 없이 self 로 부여되고
 * 보조 경로(대시보드 최근 알림)에 쿼리 스코프가 빠져, 위임 관리자가 전체 회원의 알림을 보던
 * 전례가 있다(KVE-2026-2374). 이 검사는 시드·코어 업데이트 때 그런 부여를 경고로 드러낸다.
 */
class ScopedPermissionEnforcement
{
    /**
     * 저장소 쿼리 스코프가 정본인 권한 식별자.
     *
     * 여기 올리는 권한은 그 권한으로 열리는 **모든** 조회 경로의 저장소가 스코프를 걸어야 한다 —
     * 목록에만 걸고 보조 경로(최근·통계·대시보드)에 빠뜨리면 그 경로가 우회로가 된다.
     *
     * @var array<int, string>
     */
    public const QUERY_SCOPED_PERMISSIONS = [
        // 발신자·수신자 두 소유 컬럼 — NotificationLogRepository::applyNotificationLogScope
        'core.notification-logs.read',
    ];

    /**
     * @param  PermissionRepositoryInterface  $permissionRepository  권한 조회
     */
    public function __construct(
        private PermissionRepositoryInterface $permissionRepository
    ) {}

    /**
     * 쿼리 스코프가 정본인 권한 식별자 목록을 반환합니다 (확장이 필터 훅으로 추가 가능).
     *
     * @return array<int, string> 권한 식별자 목록
     */
    public static function queryScopedPermissions(): array
    {
        return array_values(array_unique(HookManager::applyFilters(
            'core.permission.query_scoped_permissions',
            self::QUERY_SCOPED_PERMISSIONS
        )));
    }

    /**
     * 역할 정의(config)가 스코프를 거는 권한 중 강제 수단이 없는 것을 찾습니다.
     *
     * @param  array  $permissionsConfig  `config('core.permissions')` 형태의 권한 정의
     * @param  array  $rolesConfig  `config('core.roles')` 형태의 역할 정의
     * @return array<int, array{role: string, permission: string, scope_type: string}> 위반 목록
     */
    public static function findUnenforcedInConfig(array $permissionsConfig, array $rolesConfig): array
    {
        $hasOwnerMetadata = [];
        foreach ($permissionsConfig['categories'] ?? [] as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                $hasOwnerMetadata[$permission['identifier']] = ! empty($permission['resource_route_key'])
                    && ! empty($permission['owner_key']);
            }
        }

        $exempt = self::queryScopedPermissions();
        $violations = [];

        foreach ($rolesConfig as $role) {
            foreach ($role['permission_scopes'] ?? [] as $identifier => $scope) {
                if ($scope === null || ($hasOwnerMetadata[$identifier] ?? false) || in_array($identifier, $exempt, true)) {
                    continue;
                }

                $violations[] = [
                    'role' => $role['identifier'],
                    'permission' => $identifier,
                    'scope_type' => (string) $scope,
                ];
            }
        }

        return $violations;
    }

    /**
     * 저장된 역할-권한 부여 중 스코프가 붙었지만 강제 수단이 없는 것을 찾습니다.
     *
     * @return array<int, array{role: string, permission: string, scope_type: string}> 위반 목록
     */
    public function findUnenforcedScopedGrants(): array
    {
        return $this->permissionRepository->getScopedGrantsWithoutOwnerMetadata(self::queryScopedPermissions());
    }
}
