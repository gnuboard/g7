<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;

/**
 * 관리자 사용자 삭제 시 KCP 본인확인 record 를 즉시 파기한다 (PIPC 준수).
 *
 * 코어 `core.user.before_delete` 훅을 구독 — hard delete 직전에 호출되므로 User 모델이 아직
 * 존재한다. nhnkcp_identity_records.user_id 는 users(id) FK 를 가지며 CASCADE 미설정이므로
 * (database-guide 명시 삭제 규정), 코어가 users 행을 삭제하기 전에 본 record 를 먼저 파기해야
 * FK 제약 위반 없이 사용자 삭제가 완료된다.
 *
 * @since 1.0.0
 */
class CleanKcpRecordOnUserDelete implements HookListenerInterface
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
            'core.user.before_delete' => [
                'method' => 'handle',
                'priority' => 50,
                'sync' => true,
            ],
        ];
    }

    /**
     * 훅 진입점.
     *
     * @param  mixed  ...$args  [$user] — 삭제 직전의 User 모델
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
