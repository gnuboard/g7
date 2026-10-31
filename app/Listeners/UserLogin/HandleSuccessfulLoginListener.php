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
        // sync: 실패 횟수 초기화는 로그인과 같은 요청 안에서 끝나야 한다. 큐로 미루면 성공 직후의
        // 실패가 초기화 전 카운터에 더해져 잠금이 앞당겨지거나, 워커가 없는 사이트에서는 영영 초기화되지 않는다.
        return [
            'core.auth.after_login' => ['method' => 'handleLogin', 'priority' => 20, 'sync' => true],
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
     * 로그인 성공 시 잠금 카운터 리셋.
     *
     * Repository 메서드는 멱등 — 모든 컬럼이 이미 초기 상태면 UPDATE 미발행.
     *
     * @param  User  $user  로그인한 사용자
     * @return void 반환값 없음
     */
    public function handleLogin(User $user): void
    {
        $this->userRepository->resetLoginAttempts($user);
    }
}
