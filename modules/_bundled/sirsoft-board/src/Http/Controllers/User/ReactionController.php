<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Http\Requests\User\BulkDestroyMyReactionRequest;
use Modules\Sirsoft\Board\Http\Requests\User\IndexMyReactionRequest;
use Modules\Sirsoft\Board\Http\Requests\User\ReactPostRequest;
use Modules\Sirsoft\Board\Http\Resources\UserReactionCollection;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\ReactionService;

/**
 * 사용자용 반응 컨트롤러
 *
 * 게시글·댓글 반응(추천/비추천) 등록·전환·해제를 각각 하나의 엔드포인트로 처리하고,
 * 마이페이지 「내 반응」 목록 조회·해제를 제공합니다.
 */
class ReactionController extends AuthBaseController
{
    /**
     * ReactionController 생성자
     *
     * @param  ReactionService  $reactionService  반응 서비스
     * @param  BoardService  $boardService  게시판 서비스 (slug → 게시판 해석)
     */
    public function __construct(
        private ReactionService $reactionService,
        private BoardService $boardService,
    ) {
        parent::__construct();
    }

    /**
     * 게시글에 반응합니다 (등록/전환/해제 통합).
     *
     * @param  ReactPostRequest  $request  반응 요청 (reaction_type_id 검증)
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @return JsonResponse 반응 결과 응답
     */
    public function react(ReactPostRequest $request, string $slug, int $postId): JsonResponse
    {
        try {
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->notFound('sirsoft-board::messages.boards.error_404');
            }

            $result = $this->reactionService->react(
                (int) Auth::id(),
                $board,
                $postId,
                (int) $request->validated('reaction_type_id'),
            );

            $messageKey = match ($result['action']) {
                'change' => 'sirsoft-board::messages.reaction.change_success',
                'remove' => 'sirsoft-board::messages.reaction.remove_success',
                default => 'sirsoft-board::messages.reaction.add_success',
            };

            return $this->success($messageKey, [
                'action' => $result['action'],
                'my_reaction_type_id' => $result['reaction_type_id'],
                'reaction_counts' => $result['reaction_counts'],
            ]);
        } catch (PostNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.post.not_found');
        } catch (ReactionNotAllowedException $e) {
            return $this->error($e->getMessageKey(), 422, ['code' => 'reaction_not_allowed']);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.failed', 500, $e->getMessage());
        }
    }

    /**
     * 댓글에 반응합니다 (등록/전환/해제 통합).
     *
     * 게시글 반응과 같은 규약이며, 게시판이 use_reaction 과 use_comment_reaction 을
     * 모두 켰을 때만 허용됩니다 (설계 결정 R9).
     * ReactPostRequest 는 reaction_type_id 만 검증하므로 그대로 재사용합니다.
     *
     * @param  ReactPostRequest  $request  반응 요청 (reaction_type_id 검증)
     * @param  string  $slug  게시판 slug
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 반응 결과 응답
     */
    public function reactToComment(ReactPostRequest $request, string $slug, int $commentId): JsonResponse
    {
        try {
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->notFound('sirsoft-board::messages.boards.error_404');
            }

            $result = $this->reactionService->reactToComment(
                (int) Auth::id(),
                $board,
                $commentId,
                (int) $request->validated('reaction_type_id'),
            );

            $messageKey = match ($result['action']) {
                'change' => 'sirsoft-board::messages.reaction.change_success',
                'remove' => 'sirsoft-board::messages.reaction.remove_success',
                default => 'sirsoft-board::messages.reaction.add_success',
            };

            return $this->success($messageKey, [
                'action' => $result['action'],
                'my_reaction_type_id' => $result['reaction_type_id'],
                'reaction_counts' => $result['reaction_counts'],
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.comment.not_found');
        } catch (PostNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.post.not_found');
        } catch (ReactionNotAllowedException $e) {
            return $this->error($e->getMessageKey(), 422, ['code' => 'reaction_not_allowed']);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.failed', 500, $e->getMessage());
        }
    }

    /**
     * 마이페이지 「내 반응」 목록을 조회합니다.
     *
     * @param  IndexMyReactionRequest  $request  목록 조회 요청
     * @return JsonResponse 반응 목록 응답
     */
    public function index(IndexMyReactionRequest $request): JsonResponse
    {
        try {
            $filters = [
                'search' => $request->validated('search'),
                'search_field' => $request->validated('search_field') ?? 'title_content',
                'reaction_type_id' => $request->validated('reaction_type_id'),
                'target_type' => $request->validated('target_type') ?? 'all',
                'board_slug' => $request->validated('board_slug'),
                'sort' => $request->validated('sort') ?? 'latest',
            ];

            $paginator = $this->reactionService->paginateForUser(
                (int) Auth::id(),
                $filters,
                (int) ($request->validated('per_page') ?? 10),
            );

            return $this->success(
                'sirsoft-board::messages.reaction.retrieved',
                (new UserReactionCollection($paginator))->withQuery($filters)->resolve($request),
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.retrieve_failed', 500, $e->getMessage());
        }
    }

    /**
     * 본인 반응 1건을 해제합니다 (마이페이지).
     *
     * 반응이 꺼진 게시판이라도 해제는 허용한다 (설계 결정 R11).
     *
     * @param  int  $id  반응 ID
     * @return JsonResponse 해제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (! $this->reactionService->removeOwn($id, (int) Auth::id())) {
                return $this->notFound('sirsoft-board::messages.reaction.not_found');
            }

            return $this->success('sirsoft-board::messages.reaction.removed');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 본인 반응을 일괄 해제합니다 (마이페이지 선택 해제).
     *
     * 남의 ID·없는 ID 는 조용히 건너뛰고 실제 해제 건수만 돌려준다 (ID 탐색 방지).
     *
     * @param  BulkDestroyMyReactionRequest  $request  일괄 해제 요청
     * @return JsonResponse 해제 결과 (deleted_count)
     */
    public function bulkDestroy(BulkDestroyMyReactionRequest $request): JsonResponse
    {
        try {
            $count = $this->reactionService->removeManyOwn(
                $request->validated('ids'),
                (int) Auth::id(),
            );

            return $this->success(
                'sirsoft-board::messages.reaction.bulk_removed',
                ['deleted_count' => $count],
                200,
                ['count' => $count],
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.delete_failed', 500, $e->getMessage());
        }
    }
}
