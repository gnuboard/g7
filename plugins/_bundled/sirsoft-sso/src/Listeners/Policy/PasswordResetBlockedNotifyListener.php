<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;

/**
 * 회원 기준 강제에 걸린 비밀번호 찾기(W3) — 코어 액션 `core.auth.password_reset_blocked`.
 *
 * 코어는 계정 열거를 막으려고 일반 성공 응답을 주고 재설정 링크를 보내지 않는다. 메일이 오지 않아 헤매지 않게, 이 거절이 SSO 정책에서
 * 나왔으면 「이 계정은 회사 계정으로 로그인합니다」 안내 메일(알림 `sso_password_reset_redirect`)을 보낸다.
 *
 * @since 1.0.0
 */
class PasswordResetBlockedNotifyListener implements HookListenerInterface
{
    /** 안내 메일 훅 */
    public const ACTION_REDIRECTED = 'sirsoft-sso.auth.password_reset_redirected';

    /**
     * @param  EnforcementPolicy  $policy  판정 서비스
     */
    public function __construct(private readonly EnforcementPolicy $policy) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.password_reset_blocked' => ['method' => 'onBlocked', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * SSO 정책의 거절이면 안내 메일 훅을 쏩니다.
     *
     * @param  mixed  $user  재설정을 요청한 회원
     * @param  mixed  $decision  코어가 받은 거절 판정
     * @return void 반환값 없음
     */
    public function onBlocked(mixed $user = null, mixed $decision = null): void
    {
        if (! $user instanceof User || ! $decision instanceof PasswordAuthDecision) {
            return;
        }

        $ours = $this->policy->decide(['identifier' => (string) $user->email, 'user' => $user, 'guard' => 'user', 'purpose' => 'reset_request']);

        if ($ours === null || $ours->allowed) {
            return;
        }

        HookManager::doAction(self::ACTION_REDIRECTED, $user, $this->policy->guidanceIdpFor($user));
    }
}
