<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Support\ReadHookContext;
use Modules\Sirsoft\Board\Support\SecretContentGate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 반응 서비스
 *
 * 게시글·댓글 반응의 등록/전환/해제를 처리합니다.
 * 확정 03(대상당 1개, 전환 시 대체), 07(로그인 필수 — 컨트롤러 미들웨어),
 * 08(본인 글 차단), 11(비활성 유형 차단)을 강제합니다.
 *
 * 마이페이지·관리자에서의 반응 해제(목록에서 지우기)도 이 서비스가 담당하며,
 * 어느 경로로 지우든 대상마다 카운트 캐시를 다시 집계합니다.
 */
class ReactionService
{
    /**
     * 게시글 대상 타입.
     */
    public const TARGET_POST = 'post';

    /**
     * 댓글 대상 타입.
     */
    public const TARGET_COMMENT = 'comment';

    /**
     * 관리자 반응 삭제의 스코프 기준 권한 식별자
     */
    private const ADMIN_MANAGE_PERMISSION = 'sirsoft-board.reactions.manage';

    /**
     * @param  ReactionRepositoryInterface  $reactionRepository  반응 이력 Repository
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 Repository
     * @param  PostRepositoryInterface  $postRepository  게시글 스코프 조회 Repository
     * @param  CommentRepositoryInterface  $commentRepository  댓글 스코프 조회 Repository
     * @param  UserRepositoryInterface  $userRepository  회원 조회 Repository (관리자 회원 필터)
     * @param  CacheInterface|null  $cache  모듈 캐시 드라이버 (최근 게시글 위젯 캐시 무효화용)
     */
    public function __construct(
        private readonly ReactionRepositoryInterface $reactionRepository,
        private readonly ReactionTypeRepositoryInterface $reactionTypeRepository,
        private readonly PostRepositoryInterface $postRepository,
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly ?CacheInterface $cache = null,
    ) {}

    /**
     * 게시글에 반응을 남깁니다 (등록/전환/해제 통합).
     *
     * 게시글이 대상 게시판 소속인지 스코프 검증을 먼저 수행합니다 (교차 접근 차단).
     *
     * - 기존 반응 없음 → 신규(INSERT), 해당 유형 +1
     * - 기존 반응이 같은 유형 → 해제(DELETE), 해당 유형 -1
     * - 기존 반응이 다른 유형 → 전환(UPDATE), 이전 유형 -1 · 신규 유형 +1
     *
     * 카운트 증감과 이력 쓰기는 단일 트랜잭션으로 원자 처리합니다 (확정 04·동시성).
     *
     * @param  int  $userId  반응한 사용자 ID
     * @param  Board  $board  대상 게시판 (컨트롤러가 slug 로 해석 후 전달)
     * @param  int  $postId  대상 게시글 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return array{action: string, reaction_type_id: int|null, reaction_counts: array<string, int>}
     *
     * @throws PostNotFoundException 게시글이 이 게시판 소속이 아니거나 미존재
     * @throws ReactionNotAllowedException 반응 비활성 게시판 / 비활성 유형 / 본인 글
     */
    public function react(int $userId, Board $board, int $postId, int $reactionTypeId): array
    {
        // 게시글이 이 게시판 소속인지 스코프 검증 (교차 접근 차단)
        $post = $this->postRepository->findByBoardId($board->id, $postId);

        if ($post === null) {
            throw new PostNotFoundException($postId);
        }

        // 반응 기능 off (확정 07 전제)
        if (! $board->use_reaction) {
            throw ReactionNotAllowedException::disabled();
        }

        // 본인 글 반응 차단 (확정 08)
        if ((int) $post->user_id === $userId) {
            throw ReactionNotAllowedException::selfPost();
        }

        // 비밀글 반응 차단 — 본문을 열 수 없는 사용자는 반응할 수 없고, 열 수 있으면 반응할 수 있다.
        // 댓글·신고와 같은 SecretContentGate 판정을 쓴다 — 비밀번호 인증(열람 토큰)·스레드 경로 규칙·
        // 비밀글 조회 스코프가 반응에도 똑같이 적용된다.
        $post->setRelation('board', $board);
        if ($post->is_secret && ! app(SecretContentGate::class)->canView($post)) {
            throw ReactionNotAllowedException::secretDenied();
        }

        $this->assertReactionTypeUsable($board, $reactionTypeId);

        return $this->reactToTarget($userId, $board, $post, self::TARGET_POST, (int) $post->id, $reactionTypeId);
    }

