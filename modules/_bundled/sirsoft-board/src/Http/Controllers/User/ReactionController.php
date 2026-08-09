<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Http\Requests\User\ReactPostRequest;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\ReactionService;

/**
 * 사용자용 반응 컨트롤러
 *
 * 게시글 반응(추천/비추천) 등록·전환·해제를 하나의 엔드포인트로 처리합니다.
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
            return $this->error($e->getMessage(), 422, ['code' => 'reaction_not_allowed']);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.failed', 500, $e->getMessage());
        }
    }
}
