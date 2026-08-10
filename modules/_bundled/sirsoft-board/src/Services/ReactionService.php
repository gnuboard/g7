<?php

namespace Modules\Sirsoft\Board\Services;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 반응 서비스
 *
 * 게시글 반응의 등록/전환/해제를 단일 진입점(`react()`)으로 처리합니다.
 * 확정 03(글당 1개, 전환 시 대체), 07(로그인 필수 — 컨트롤러 미들웨어),
 * 08(본인 글 차단), 11(비활성 유형 차단)을 강제합니다.
 */
class ReactionService
{
    use ChecksBoardPermission;

    /**
     * 현재 지원하는 반응 대상 타입 (확정 10 — 게시글만, 향후 comment 확장 가능).
     */
    private const TARGET_POST = 'post';

    /**
     * @param  ReactionRepositoryInterface  $reactionRepository  반응 이력 Repository
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 Repository
     * @param  PostRepositoryInterface  $postRepository  게시글 스코프 조회 Repository
     */
    public function __construct(
        private readonly ReactionRepositoryInterface $reactionRepository,
        private readonly ReactionTypeRepositoryInterface $reactionTypeRepository,
        private readonly PostRepositoryInterface $postRepository,
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

        // 비밀글 반응 차단 — 본문 열람 권한이 없는 사용자는 반응 불가.
        // 신고(PostResource::canViewSecretContent)와 동일한 판정을 재사용해,
        // 본문을 못 보는 사용자가 반응만 남기는 우회를 막는다.
        // 작성자 본인은 위에서 이미 차단되므로 여기서는 게시판별 비밀글 열람 권한만 본다.
        if ($post->is_secret && ! $this->canReactToSecretPost($board->slug)) {
            throw ReactionNotAllowedException::secretDenied();
        }

        // 요청 유형이 존재하는 활성 유형이면서 게시판이 켠 유형인지 확인 (확정 11)
        $requestedType = $this->reactionTypeRepository->findById($reactionTypeId);
        $activeCodes = $board->active_reaction_types ?? [];

        if ($requestedType === null
            || ! $requestedType->is_active
            || ! in_array($requestedType->code, $activeCodes, true)) {
            throw ReactionNotAllowedException::inactiveType();
        }

        HookManager::doAction('sirsoft-board.reaction.before_react', $userId, $post, $reactionTypeId);

        $result = DB::transaction(function () use ($userId, $board, $post, $reactionTypeId) {
            // 이 게시글에 대한 반응 처리를 직렬화 — 짧은 시간에 연속 전송된 요청이
            // 서버 도착 순서와 다르게 처리되어도(A→B→C 전송, B→A→C 처리 등) existing
            // 조회부터 upsert/delete·카운트 재집계까지가 이 잠금 하나로 묶여, 이후
            // 로직이 항상 "이 트랜잭션 차례가 됐을 때의 실제 최신 상태"를 기준으로
            // 판단하도록 보장한다. 잠금 없이 existing 을 먼저 읽으면 다른 트랜잭션이
            // 그 사이 상태를 바꿔도 반영되지 않아, 이미 지워진 반응을 다시 지우거나
            // 캐시 카운트가 실제 반응 행 수와 어긋나는 결함으로 이어진다.
            //
            // 스코프 검증 통과 직후·락 획득 시점 사이에 게시글이 삭제되는 레이스에서는
            // findOrFail() 이 ModelNotFoundException 을 던진다. 컨트롤러는 이 예외를
            // 모르므로 그대로 두면 일반 500 으로 새어나가 사용자가 원인을 알 수 없다 —
            // 이미 위에서 존재를 확인한 게시글이 사라진 것과 같은 의미이므로 동일하게
            // PostNotFoundException 으로 변환한다.
            try {
                $this->reactionRepository->lockPostForReaction($post->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                throw new PostNotFoundException($post->id);
            }

            $existing = $this->reactionRepository->findByUserAndTarget(
                $userId,
                self::TARGET_POST,
                $post->id,
            );

            // 같은 유형 재요청 → 해제
            if ($existing !== null && (int) $existing->reaction_type_id === $reactionTypeId) {
                $this->reactionRepository->delete($existing);
                $counts = $this->reactionRepository->recalculatePostReactionCounts($post->id);

                return ['action' => 'remove', 'reaction_type_id' => null, 'reaction_counts' => $counts];
            }

            // 다른 유형 → 전환 (이전 -1, 신규 +1)
            if ($existing !== null) {
                $this->reactionRepository->upsert(
                    $userId,
                    self::TARGET_POST,
                    $post->id,
                    $reactionTypeId,
                    $board->id,
                );
                $counts = $this->reactionRepository->recalculatePostReactionCounts($post->id);

                return ['action' => 'change', 'reaction_type_id' => $reactionTypeId, 'reaction_counts' => $counts];
            }

            // 신규 등록
            $this->reactionRepository->upsert(
                $userId,
                self::TARGET_POST,
                $post->id,
                $reactionTypeId,
                $board->id,
            );
            $counts = $this->reactionRepository->recalculatePostReactionCounts($post->id);

            return ['action' => 'add', 'reaction_type_id' => $reactionTypeId, 'reaction_counts' => $counts];
        });

        HookManager::doAction(
            'sirsoft-board.reaction.after_react',
            $userId,
            $post,
            $reactionTypeId,
            $result['action'],
        );

        return $result;
    }

    /**
     * 비밀글에 반응할 수 있는 열람 권한이 있는지 확인합니다.
     *
     * 반응은 사용자(User) 페이지 전용 기능이므로 게시판별 비밀글 읽기 권한
     * (posts.read-secret) 또는 게시판 매니저 권한만 인정합니다. 작성자 본인은
     * 호출 이전에 이미 selfPost 로 차단되므로 여기서는 고려하지 않습니다.
     * `PostResource::canViewSecretContent` 의 게시판 권한 판정과 동일한 기준입니다.
     *
     * @param  string  $slug  대상 게시판 슬러그
     * @return bool 비밀글 반응 가능 여부
     */
    private function canReactToSecretPost(string $slug): bool
    {
        return $this->checkBoardPermission($slug, 'posts.read-secret', PermissionType::User)
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
    }
}
