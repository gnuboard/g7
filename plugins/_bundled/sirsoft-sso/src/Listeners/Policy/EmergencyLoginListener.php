<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\Policy\EmergencyAccess;

/**
 * 비상 계정의 비밀번호 로그인 기록(W4) — 코어 액션 `core.auth.after_login`.
 *
 * SSO 강제가 켜져 있는 동안 비상 계정이 비밀번호로 로그인하면 활동 로그 `sso.emergency_login` 과 알림 `sso_emergency_login` 을 남긴다.
 * 회사 계정 로그인(`login_method` 가 `sso:` 로 시작)은 대상이 아니다.
 *
 * @since 1.0.0
 */
class EmergencyLoginListener implements HookListenerInterface
{
    /**
     * @param  EmergencyAccess  $emergency  비상 계정
     */
    public function __construct(private readonly EmergencyAccess $emergency) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_login' => ['method' => 'onLogin', 'priority' => 60, 'sync' => true],
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
     * 로그인 직후 판정합니다.
     *
     * @param  mixed  $user  로그인한 회원
     * @param  array<string, mixed>  $context  로그인 문맥 (`login_method`)
     * @return void 반환값 없음
     */
    public function onLogin(mixed $user = null, array $context = []): void
    {
        if (! $user instanceof User || ($context['login_method'] ?? 'password') !== 'password') {
            return;
        }

        $this->emergency->recordLogin($user);
    }
}
