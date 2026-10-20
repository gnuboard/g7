<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\ScopedPermissionEnforcement;

/**
 * 게시판 모듈의 스코프 강제 지점 원장 리스너
 *
 * 게시판 라우트는 `{slug}`·`{id}`·`{postId}` 를 써서 권한의 스코프 메타(`resource_route_key`)와 이름이
 * 맞는 모델 바인딩이 없다 — 미들웨어의 스코프 검사가 돌지 않는다. 그런 라우트마다 스코프를 실제로
 * 다시 판정하는 메서드를 이 원장에 적어 코어 필터 훅 `core.permission.scope_enforcement_ledger` 로 더한다.
 *
 * 감사(`ScopedPermissionEnforcement::auditRoutes`·`auditEnforcers`, 테스트
 * `tests/Feature/Security/BoardScopedRouteLedgerTest`)가 (1) 바인딩도 원장 등록도 없는 라우트,
 * (2) 원장에 적었는데 사라졌거나 스코프를 판정하지 않는 메서드를 잡는다. 라우트를 더하거나 강제 메서드
 * 이름을 바꾸면 여기를 함께 고친다. 런타임 동작은 바꾸지 않는다(기록과 감사 전용).
 */
class BoardScopeLedgerListener implements HookListenerInterface
{
    /**
     * 라우트 이름 접두사 (모듈 라우트 등록 시 붙는다)
     */
    private const R = 'api.modules.sirsoft-board.';

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.permission.scope_enforcement_ledger' => [
                'method' => 'addLedgerEntries',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (필터 훅은 getSubscribedHooks 의 메서드를 직접 호출한다).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 인터페이스 요구사항 충족용
    }

    /**
     * 원장에 게시판 모듈 항목을 병합합니다.
     *
     * @param  array  $ledger  지금까지 모인 원장
     * @return array 게시판 항목을 더한 원장
     */
    public function addLedgerEntries(array $ledger): array
    {
        return array_merge($ledger, self::entries());
    }

    /**
     * 게시판 모듈 원장 항목을 돌려줍니다.
     *
     * @return array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>}> 원장 항목
     */
    public static function entries(): array
    {
        $r = self::R;
        $svc = 'Modules\\Sirsoft\\Board\\Services\\';
        $repo = 'Modules\\Sirsoft\\Board\\Repositories\\';
        $ctl = 'Modules\\Sirsoft\\Board\\Http\\Controllers\\';
        $sup = 'Modules\\Sirsoft\\Board\\Support\\';
        $trait = 'Modules\\Sirsoft\\Board\\Traits\\ChecksBoardPermission::';

        return [
            // ── 게시판 단위 관리 권한(resource_route_key = board) ─────────────────────────
            // 블라인드·복원·일괄은 서비스 진입부 BoardManageScope, 수정·삭제 OR 경로는 ChecksBoardPermission 판정.
            // 신고 처리·자동 블라인드(triggerType report·auto_hide)는 reports.manage 관할이라 BoardManageScope 대상에서
            // 제외한다 — 서비스가 triggerType 이 있을 때 단언을 건너뛴다(신고 관리에는 범위가 없다)
            'sirsoft-board.{slug}.admin.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}admin.board.posts.bulk-destroy",
                    "{$r}admin.board.posts.bulk-blind",
                    "{$r}admin.board.posts.bulk-restore",
                    "{$r}admin.board.posts.destroy",
                    "{$r}admin.board.posts.blind",
                    "{$r}admin.board.posts.restore",
                    "{$r}admin.board.posts.comments.destroy",
                    "{$r}admin.board.posts.comments.blind",
                    "{$r}admin.board.posts.comments.restore",
                ],
                'paths' => [
                    "{$svc}PostService::blindPost",
                    "{$svc}PostService::restorePost",
                    "{$svc}PostService::bulkAction",
                    "{$svc}CommentService::blindComment",
                    "{$svc}CommentService::restoreComment",
                    "{$ctl}Admin\\PostController::authorizePostModification",
                    "{$ctl}Admin\\CommentController::authorizeCommentModification",
                    "{$sup}BoardManageScope::assert",
                    "{$trait}checkBoardPermission",
                    "{$trait}passesBoardScope",
                ],
            ],
            'sirsoft-board.{slug}.manager' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}boards.posts.update",
                    "{$r}boards.posts.destroy",
                    "{$r}boards.posts.comments.update",
                    "{$r}boards.posts.comments.destroy",
                ],
                'paths' => [
                    "{$ctl}User\\PostController::canModifyPost",
                    "{$svc}CommentService::canUpdate",
                    "{$svc}CommentService::canDelete",
                    "{$trait}checkBoardPermission",
                    "{$trait}passesBoardScope",
                ],
            ],

            // ── 게시글·댓글 읽기 (resource_route_key = post|comment) ──────────────────────
            'sirsoft-board.{slug}.admin.posts.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}admin.board.posts.index",
                    "{$r}admin.board.posts.show",
                ],
                'paths' => [
                    "{$repo}PostRepository::buildSortedPostList",
                    "{$repo}PostRepository::countNormalPosts",
                    "{$svc}PostService::getPost",
                    "{$svc}PostService::getPostWithCounts",
                    "{$repo}PostRepository::getAdjacentPosts",
                ],
            ],
            'sirsoft-board.{slug}.posts.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}boards.posts.index",
                    "{$r}boards.posts.show",
                    "{$r}boards.posts.navigation",
                    "{$r}boards.posts.verify-password",
                    "{$r}boards.posts.verify-thread-password",
                    "{$r}boards.posts.reports.store",
                    "{$r}boards.comments.reports.store",
                    "{$r}boards.posts.scrap.toggle",
                    "{$r}boards.posts.react",
                    "{$r}boards.comments.react",
                ],
                'paths' => [
                    "{$repo}PostRepository::buildSortedPostList",
                    "{$svc}PostService::getPostWithCounts",
                    "{$repo}PostRepository::getAdjacentPosts",
                    "{$sup}BoardReadScope::assertTargetReadable",
                    "{$sup}BoardReadScope::allowsItem",
                    "{$sup}BoardReadScope::resolve",
                    "{$sup}BoardReadScope::effectiveScope",
                ],
            ],
            'sirsoft-board.{slug}.comments.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => [
                    "{$r}boards.posts.comments.index",
                    "{$r}boards.comments.reports.store",
                    "{$r}boards.comments.react",
                ],
                'paths' => [
                    "{$repo}CommentRepository::getByPostId",
                    "{$repo}CommentRepository::buildVisibleCommentQuery",
                    "{$sup}BoardReadScope::assertTargetReadable",
                ],
            ],

            // ── 첨부 (resource_route_key = attachment) ──────────────────────────────────
            'sirsoft-board.{slug}.attachments.download' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => ["{$r}boards.attachment.download"],
                'paths' => ["{$svc}AttachmentService::download"],
            ],
            'sirsoft-board.{slug}.admin.attachments.download' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => ["{$r}admin.board.attachments.download"],
                'paths' => ["{$svc}AttachmentService::download"],
            ],
            'sirsoft-board.{slug}.attachments.upload' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}boards.attachments.upload",
                    "{$r}boards.attachments.reorder",
                    "{$r}boards.attachments.destroy",
                ],
                'paths' => ["{$svc}AttachmentService::assertAttachmentWithinScope"],
            ],
            'sirsoft-board.{slug}.admin.attachments.upload' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}admin.board.attachments.upload",
                    "{$r}admin.board.attachments.reorder",
                    "{$r}admin.board.attachments.destroy",
                ],
                'paths' => ["{$svc}AttachmentService::assertAttachmentWithinScope"],
            ],

            // ── 모듈 단위: 게시판 관리 (resource_route_key = board, {board} 바인딩 라우트는 자동 통과) ──
            'sirsoft-board.boards.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}admin.boards.index",
                    "{$r}admin.boards.form-data",
                    "{$r}admin.boards.show-by-slug",
                    "{$r}admin.reaction-types.index",
                ],
                'paths' => [
                    "{$repo}BoardRepository::paginate",
                    "{$svc}BoardService::getBoardBySlug",
                ],
            ],
            // 생성 권한은 범위로 좁힐 기존 자원이 없다 — 게시판 생성·게시판 유형 관리(유형은 게시판 소유자와 무관한 설정)
            'sirsoft-board.boards.create' => [
                'mode' => ScopedPermissionEnforcement::MODE_ROUTE,
                'routes' => [],
                'paths' => [],
                'exempt' => [
                    "{$r}admin.boards.store" => '새 게시판 생성 — 범위로 좁힐 기존 게시판이 없다',
                    "{$r}admin.board-types.*" => '게시판 유형은 개별 게시판·작성자와 무관한 전역 설정이다(소유자 없음)',
                ],
            ],
            'sirsoft-board.boards.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => [
                    "{$r}admin.boards.bulk-members",
                    "{$r}admin.boards.order",
                    "{$r}admin.settings.bulk-apply",
                ],
                'paths' => [
                    "{$svc}BoardService::bulkUpdateBoardMembers",
                    "{$svc}BoardService::reorderBoards",
                ],
            ],

            // ── 모듈 단위: 스크랩·반응 관리 (resource_route_key = scrap|reaction, 라우트는 {id}) ──
            'sirsoft-board.scraps.view' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => ["{$r}admin.scraps.index"],
                'paths' => ["{$repo}PostScrapRepository::paginateForAdmin"],
            ],
            'sirsoft-board.scraps.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => ["{$r}admin.scraps.destroy", "{$r}admin.scraps.bulk-destroy"],
                'paths' => [
                    "{$svc}PostScrapService::removeByAdmin",
                    "{$svc}PostScrapService::removeManyByAdmin",
                ],
            ],
            'sirsoft-board.reactions.view' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => ["{$r}admin.reactions.index"],
                'paths' => ["{$repo}ReactionRepository::paginateForAdmin"],
            ],
            'sirsoft-board.reactions.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => ["{$r}admin.reactions.destroy", "{$r}admin.reactions.bulk-destroy"],
                'paths' => [
                    "{$svc}ReactionService::removeByAdmin",
                    "{$svc}ReactionService::removeManyByAdmin",
                ],
            ],
            // 신고 관리(reports.*)는 범위를 선언하지 않는다 — 원장 대상이 아니다(#789)
        ];
    }
}
