<?php

namespace App\Listeners\UserLogin;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;

/**
 * 사용자 로그인 시 last_login_at을 업데이트하는 리스너
 */
class UpdateLastLoginListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_login' => ['method' => 'handleLogin', 'priority' => 10],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 - handleLogin 사용
    }

    /**
     * 사용자 로그인 시 last_login_at을 업데이트합니다.
     *
     * @param  User  $user  로그인한 사용자
     */
    public function handleLogin(User $user): void
    {
        // last_login_at 업데이트
        $this->updateLastLoginAt($user);
    }

    /**
     * 사용자의 last_login_at 필드를 현재 시간으로 업데이트합니다.
     *
     * @param User $user
     * @return void
     */
    private function updateLastLoginAt(User $user): void
    {
        $user->update([
            'last_login_at' => now()
        ]);
    }
}
