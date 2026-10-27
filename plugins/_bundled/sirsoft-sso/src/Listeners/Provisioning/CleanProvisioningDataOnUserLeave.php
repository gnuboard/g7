<?php

namespace Plugins\Sirsoft\Sso\Listeners\Provisioning;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\Listeners\CleanSsoAccountsOnUserLeave;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimGroupRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoRoleGrantRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoSessionRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoUserAttributeRepositoryInterface;

/**
 * 회원 탈퇴, 삭제 때 프로비저닝 영역 표(추가 속성, 역할 매핑 기록, SSO 세션, SCIM 그룹 소속)의 그 회원 행을 지웁니다.
 *
 * 이 표들은 회원 FK 와 연결 FK 에 CASCADE 를 두지 않는다. 연결 표를 지우는 리스너(우선순위 50)보다 먼저 돌아야 그룹 소속 FK 가
 * 연결 삭제를 막지 않는다 — 그래서 더 작은 수(먼저 실행)로 구독한다.
 *
 * @since 1.0.0
 */
class CleanProvisioningDataOnUserLeave implements HookListenerInterface
{
    /** 우선순위 — 연결 정리(50)보다 먼저 */
    public const PRIORITY = CleanSsoAccountsOnUserLeave::PRIORITY - 10;

    /**
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소
     * @param  SsoUserAttributeRepositoryInterface  $attributes  추가 속성 저장소
     * @param  SsoRoleGrantRepositoryInterface  $grants  역할 매핑 기록 저장소
     * @param  SsoSessionRepositoryInterface  $sessions  세션 저장소
     * @param  ScimGroupRepositoryInterface  $groups  SCIM 그룹 저장소
     */
    public function __construct(
        private readonly SsoAccountRepositoryInterface $accounts,
        private readonly SsoUserAttributeRepositoryInterface $attributes,
        private readonly SsoRoleGrantRepositoryInterface $grants,
        private readonly SsoSessionRepositoryInterface $sessions,
        private readonly ScimGroupRepositoryInterface $groups,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_withdraw' => ['method' => 'handle', 'priority' => self::PRIORITY, 'sync' => true],
            'core.user.before_delete' => ['method' => 'handle', 'priority' => self::PRIORITY, 'sync' => true],
        ];
    }

    /**
     * 그 회원의 프로비저닝 영역 행을 지웁니다.
     *
     * @param  mixed  ...$args  [User]
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User || (int) $user->getKey() <= 0) {
            return;
        }

        $userId = (int) $user->getKey();
        $accountIds = $this->accounts->forUser($userId)->map(static fn ($a) => (int) $a->getKey())->all();

        $this->groups->deleteMembershipsFor($accountIds);
        $this->attributes->deleteForUser($userId);
        $this->grants->deleteForUser($userId);
        $this->sessions->deleteForUser($userId);
    }
}