    /**
     * 댓글에 반응을 남깁니다 (등록/전환/해제 통합).
     *
     * 게시글 반응과 같은 규약이되 게시판이 `use_reaction` 과 `use_comment_reaction` 을
     * 모두 켰을 때만 허용합니다 (결정 R9 — 댓글 반응은 하위 토글).
     * 비회원 댓글(user_id NULL)은 본인 판정이 불가능하므로 셀프 차단을 걸지 않습니다.
     *
     * @param  int  $userId  반응한 사용자 ID
     * @param  Board  $board  대상 게시판
     * @param  int  $commentId  대상 댓글 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return array{action: string, reaction_type_id: int|null, reaction_counts: array<string, int>}
     *
     * @throws ModelNotFoundException 댓글이 이 게시판 소속이 아니거나 미존재
     * @throws PostNotFoundException 부모 게시글이 사라진 경우
     * @throws ReactionNotAllowedException 반응·댓글반응 비활성 / 비활성 유형 / 본인 댓글 / 비밀글
     */
    public function reactToComment(int $userId, Board $board, int $commentId, int $reactionTypeId): array
    {
        $comment = $this->commentRepository->findByBoardId($board->id, $commentId);

        if ($comment === null) {
            throw (new ModelNotFoundException)->setModel(Comment::class, [$commentId]);
        }

        if (! $board->use_reaction || ! $board->use_comment_reaction) {
            throw ReactionNotAllowedException::disabled();
        }

        // 삭제·블라인드된 댓글에는 새 반응을 남길 수 없다 (기존 반응 해제는 마이페이지 몫).
        if ($comment->deleted_at !== null || $comment->status !== PostStatus::Published) {
            throw ReactionNotAllowedException::targetUnavailable();
        }

        if ($comment->user_id !== null && (int) $comment->user_id === $userId) {
            throw ReactionNotAllowedException::selfComment();
        }

        $post = $this->postRepository->findByBoardId($board->id, (int) $comment->post_id);

        if ($post === null) {
            throw new PostNotFoundException((int) $comment->post_id);
        }

        // 비밀글의 댓글은 글 본문을, 비밀 댓글은 그 댓글 본문을 열 수 있어야 반응할 수 있다.
        // 판정은 댓글 목록과 같은 SecretContentGate — 글 작성자·비밀번호 인증자·경로 규칙 앵커·
        // 부모 댓글 작성자·권한자가 각자 볼 수 있는 만큼만 반응한다.
        $post->setRelation('board', $board);
        $gate = app(SecretContentGate::class);
        if (($post->is_secret && ! $gate->canView($post))
            || ($comment->is_secret && ! $gate->canViewSecretComment($comment, $post))) {
            throw ReactionNotAllowedException::secretDenied();
        }

        $this->assertReactionTypeUsable($board, $reactionTypeId);

        return $this->reactToTarget($userId, $board, $comment, self::TARGET_COMMENT, (int) $comment->id, $reactionTypeId);
    }

    /**
     * 회원 본인의 반응 목록을 조회합니다 (마이페이지).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, mixed>  $filters  필터
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 반응 목록
     */
    public function paginateForUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        $hookContext = ReadHookContext::make(['subject_user_id' => $userId, 'filters' => $filters]);

        HookManager::doAction('sirsoft-board.reaction.before_user_list', $hookContext);

        $reactions = $this->reactionRepository->paginateByUser($userId, $filters, $perPage);

