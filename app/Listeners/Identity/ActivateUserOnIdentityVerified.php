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

        // 상태에 딸린 시각 칸의 짝은 Enum 한 곳(`UserStatus::transitionTimestamps()`)이
        // 소유한다 — 관리자 생성·수정·일괄 경로와 로그인 해제 경로가 모두 그 표를 읽는다.
        // 여기만 `status` 를 단독으로 쓰면 「→ Active」 다섯 경로 중 하나가 어휘에서
        // 이탈하고, 낡은 `dormant_notified_at` 이 남아 재안내 쿼리(`whereNull`)에서는
        // 빠지고 전환 쿼리(`whereNotNull` + 기한)에는 잡히는 상태가 만들어진다.
        $this->userRepository->update($user, array_merge(
            ['status' => UserStatus::Active->value],
            UserStatus::Active->transitionTimestamps(),
        ));

        // 마케팅/분석 연동용 보조 훅
        HookManager::doAction('core.auth.after_register_activated', $user);
    }
}
