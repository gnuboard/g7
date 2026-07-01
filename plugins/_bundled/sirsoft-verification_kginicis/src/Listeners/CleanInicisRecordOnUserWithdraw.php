<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;

/**
 * 사용자 탈퇴 시 이니시스 본인확인 record 를 즉시 파기한다 (PIPC 준수).
 *
 * 코어 `core.user.after_withdraw` 훅을 구독 — 탈퇴 트랜잭션 완료 후 호출되므로
 * 탈퇴 실패 시 PII 가 우연히 남는 것을 방지한다 (before 훅 미사용 사유).
 *
 * Hook 시그니처 (코어 `UserService::withdraw()`):
 *   HookManager::doAction('core.user.after_withdraw', $user);
 *
 * @since 1.0.0-beta.1
 */
class CleanInicisRecordOnUserWithdraw implements HookListenerInterface
{
    /**
     * @param  InicisIdentityRecordRepositoryInterface  $recordRepository  본 plugin record Repository
     * @param  InicisIdentityLogQueryRepositoryInterface  $logQueryRepository  log anonymize 용 Repository
     */
    public function __construct(
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
        protected readonly InicisIdentityLogQueryRepositoryInterface $logQueryRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_withdraw' => [
                'method' => 'handle',
                'priority' => 50,
                'sync' => true,
            ],
        ];
    }

    /**
     * 훅 진입점.
     *
     * @param  mixed  ...$args  [$user]
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return;
        }

        $userId = (int) $user->id;

        $this->recordRepository->deleteByUserId($userId);
        $this->logQueryRepository->anonymizeUserId($userId);
    }
}