        $reactions = HookManager::applyFilters('sirsoft-board.reaction.filter_user_list_result', $reactions, $hookContext);

        HookManager::doAction('sirsoft-board.reaction.after_user_list', $reactions, $hookContext);

        return $reactions;
    }

    /**
     * 관리자 반응 관리 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터
     * @param  int  $perPage  페이지당 건수
     * @return Paginator 반응 목록
     */
    public function paginateForAdmin(array $filters, int $perPage)
    {
        return $this->reactionRepository->paginateForAdmin($filters, $perPage);
    }

    /**
     * 본인 반응 1건을 해제합니다 (마이페이지).
     *
     * 반응이 꺼진 게시판이라도 해제는 허용한다 (결정 R11) — 남긴 기록을 회수하는
     * 행위는 게시판 설정과 무관하게 회원의 권리다.
     *
     * @param  int  $id  반응 ID
     * @param  int  $userId  소유자 ID
     * @return bool 해제 여부 (없거나 남의 것이면 false)
     */
    public function removeOwn(int $id, int $userId): bool
    {
        $removed = DB::transaction(function () use ($id, $userId) {
            $reaction = $this->reactionRepository->deleteByIdAndUser($id, $userId);

            if ($reaction !== null) {
                $this->recalculateAfterRemoval($reaction);
            }

            return $reaction;
        });

        if ($removed === null) {
            return false;
        }

        $this->fireRemovalHook($removed, $userId);

        return true;
    }

    /**
     * 본인 반응을 일괄 해제합니다 (마이페이지).
     *
     * @param  array<int, int>  $ids  반응 ID 목록
     * @param  int  $userId  소유자 ID
     * @return int 실제로 해제된 건수
     */
    public function removeManyOwn(array $ids, int $userId): int
    {
        $removed = DB::transaction(function () use ($ids, $userId) {
            $reactions = $this->reactionRepository->deleteManyByIdsAndUser($ids, $userId);

            foreach ($reactions as $reaction) {
                $this->recalculateAfterRemoval($reaction);
            }

            return $reactions;
        });

        foreach ($removed as $reaction) {
            $this->fireRemovalHook($reaction, $userId);
        }

        return $removed->count();
    }

    /**
     * 관리자가 반응 1건을 삭제합니다.
     *
     * @param  int  $id  반응 ID
     * @return bool 삭제 여부 (없으면 false)
     */
    public function removeByAdmin(int $id): bool
    {
        // 반응 관리 권한의 스코프(본인만·소유역할)를 다시 본다 — 라우트가 {id} 라 미들웨어가 보지 않는다
        $target = $this->reactionRepository->findById($id);
        if ($target !== null && ! PermissionHelper::checkScopeAccess($target, self::ADMIN_MANAGE_PERMISSION)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        $removed = DB::transaction(function () use ($id) {
            $reaction = $this->reactionRepository->deleteById($id);

            if ($reaction !== null) {
                $this->recalculateAfterRemoval($reaction);
            }

            return $reaction;
        });

        if ($removed === null) {
            return false;
        }

        // 훅의 $userId 는 반응 소유자다 (스크랩 관례) — 활동 로그의 actor 는
        // 리스너가 로그인 관리자에서 따로 읽는다.
        $this->fireRemovalHook($removed, (int) $removed->user_id);

        return true;
    }

    /**
     * 관리자가 반응을 일괄 삭제합니다.
     *
     * @param  array<int, int>  $ids  반응 ID 목록
     * @return int 삭제된 건수
     */
    public function removeManyByAdmin(array $ids): int
    {
        // 범위 밖 반응이 하나라도 섞이면 아무것도 지우지 않고 전량 거부한다
        $targets = $this->reactionRepository->findManyByIds($ids);
        if (count(PermissionHelper::filterByScope($targets, self::ADMIN_MANAGE_PERMISSION)) !== $targets->count()) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        $removed = DB::transaction(function () use ($ids) {
            $reactions = $this->reactionRepository->deleteManyByIds($ids);

            foreach ($reactions as $reaction) {
                $this->recalculateAfterRemoval($reaction);
            }

            return $reactions;
        });

        foreach ($removed as $reaction) {
            $this->fireRemovalHook($reaction, (int) $reaction->user_id);
        }

        return $removed->count();
    }

    /**
     * 회원 uuid 를 목록 필터용 user_id 로 해석합니다.
     *
     * 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다 — 필터를 무시하고 전체를 보여주면
     * "이 회원의 반응" 을 요청한 화면에 남의 반응이 뜬다.
     *
     * @param  string  $uuid  회원 uuid
     * @return int 회원 ID (없으면 -1)
     */
    public function resolveMemberFilterId(string $uuid): int
    {
        return (int) ($this->userRepository->findByUuid($uuid)?->id ?? -1);
    }

    /**
     * 회원의 반응 요약을 반환합니다 (관리자 회원 상세 탭).
     *
     * @param  int  $userId  사용자 ID
     * @return array{count: int, post_count: int, comment_count: int, latest_reacted_at: mixed}
     */
    public function summaryForUser(int $userId): array
    {
        return $this->reactionRepository->summaryByUser($userId);
    }

    /**
     * 대상 타입과 무관한 반응 등록/전환/해제 본문.
     *
     * @param  int  $userId  사용자 ID
     * @param  Board  $board  대상 게시판
     * @param  Post|Comment  $target  대상 모델 (훅 인자)
     * @param  string  $targetType  대상 타입
     * @param  int  $targetId  대상 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return array{action: string, reaction_type_id: int|null, reaction_counts: array<string, int>}
     */
    private function reactToTarget(
        int $userId,
        Board $board,
        Post|Comment $target,
        string $targetType,
        int $targetId,
        int $reactionTypeId,
    ): array {
        HookManager::doAction('sirsoft-board.reaction.before_react', $userId, $target, $reactionTypeId, $targetType);

        $result = DB::transaction(function () use ($userId, $board, $targetType, $targetId, $reactionTypeId) {
            // 이 대상에 대한 반응 처리를 직렬화 — 짧은 시간에 연속 전송된 요청이
            // 서버 도착 순서와 다르게 처리되어도(A→B→C 전송, B→A→C 처리 등) existing
            // 조회부터 upsert/delete·카운트 재집계까지가 이 잠금 하나로 묶여, 이후
            // 로직이 항상 "이 트랜잭션 차례가 됐을 때의 실제 최신 상태"를 기준으로
            // 판단하도록 보장한다. 잠금 없이 existing 을 먼저 읽으면 다른 트랜잭션이
            // 그 사이 상태를 바꿔도 반영되지 않아, 이미 지워진 반응을 다시 지우거나
            // 캐시 카운트가 실제 반응 행 수와 어긋나는 결함으로 이어진다.
            //
            // 스코프 검증 통과 직후·락 획득 시점 사이에 대상이 하드 삭제되는 레이스에서는
            // findOrFail() 이 ModelNotFoundException 을 던진다. 게시글은 컨트롤러가 아는
            // PostNotFoundException 으로 변환하고, 댓글은 컨트롤러가 404 로 매핑한다.
            try {
                $this->reactionRepository->lockTargetForReaction($targetType, $targetId);
            } catch (ModelNotFoundException $e) {
                if ($targetType === self::TARGET_POST) {
                    throw new PostNotFoundException($targetId);
                }

                throw $e;
            }

            $existing = $this->reactionRepository->findByUserAndTarget($userId, $targetType, $targetId);

            // 같은 유형 재요청 → 해제
            if ($existing !== null && (int) $existing->reaction_type_id === $reactionTypeId) {
                $this->reactionRepository->delete($existing);
                $counts = $this->reactionRepository->recalculateReactionCounts($targetType, $targetId);

                return ['action' => 'remove', 'reaction_type_id' => null, 'reaction_counts' => $counts];
            }

            $action = $existing !== null ? 'change' : 'add';

            // 다른 유형 → 전환 (이전 -1, 신규 +1) / 없음 → 신규 등록
            $this->reactionRepository->upsert($userId, $targetType, $targetId, $reactionTypeId, $board->id);
            $counts = $this->reactionRepository->recalculateReactionCounts($targetType, $targetId);

            return ['action' => $action, 'reaction_type_id' => $reactionTypeId, 'reaction_counts' => $counts];
        });

        if ($targetType === self::TARGET_POST) {
            $this->forgetPostListCaches();
        }

        HookManager::doAction(
            'sirsoft-board.reaction.after_react',
            $userId,
            $target,
            $reactionTypeId,
            $result['action'],
            $targetType,
        );

        return $result;
    }

    /**
     * 요청 유형이 존재하는 활성 유형이면서 게시판이 켠 유형인지 확인합니다 (확정 11).
     *
     * @param  Board  $board  대상 게시판
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return void
     *
     * @throws ReactionNotAllowedException 미존재·비활성·게시판 미사용 유형
     */
    private function assertReactionTypeUsable(Board $board, int $reactionTypeId): void
    {
        $requestedType = $this->reactionTypeRepository->findById($reactionTypeId);
        $activeCodes = $board->active_reaction_types ?? [];

        if ($requestedType === null
            || ! $requestedType->is_active
            || ! in_array($requestedType->code, $activeCodes, true)) {
            throw ReactionNotAllowedException::inactiveType();
        }
    }

    /**
     * 반응 행 삭제 후 대상의 카운트 캐시를 다시 집계합니다.
     *
     * 대상이 이미 하드 삭제됐으면 재집계할 캐시 자체가 없으므로 조용히 넘어간다
     * (반응 행만 사라지면 되고, 없는 대상의 카운트는 의미가 없다).
     *
     * @param  Reaction  $reaction  삭제된 반응
     * @return void
     */
    private function recalculateAfterRemoval(Reaction $reaction): void
    {
        if ($reaction->target_type === self::TARGET_POST) {
            $this->forgetPostListCaches();
        }

        try {
            $this->reactionRepository->recalculateReactionCounts(
                $reaction->target_type,
                (int) $reaction->target_id,
            );
        } catch (ModelNotFoundException $e) {
            // 대상이 하드 삭제된 반응 — 재집계 대상 없음
        }
    }

    /**
     * 게시글 반응 수가 실리는 캐시 목록(홈 최근 게시글 등 `board-posts` 태그)을 비웁니다.
     *
     * 최신글 위젯은 `board-posts` 태그로 하루 동안 캐시된다. 게시글 작성·삭제만 이 태그를
     * 비우면 반응을 남기거나 지워도 위젯의 반응 수가 그대로 남는다. 댓글 반응은 게시글의
     * 반응 합계를 바꾸지 않으므로 호출하지 않는다.
     *
     * @return void 반환값 없음
     */
    private function forgetPostListCaches(): void
    {
        $this->cache?->flushTags(['board-posts']);
    }

    /**
     * 반응 해제 훅을 발화합니다.
     *
     * 대상이 이미 사라졌을 수 있어 모델 인자는 null 일 수 있다 — 리스너가
     * loggable_type/loggable_id 를 인자에서 직접 받도록 타입·ID 를 뒤에 붙인다.
     *
     * @param  Reaction  $reaction  삭제된 반응
     * @param  int  $userId  훅에 실을 사용자 ID (반응 소유자)
     * @return void
     */
    private function fireRemovalHook(Reaction $reaction, int $userId): void
    {
        $target = $reaction->target_type === self::TARGET_COMMENT
            ? Comment::withTrashed()->find($reaction->target_id)
            : Post::withTrashed()->find($reaction->target_id);

        HookManager::doAction(
            'sirsoft-board.reaction.after_react',
            $userId,
            $target,
            (int) $reaction->reaction_type_id,
            'remove',
            $reaction->target_type,
            (int) $reaction->target_id,
        );
    }
}
