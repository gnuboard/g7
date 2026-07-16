<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Http\Requests\BlindCommentRequest;
use Modules\Sirsoft\Board\Http\Requests\StoreCommentRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateCommentRequest;
use Modules\Sirsoft\Board\Http\Resources\CommentResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 관리자용 댓글 컨트롤러
 *
 * 댓글 생성, 수정, 삭제, 블라인드, 복원 기능을 제공합니다.
 */
class CommentController extends AdminBaseController
{
    use ChecksBoardPermission;

    /**
     * CommentController 생성자
     *
     * @param  CommentService  $commentService  댓글 서비스
     */
    public function __construct(
        private CommentService $commentService
    ) {
        parent::__construct();
    }

    /**
     * 댓글 수정/삭제 권한을 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int|null  $commentUserId  댓글 작성자 ID
     * @return JsonResponse|null 권한이 없으면 JsonResponse, 있으면 null
     */
    protected function authorizeCommentModification(string $slug, ?int $commentUserId): ?JsonResponse
    {
        // admin.manage 권한이 있으면 모든 댓글 수정/삭제 가능
        if ($this->checkBoardPermission($slug, 'admin.manage')) {
            return null;
        }

        // 비회원 댓글은 admin.manage 권한 필요
        if ($commentUserId === null) {
            return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
        }

        // admin.write 권한이 있고 본인 댓글이면 수정/삭제 가능
        if ($this->checkBoardPermission($slug, 'admin.write') && $this->isCommentOwner($commentUserId)) {
            return null;
        }

        return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
    }

    /**
     * 현재 사용자가 댓글 작성자인지 확인합니다.
     *
     * @param  int|null  $commentUserId  댓글 작성자 ID
     * @return bool 댓글 작성자이면 true, 아니면 false
     */
    protected function isCommentOwner(?int $commentUserId): bool
    {
        return Auth::id() === $commentUserId;
    }

    /**
     * 댓글을 생성합니다.
     *
     * @param  StoreCommentRequest  $request  댓글 생성 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @return JsonResponse 댓글 생성 결과 응답
     */
    public function store(StoreCommentRequest $request, string $slug, int $postId): JsonResponse
    {
        try {
            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $data = $request->validated();
            $data['post_id'] = $postId;
            $data['user_id'] = Auth::id();
            $data['ip_address'] = $request->ip();

            $comment = $this->commentService->createComment($slug, $data);

            return $this->successWithResource(
                'sirsoft-board::messages.comment.create_success',
                new CommentResource($comment),
                201
            );
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 댓글을 수정합니다.
     *
     * @param  UpdateCommentRequest  $request  댓글 수정 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $id  댓글 ID
     * @return JsonResponse 댓글 수정 결과 응답
     */
    public function update(UpdateCommentRequest $request, string $slug, int $postId, int $id): JsonResponse
    {
        try {
            // 댓글 조회
            $comment = $this->commentService->getComment($slug, $id);

            // 권한 체크: 본인 댓글 또는 admin.manage
            if ($response = $this->authorizeCommentModification($slug, $comment->user_id)) {
                return $response;
            }

            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $data = $request->validated();
            $updatedComment = $this->commentService->updateComment($slug, $id, $data);

            return $this->successWithResource(
                'sirsoft-board::messages.comment.update_success',
                new CommentResource($updatedComment)
            );
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.comment.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 댓글을 삭제합니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $id  댓글 ID
     * @return JsonResponse 댓글 삭제 결과 응답
     */
    public function destroy(string $slug, int $postId, int $id): JsonResponse
    {
        try {
            // 댓글 조회
            $comment = $this->commentService->getComment($slug, $id);

            // 권한 체크: 본인 댓글 또는 admin.manage
            if ($response = $this->authorizeCommentModification($slug, $comment->user_id)) {
                return $response;
            }

            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $this->commentService->deleteComment($slug, $id, 'admin');

            return $this->success('sirsoft-board::messages.comment.delete_success');
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.comment.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 댓글을 블라인드 처리합니다.
     *
     * @param  BlindCommentRequest  $request  블라인드 처리 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $id  댓글 ID
     * @return JsonResponse 댓글 블라인드 결과 응답
     */
    public function blind(BlindCommentRequest $request, string $slug, int $postId, int $id): JsonResponse
    {
        try {
            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $validated = $request->validated();
            $reason = $validated['reason'] ?? '';

            $comment = $this->commentService->blindComment($slug, $id, $reason);

            return $this->successWithResource(
                'sirsoft-board::messages.comment.blind_success',
                new CommentResource($comment)
            );
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.comment.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.blind_failed', 500, $e->getMessage());
        }
    }

    /**
     * 블라인드된 댓글을 복원합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $id  댓글 ID
     * @return JsonResponse 댓글 복원 결과 응답
     */
    public function restore(Request $request, string $slug, int $postId, int $id): JsonResponse
    {
        try {
            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $reason = $request->input('reason');
            $comment = $this->commentService->restoreComment($slug, $id, $reason);

            return $this->successWithResource(
                'sirsoft-board::messages.comment.restore_success',
                new CommentResource($comment)
            );
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.comment.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.restore_failed', 500, $e->getMessage());
        }
    }
}
