<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;

/**
 * IDV 검증 성공 시 pending_verification 사용자를 active 로 전환합니다.
 *
 * core.identity.after_verify 훅에 구독하며, purpose='signup' + user_id 존재 + 상태가
 * pending_verification 인 사용자만 대상으로 합니다.
 *
 * @since 7.0.0-beta.4
 */
class ActivateUserOnIdentityVerified implements HookListenerInterface
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
            'core.identity.after_verify' => [
                'method' => 'handle',
                'priority' => 15,
                'sync' => true, // VerificationResult DTO 를 받으므로 큐 직렬화 회피
            ],
        ];
    }

    /**
     * core.identity.after_verify 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=VerificationResult $result, [1]=IdentityVerificationLog $log
     * @return void
     */
    public function handle(...$args): void
    {
        $result = $args[0] ?? null;
        $log = $args[1] ?? null;

        if (! is_object($result) || ! ($result->success ?? false)) {
            return;
        }

        if (! $log instanceof IdentityVerificationLog) {
            return;
        }

        if ($log->purpose !== 'signup' || $log->user_id === null) {
            return;
        }

        $user = $this->userRepository->findById((int) $log->user_id);
        if (! $user || $user->status !== UserStatus::PendingVerification->value) {
            return;
        }

        $this->userRepository->update($user, ['status' => UserStatus::Active->value]);

        // 마케팅/분석 연동용 보조 훅
        HookManager::doAction('core.auth.after_register_activated', $user);
    }
}
