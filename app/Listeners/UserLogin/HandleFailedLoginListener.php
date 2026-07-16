<?php

namespace App\Listeners\UserLogin;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;

/**
 * 로그인 실패 리스너 — `core.auth.login_failed` 액션 훅을 구독하여
 * 보안 환경설정의 시도 카운트/계정 잠금 상태를 갱신합니다.
 *
 * Listener 직접 DB 접근 금지 룰에 따라 모든 mutation 은
 * `UserRepositoryInterface` 를 통해 위임됩니다. 임계 도달 시
 * 잠금 처리 후 `core.auth.account_locked` 액션 훅을 추가 발화하여
 * Activity Log / Notification 등 후속 리스너가 구독할 수 있도록 합니다.
 */
class HandleFailedLoginListener implements HookListenerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환.
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.login_failed' => ['method' => 'handleFailed', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 — 사용하지 않음.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // no-op
    }

    /**
     * 로그인 실패 시 카운트 증가 및 임계 도달 시 잠금 처리.
     *
     * @param  string  $email  실패한 로그인 이메일
     * @param  array   $context  IP/UA/시각 등 부가 정보
     */
    public function handleFailed(string $email, array $context = []): void
    {
        if (! (bool) g7_core_settings('security.login_attempt_enabled', true)) {
            return;
        }

        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            // 존재하지 않는 이메일은 IP 기반 throttle 미들웨어가 차단
            return;
        }

        $newCount = $this->userRepository->incrementFailedAttempts($user);

        $maxAttempts = (int) HookManager::applyFilters(
            'core.auth.max_login_attempts',
            (int) g7_core_settings('security.max_login_attempts', 5),
            $user
        );

        if ($maxAttempts <= 0 || $newCount < $maxAttempts) {
            return;
        }

        $lockoutMinutes = (int) HookManager::applyFilters(
            'core.auth.lockout_minutes',
            (int) g7_core_settings('security.login_lockout_time', 5),
            $user
        );

        $lockedUntil = $this->userRepository->lockAccount($user, $lockoutMinutes);

        HookManager::doAction('core.auth.account_locked', $user, array_merge($context, [
            'attempts' => $newCount,
            'locked_until' => $lockedUntil,
            'lockout_minutes' => $lockoutMinutes,
        ]));
    }
}
