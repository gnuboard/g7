<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoRoleGrantRepositoryInterface;

/**
 * 역할 매핑 설정 변경의 최고관리자 전용 검사.
 *
 *  - 관리자 권한(admin 타입 권한)을 가진 역할을 대상으로 하는 규칙의 추가, 수정, 삭제는 최고관리자만 — 위임 관리자가 자기 그룹에
 *    관리자 역할을 붙이는 권한 상승 방지.
 *  - 연결 회원 중 관리자가 있는 IdP 의 `role_sync_mode`, `group_source` 변경도 최고관리자만 — `authoritative` 전환이 관리자의 수동
 *    역할을 회수하는 권한 하강 공격 방지.
 *
 * 권한 미들웨어(`sirsoft-sso.settings.update`)와 별개로 IdP 저장 서비스가 저장 직전에 부른다.
 *
 * @since 1.0.0
 */
class RoleSyncRuleValidator
{
    /** 거절 문구 키 */
    public const DENIED_KEY = 'sirsoft-sso::provisioning.role_mapping.super_admin_only';

    /**
     * @param  SsoRoleGrantRepositoryInterface  $roles  역할 조회
     * @param  ProvisioningAccountRepositoryInterface  $accounts  연결 조회
     */
    public function __construct(
        private readonly SsoRoleGrantRepositoryInterface $roles,
        private readonly ProvisioningAccountRepositoryInterface $accounts,
    ) {}

    /**
     * 바꾸려는 값이 행위자에게 허용되는지 확인합니다.
     *
     * @param  IdentityProvider|null  $before  저장 전 IdP (새로 만들면 null)
     * @param  array<string, mixed>  $changes  저장할 값 (`role_mapping`, `role_sync_mode`, `group_source` 중 들어온 것)
     * @param  User|null  $actor  행위자 (null 이면 시스템 — 통과)
     * @return void
     *
     * @throws AuthorizationException 최고관리자 전용 항목을 최고관리자가 아닌 행위자가 바꿀 때 (403)
     */
    public function assertAllowed(?IdentityProvider $before, array $changes, ?User $actor): void
    {
        if ($actor === null || $actor->is_super === true) {
            return;
        }

        if (array_key_exists('role_mapping', $changes)) {
            $old = $before !== null ? RoleSyncService::rules($before) : [];
            $new = RoleSyncService::normalizeRules($changes['role_mapping']);

            $touched = array_merge(self::diffRules($old, $new), self::diffRules($new, $old));
            if ($touched !== [] && $this->roles->adminRoleIdentifiers(array_column($touched, 'role')) !== []) {
                throw new AuthorizationException(__(self::DENIED_KEY));
            }
        }

        if ($before === null) {
            return;
        }

        foreach (['role_sync_mode', 'group_source'] as $key) {
            if (array_key_exists($key, $changes) && $changes[$key] !== $before->{$key}
                && $this->accounts->hasPrivilegedLinkedUser((int) $before->getKey())) {
                throw new AuthorizationException(__(self::DENIED_KEY));
            }
        }
    }

    /**
     * `$a` 에만 있는 규칙을 반환합니다 (그룹, 역할 쌍 비교).
     *
     * @param  array<int, array{group: string, role: string}>  $a  규칙
     * @param  array<int, array{group: string, role: string}>  $b  규칙
     * @return array<int, array{group: string, role: string}> 차이
     */
    private static function diffRules(array $a, array $b): array
    {
        $keys = array_map(static fn (array $r) => $r['group']."\0".$r['role'], $b);

        return array_values(array_filter($a, static fn (array $r) => ! in_array($r['group']."\0".$r['role'], $keys, true)));
    }
}
