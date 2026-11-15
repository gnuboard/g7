<?php

namespace App\Listeners\UserLogin;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\UnknownEmailLockService;

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
     * @param  UnknownEmailLockService  $unknownEmailLock  가입되지 않은 주소의 실패 횟수·잠금 흉내
     */
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UnknownEmailLockService $unknownEmailLock,
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
            // 그 주소로 가입이 끝나면 가입 전 실패 기록을 지운다 — 가입 직후 로그인이 막히지 않게.
            'core.auth.after_register' => ['method' => 'handleRegistered', 'priority' => 10, 'sync' => true],
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
     * 가입이 끝난 주소의 가입 전 실패 기록을 지웁니다.
     *
     * @param  mixed  $user  가입한 사용자 (훅 첫 인자)
     * @param  mixed  ...$rest  훅의 나머지 인자
     * @return void 반환값 없음
     */
    public function handleRegistered(mixed $user = null, mixed ...$rest): void
    {
        if ($user instanceof User && is_string($user->email) && $user->email !== '') {
            $this->unknownEmailLock->forget($user->email);
        }
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

        // 로그인 2단계 실패처럼 대상 회원이 확정된 실패는 id 로 찾는다 — 그 사이 이메일이 비었거나 바뀐 회원이
        // 미가입 주소 경로로 새지 않게 한다(7.1.6). 회원을 못 찾으면 아무것도 세지 않는다.
        if (isset($context['user_id'])) {
            $user = $this->userRepository->findById((int) $context['user_id']);

            if ($user === null) {
                return;
            }
        } else {
            $user = $this->userRepository->findByEmail($email);
        }

        if ($user === null) {
            // 가입되지 않은 주소도 같은 기준으로 세어 같은 횟수 뒤에 같은 잠금 응답이 나가게 한다 —
            // 가입 계정만 잠금 응답을 받으면 그 차이로 가입 여부를 알 수 있다. 회원 데이터는 건드리지 않고
            // 잠금 훅·알림도 내지 않는다(UnknownEmailLockService).
            $this->unknownEmailLock->recordFailure($email);

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

        HookManager::doAction('core.auth.account_locked', $user, array_merge($context, [
            'attempts' => $newCount,
            'locked_until' => $lockedUntil,
            'lockout_minutes' => $lockoutMinutes,
            'permanent' => $lockedUntil === null,
        ]));
    }
}
