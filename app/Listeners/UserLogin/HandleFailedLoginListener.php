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
    /**
     * 리스너를 생성합니다.
     *
     * @param  UserRepositoryInterface  $userRepository  로그인 시도 추적 컬럼을 갱신할 사용자 저장소
     */
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
        // sync: 실패 횟수와 잠금은 그 요청 안에서 바로 반영돼야 한다. 큐로 미루면 기본 설치
        // (QUEUE_CONNECTION=database)에서 워커가 없거나 밀린 동안 실패가 하나도 세지지 않아
        // 잠금이 걸리지 않고, 워커가 있어도 처리 지연 동안 들어온 무차별 대입을 막지 못한다.
        return [
            'core.auth.login_failed' => ['method' => 'handleFailed', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 — 사용하지 않음.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // no-op
    }

    /**
     * 로그인 실패 시 카운트 증가 및 임계 도달 시 잠금 처리.
     *
     * @param  string  $email  실패한 로그인 이메일
     * @param  array  $context  IP/UA/시각 등 부가 정보
     * @return void 반환값 없음
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

        // 비밀번호가 없는 회원(외부 인증 전용)은 비밀번호로 로그인할 수 없으므로 틀린 비밀번호를 세지 않는다 —
        // 세면 이메일만 아는 남이 그 계정을 잠가 소셜 로그인까지 막을 수 있다. 응답은 그대로(`invalid_credentials`)라
        // 비밀번호 부재가 드러나지 않는다.
        // 단 외부 자격 증명(사내 디렉터리 비밀번호) 실패는 센다(7.2.0) — 그 비밀번호는 실제 비밀이고, G7 이 먼저 잠가야
        // 디렉터리 계정 잠금(전사 로그인 불가)을 막는다.
        if (! $user->hasPassword() && ($context['external_credential'] ?? false) !== true) {
            return;
        }

        $lockoutMinutes = (int) HookManager::applyFilters(
            'core.auth.lockout_minutes',
            (int) g7_core_settings('security.login_lockout_time', 5),
            $user
        );

        // 실패는 차단 시간 안에서만 연속으로 센다 — 직전 실패가 그보다 오래됐으면 1 부터 다시.
        // 차단 시간 0(= 무기한 잠금)은 운영자가 가장 엄격한 정책을 고른 것이라 기간 없이 누적한다.
        $newCount = $this->userRepository->incrementFailedAttempts($user, $lockoutMinutes);

        $maxAttempts = (int) HookManager::applyFilters(
            'core.auth.max_login_attempts',
            (int) g7_core_settings('security.max_login_attempts', 5),
            $user
        );

        if ($maxAttempts <= 0 || $newCount < $maxAttempts) {
            return;
        }

        // 잠금 시간 0(= 무한대) 은 영구 잠금이라 해제 시각이 null 이다.
        $lockedUntil = $this->userRepository->lockAccount($user, $lockoutMinutes);

        // 잠긴 계정이 이미 받아 둔 토큰으로 계속 쓰지 못하게 전부 회수한다(7.2.0) — 잠금은 새 로그인만 막는다.
        $this->userRepository->deleteAllAccessTokens($user);

        HookManager::doAction('core.auth.account_locked', $user, array_merge($context, [
            'attempts' => $newCount,
            'locked_until' => $lockedUntil,
            'lockout_minutes' => $lockoutMinutes,
            'permanent' => $lockedUntil === null,
        ]));
    }
}
