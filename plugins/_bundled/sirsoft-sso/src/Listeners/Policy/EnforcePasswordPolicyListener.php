<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Auth\PasswordAuthDecision;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;

/**
 * 비밀번호 인증 정책 — 코어 필터 `core.auth.password_auth_decision` 에 SSO 강제와 비상 계정, D22 판정을 답합니다.
 *
 * 다른 확장이 먼저 거절했으면 그 판정을 그대로 둔다(거절을 허용으로 바꾸지 않는다).
 *
 * @since 1.0.0
 */
class EnforcePasswordPolicyListener implements HookListenerInterface
{
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
            'core.auth.password_auth_decision' => ['method' => 'decide', 'type' => 'filter', 'priority' => 20, 'sync' => true],
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
     * 판정을 더합니다.
     *
     * @param  mixed  $decision  앞선 판정 (없으면 null)
     * @param  array<string, mixed>  $subject  `{identifier, user, guard, purpose}`
     * @return mixed 판정
     */
    public function decide(mixed $decision = null, array $subject = []): mixed
    {
        if ($decision instanceof PasswordAuthDecision && ! $decision->allowed) {
            return $decision;
        }

        $ours = $this->policy->decide($subject);

        if ($ours === null) {
            return $decision;
        }

        if ($ours->allowed && $decision instanceof PasswordAuthDecision) {
            return PasswordAuthDecision::allow($decision->requireTwoFactor || $ours->requireTwoFactor);
        }

        return $ours;
    }
}
