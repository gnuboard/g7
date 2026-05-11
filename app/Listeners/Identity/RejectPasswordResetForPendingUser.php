<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * pending_verification 상태 계정의 비밀번호 재설정을 거부합니다.
 *
 * "먼저 가입 인증을 완료하세요" 안내를 위해 AuthService::resetPassword 진입 지점에 붙습니다.
 *
 * @since 7.0.0-beta.4
 */
class RejectPasswordResetForPendingUser implements HookListenerInterface
{
    /**
     * @param  UserRepositoryInterface  $userRepository  사용자 Repository
     */
    public function __construct(
        protected UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.before_reset_password' => [
                'method' => 'handle',
                'priority' => 5, // VerifyIdentityBeforePasswordReset 보다 먼저
                'sync' => true,
            ],
        ];
    }

    /**
     * core.auth.before_reset_password 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=User|array{email: string} payload
     * @return void
     *
     * @throws AuthorizationException 사용자가 PendingVerification 상태일 때
     */
    public function handle(...$args): void
    {
        $first = $args[0] ?? null;

        $user = null;
        if ($first instanceof User) {
            $user = $first;
        } elseif (is_array($first) && ! empty($first['email'])) {
            $user = $this->userRepository->findByEmail($first['email']);
        }

        if (! $user) {
            return;
        }

        if ($user->status === UserStatus::PendingVerification->value) {
            throw new AuthorizationException(__('auth.account_pending_verification'));
        }
    }
}
