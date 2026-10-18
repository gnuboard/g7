<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;

/**
 * 사용자 탈퇴 시 KCP 본인확인 record 를 즉시 파기한다 (PIPC 준수).
 *
 * 코어 `core.user.after_withdraw` 훅을 구독 — 탈퇴 트랜잭션 완료 후 호출되므로 탈퇴가
 * 실패한 경우 PII 가 잘못 파기되는 것을 방지한다 (before 훅 미사용 사유).
 *
 * @since 1.0.0
 */
class CleanKcpRecordOnUserWithdraw implements HookListenerInterface
{
    /**
     * @param  KcpIdentityRecordRepositoryInterface  $recordRepository  본 plugin record Repository
     * @param  KcpIdentityLogQueryRepositoryInterface  $logQueryRepository  로그 익명화 Repository
     */
    public function __construct(
        protected readonly KcpIdentityRecordRepositoryInterface $recordRepository,
        protected readonly KcpIdentityLogQueryRepositoryInterface $logQueryRepository,
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
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return;
        }

        $userId = (int) $user->id;
        if ($userId <= 0) {
            return;
        }

        // record 파기와 log 익명화는 함께 성공해야 한다 — 한쪽만 끝나면
        // 파기된 것으로 보이는데 이력에 식별자가 남거나, 그 반대가 된다.
        DB::transaction(function () use ($userId) {
            $this->recordRepository->deleteByUserId($userId);
            $this->logQueryRepository->anonymizeUserId($userId);
        });
    }
}
