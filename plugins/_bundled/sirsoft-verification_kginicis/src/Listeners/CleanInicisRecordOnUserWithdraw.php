<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
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
     * @param  InicisIdentityClaimRepositoryInterface|null  $claimRepository  동일인 선점표 (1.1.4, 미주입이면 컨테이너에서 해석)
     */
    public function __construct(
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
        protected readonly InicisIdentityLogQueryRepositoryInterface $logQueryRepository,
        protected readonly ?InicisIdentityClaimRepositoryInterface $claimRepository = null,
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
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return;
        }

        $userId = (int) $user->id;

        // record 파기와 log 익명화는 함께 성공해야 한다 — 한쪽만 끝나면
        // 파기된 것으로 보이는데 이력에 식별자가 남거나, 그 반대가 된다.
        DB::transaction(function () use ($userId) {
            $this->recordRepository->deleteByUserId($userId);
            $this->logQueryRepository->anonymizeUserId($userId);
            // 동일인 선점도 함께 푼다(1.1.4) — 탈퇴 후 같은 명의로 다시 가입하는 현행 허용을 유지한다.
            ($this->claimRepository ?? app(InicisIdentityClaimRepositoryInterface::class))->releaseByUserId($userId);
        });
    }
}
