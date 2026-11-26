<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Board\Services\PostScrapService;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;

/**
 * 회원 삭제 시 게시판이 소유한 회원 데이터를 명시적으로 지우는 리스너
 *
 * 코어 `UserService::deleteUser()` 가 회원 삭제 트랜잭션 안에서 발화하는 `core.user.before_delete` 를 구독합니다.
 * 회원 행에 외래키(삭제 시 연쇄)로 걸린 게시판 테이블 세 개를 회원 행이 지워지기 전에 서비스 계층으로 지운다 —
 * DB CASCADE 에 기대지 않는다(외래키는 안전망으로만 둔다).
 *
 * - `board_user_notification_settings`: 회원별 게시판 알림 수신 설정
 * - `board_reactions`: 반응 — 지운 뒤 영향받은 게시글·댓글의 `reaction_counts` 를 남은 행으로 다시 집계
 * - `board_post_scraps`: 스크랩 — 지운 뒤 영향받은 게시글의 `scrap_count` 를 남은 행으로 다시 계산
 *
 * 작성자 컬럼(`created_by`·`author_id`·`processed_by`·`reporter_id` 등)은 외래키가 회원 삭제 시 NULL 로 바꾸는 쪽이라
 * 기록을 남기는 것이 의도다 — 여기서 다루지 않는다.
 *
 * 동기 실행(`sync`)이어야 한다 — 큐로 미루면 회원 행이 먼저 지워져 외래키 연쇄가 행을 지운 뒤라 집계를 맞출 근거가 사라진다.
 */
class UserDeleteCleanupListener implements HookListenerInterface
{
    /**
     * @param  UserNotificationSettingService  $notificationSettingService  회원 알림 설정 서비스
     * @param  ReactionService  $reactionService  반응 서비스
     * @param  PostScrapService  $scrapService  스크랩 서비스
     */
    public function __construct(
        private readonly UserNotificationSettingService $notificationSettingService,
        private readonly ReactionService $reactionService,
        private readonly PostScrapService $scrapService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, sync: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.before_delete' => ['method' => 'handleUserDelete', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑(handleUserDelete)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 삭제 직전의 회원이 남긴 게시판 알림 설정·반응·스크랩을 지웁니다.
     *
     * 실패하면 예외가 회원 삭제 트랜잭션을 되돌린다 — 회원만 지워지고 집계가 어긋난 채 남는 것보다 낫다.
     *
     * @param  User  $user  삭제 대상 회원
     * @return void 반환값 없음
     */
    public function handleUserDelete(User $user): void
    {
        $userId = (int) $user->id;

        if ($userId <= 0) {
            return;
        }

        $this->notificationSettingService->delete($userId);
        $this->reactionService->purgeForUser($userId);
        $this->scrapService->purgeForUser($userId);
    }
}
