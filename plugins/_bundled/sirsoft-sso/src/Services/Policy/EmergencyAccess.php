<?php

namespace Plugins\Sirsoft\Sso\Services\Policy;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;

/**
 * 비상 계정(D12, W4) — 강제가 켜져 있어도 비밀번호 + 2단계 인증으로 들어올 수 있는 지정 계정.
 *
 * 목록은 최고관리자만 편집한다(`PolicyService::update()` 의 최고관리자 전용 키). 강제 중 비상 계정이 비밀번호로 로그인하면
 * 활동 로그 `sso.emergency_login` 과 액션 `sirsoft-sso.auth.emergency_login`(알림 `sso_emergency_login`)을 남긴다.
 *
 * @since 1.0.0
 */
class EmergencyAccess
{
    use ResolvesActivityLogType;

    /** 비상 계정 로그인 훅 */
    public const ACTION_EMERGENCY_LOGIN = 'sirsoft-sso.auth.emergency_login';

    /**
     * @param  PolicyService  $policy  정책
     * @param  SitePolicyRepositoryInterface  $repository  정책 저장소
     */
    public function __construct(
        private readonly PolicyService $policy,
        private readonly SitePolicyRepositoryInterface $repository,
    ) {}

    /**
     * 회원이 비상 계정인지 확인합니다.
     *
     * @param  User  $user  회원
     * @return bool 비상 계정이면 true
     */
    public function isEmergencyUser(User $user): bool
    {
        return in_array((int) $user->getKey(), $this->policy->emergencyUserIds(), true);
    }

    /**
     * 로그인 칸 입력이 비상 계정 이메일인지 확인합니다 (시도 전 판정 — W4).
     *
     * @param  string  $identifier  로그인 칸 입력
     * @return bool 비상 계정 이메일이면 true
     */
    public function isEmergencyIdentifier(string $identifier): bool
    {
        return in_array(mb_strtolower(trim($identifier)), $this->repository->emailsForUsers($this->policy->emergencyUserIds()), true);
    }

    /**
     * 비상 계정의 비밀번호 로그인을 기록합니다 — 강제가 켜져 있을 때만.
     *
     * @param  User  $user  로그인한 회원
     * @return bool 기록했으면 true
     */
    public function recordLogin(User $user): bool
    {
        if (! $this->isEmergencyUser($user) || ! $this->policy->anyEnforcementOn()) {
            return false;
        }

        $this->logActivity('sso.emergency_login', [
            'loggable' => $user,
            'description_key' => 'sirsoft-sso::activity_log.description.sso.emergency_login',
            'description_params' => ['user_name' => (string) ($user->name ?? '')],
            'properties' => ['user_uuid' => (string) ($user->uuid ?? '')],
        ]);

        HookManager::doAction(self::ACTION_EMERGENCY_LOGIN, $user);

        return true;
    }

    /**
     * 정책 요청 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->policy->flush();
    }
}
