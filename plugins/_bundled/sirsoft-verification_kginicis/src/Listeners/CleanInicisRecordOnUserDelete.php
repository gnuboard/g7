<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;

/**
 * 관리자 사용자 삭제 시 이니시스 본인확인 record 를 즉시 파기한다 (PIPC 준수).
 *
 * 코어 `core.user.before_delete` 훅을 구독 — hard delete 직전에 호출되므로 User 모델이
 * 아직 존재한다. inicis_identity_records.user_id 는 users(id) FK 를 가지며 CASCADE 미설정
 * 이므로(database-guide 명시 삭제 규정), 코어가 users 행을 삭제하기 전에 본 record 를 먼저
 * 파기해야 FK 제약 위반(1451) 없이 사용자 삭제가 완료된다.
 *
 * before_delete 시점 정리 이유: FK 제약 회피 + cascade race 회피
 * (참조: Plugins\Sirsoft\Gdpr\Listeners\GdprUserDeleteListener 동일 패턴).
 *
 * Hook 시그니처 (코어 `UserService::deleteUser()`):
 *   HookManager::doAction('core.user.before_delete', $user);
 *
 * @since 1.0.0-beta.1
 */
class CleanInicisRecordOnUserDelete implements HookListenerInterface
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
     * 코어 `core.user.before_delete` 는 삭제될 User 모델을 그대로 전달한다.
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

        $this->recordRepository->deleteByUserId($userId);
        $this->logQueryRepository->anonymizeUserId($userId);
    }
}
