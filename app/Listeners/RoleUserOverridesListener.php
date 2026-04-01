<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Models\Role;

/**
 * 역할 유저 수정 추적 리스너
 *
 * RoleService에서 발행하는 훅을 구독하여
 * roles.user_overrides 컬럼에 유저가 수정한 항목을 자동으로 기록합니다.
 *
 * 기록 형식:
 * - 필드 변경: "name", "description" (필드명)
 * - 권한 변경: "sirsoft-board.boards.read" (권한 식별자 — 개별 추적)
 */
class RoleUserOverridesListener implements HookListenerInterface
{
    public function __construct(
        private RoleRepositoryInterface $roleRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.role.before_update' => ['method' => 'handleBeforeUpdate', 'priority' => 10],
            'core.role.after_sync_permissions' => ['method' => 'handleAfterSyncPermissions', 'priority' => 10],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void {}

    /**
     * 역할 업데이트 전: 변경된 필드를 user_overrides에 기록
     *
     * @param  Role  $role  업데이트 대상 역할
     * @param  array  $data  업데이트 데이터
     */
    public function handleBeforeUpdate(Role $role, array $data): void
    {
        $userOverrides = $role->user_overrides ?? [];
        $changed = false;

        if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
            if (! in_array('name', $userOverrides, true)) {
                $userOverrides[] = 'name';
                $changed = true;
            }
        }

        if (array_key_exists('description', $data) && $data['description'] !== $role->description) {
            if (! in_array('description', $userOverrides, true)) {
                $userOverrides[] = 'description';
                $changed = true;
            }
        }

        if ($changed) {
            $this->roleRepository->update($role, ['user_overrides' => $userOverrides]);
        }
    }

    /**
     * 역할 권한 동기화 후: 변경된 개별 권한 식별자를 user_overrides에 기록
     *
     * Service가 전달하는 이전/이후 권한 식별자 목록을 비교하여
     * 추가되거나 제거된 권한 식별자를 개별적으로 기록합니다.
     *
     * @param  Role  $role  권한이 동기화된 역할
     * @param  array  $previousPermIdentifiers  동기화 전 권한 식별자 배열
     * @param  array  $currentPermIdentifiers  동기화 후 권한 식별자 배열
     */
    public function handleAfterSyncPermissions(Role $role, array $previousPermIdentifiers, array $currentPermIdentifiers): void
    {
        // 추가된 권한 + 제거된 권한 = 유저가 변경한 권한
        $added = array_diff($currentPermIdentifiers, $previousPermIdentifiers);
        $removed = array_diff($previousPermIdentifiers, $currentPermIdentifiers);
        $changedPermissions = array_merge($added, $removed);

        if (empty($changedPermissions)) {
            return;
        }

        $userOverrides = $role->user_overrides ?? [];
        $changed = false;

        foreach ($changedPermissions as $permIdentifier) {
            if (! in_array($permIdentifier, $userOverrides, true)) {
                $userOverrides[] = $permIdentifier;
                $changed = true;
            }
        }

        if ($changed) {
            $this->roleRepository->update($role, ['user_overrides' => $userOverrides]);
        }
    }
}
