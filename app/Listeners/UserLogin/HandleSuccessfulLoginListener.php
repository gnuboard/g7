<?php

namespace App\Listeners\UserLogin;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;

/**
 * 로그인 성공 리스너 — `core.auth.after_login` 액션 훅을 구독하여
 * 잠금 카운터/타임스탬프를 초기화합니다.
 *
 * `UpdateLastLoginListener` 와 분리한 이유: 잠금 도메인은 보안 환경설정
 * 토글에 종속되며, 책임이 다르므로 단일 책임 원칙으로 별도 리스너로
 * 분리합니다. 두 리스너 모두 priority 10 으로 동작하므로 순서 의존성 없음.
 */
class HandleSuccessfulLoginListener implements HookListenerInterface
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
            'core.auth.after_login' => ['method' => 'handleLogin', 'priority' => 20],
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
     * 로그인 성공 시 잠금 카운터 리셋.
     *
     * Repository 메서드는 멱등 — 모든 컬럼이 이미 초기 상태면 UPDATE 미발행.
     *
     * @param  User  $user  로그인한 사용자
     */
    public function handleLogin(User $user): void
    {
        $this->userRepository->resetLoginAttempts($user);
    }
}
