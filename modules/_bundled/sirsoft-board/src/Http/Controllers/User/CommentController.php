<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Http\Requests\StoreCommentRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateCommentRequest;
use Modules\Sirsoft\Board\Http\Resources\CommentResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\CommentService;

/**
 * 사용자용 댓글 컨트롤러
 *
 * 회원/비회원 모두 댓글 생성, 수정, 삭제 기능을 제공합니다.
 */
class CommentController extends PublicBaseController
{
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
     * 게시글의 댓글 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @return JsonResponse 댓글 목록 응답
     */
    public function index(string $slug, int $postId): JsonResponse
    {
        try {
            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $comments = $this->commentService->getCommentsByPostId($slug, $postId);

            return $this->success(
                'sirsoft-board::messages.comments.index_success',
                CommentResource::collection($comments)
            );
        } catch (ModelNotFoundException) {
            return $this->error('sirsoft-board::messages.boards.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comments.index_failed', 500);
        }
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
            $data['ip_address'] = $request->ip();

            // user_id 설정 (인증 필수)
            $data['user_id'] = Auth::id();

            $comment = $this->commentService->createComment($slug, $data);

            // 쿨다운 캐시 기록 (댓글 생성 성공 후)
            $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
            $cooldown = (int) ($spamSecurity['comment_cooldown_seconds'] ?? 0);
            if ($cooldown > 0) {
                $identifier = Auth::id() ?? $request->ip();
                Cache::put("comment_cooldown_{$slug}_{$identifier}", true, $cooldown);
            }

            return $this->successWithResource(
                'sirsoft-board::messages.comment.create_success',
                new CommentResource($comment),
                201
            );
        } catch (ModelNotFoundException) {
            return $this->error('sirsoft-board::messages.boards.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.create_failed', 500);
        }
    }

    /**
     * 댓글을 수정합니다.
     *
     * @param  UpdateCommentRequest  $request  댓글 수정 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 댓글 수정 결과 응답
     */
    public function update(UpdateCommentRequest $request, string $slug, int $postId, int $commentId): JsonResponse
    {
        try {
            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $comment = $this->commentService->getComment($slug, $commentId);

            // 권한 확인 (Service에서 처리)
            $canUpdate = $this->commentService->canUpdate(
                $comment,
                Auth::id(),
                $request->input('password'),
                $slug
            );

            if (! $canUpdate) {
                return $this->forbidden('sirsoft-board::messages.comment.update_forbidden');
            }

            // password는 검증용이므로 제거
            $data = $request->except('password');
            $updatedComment = $this->commentService->updateComment($slug, $commentId, $data);

            return $this->successWithResource(
                'sirsoft-board::messages.comment.update_success',
                new CommentResource($updatedComment)
            );
        } catch (ModelNotFoundException) {
            return $this->error('sirsoft-board::messages.comment.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.update_failed', 500);
        }
    }

    /**
     * 댓글을 삭제합니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 댓글 삭제 결과 응답
     */
    public function destroy(string $slug, int $postId, int $commentId): JsonResponse
    {
        try {
            $board = Board::where('slug', $slug)->firstOrFail();

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $comment = $this->commentService->getComment($slug, $commentId);

            // 비회원인 경우 password 파라미터 필요
            $password = request()->input('password');

            // 권한 확인 (Service에서 처리)
            $canDelete = $this->commentService->canDelete(
                $comment,
                Auth::id(),
                $password,
                $slug
            );

            if (! $canDelete) {
                return $this->forbidden('sirsoft-board::messages.comment.delete_forbidden');
            }

            $this->commentService->deleteComment($slug, $commentId, 'user');

            return $this->success(
                'sirsoft-board::messages.comment.delete_success'
            );
        } catch (ModelNotFoundException) {
            return $this->error('sirsoft-board::messages.comment.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.delete_failed', 500);
        }
    }

    /**
     * 비회원 댓글 비밀번호를 검증합니다.
     *
     * @param  Request  $request  요청
     * @param  string  $slug  게시판 slug
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 비밀번호 검증 결과 응답
     */
    public function verifyPassword(Request $request, string $slug, int $commentId): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $comment = $this->commentService->getComment($slug, $commentId);

            if (! $comment) {
                return $this->error('sirsoft-board::messages.comment.not_found', 404);
            }

            // 비회원 댓글 확인
            if ($comment->user_id !== null) {
                return $this->error('sirsoft-board::messages.comment.not_guest_comment', 400);
            }

            $result = Hash::check($request->password, $comment->password);

            // 비밀번호 검증
            if (! $result) {
                return $this->error('sirsoft-board::messages.comment.invalid_password', 401);
            }

            // 검증 성공 시 임시 토큰 생성 (프론트엔드에서 로컬 스토리지에 저장)
            $verificationToken = Str::random(32);

            return $this->success(
                'sirsoft-board::messages.comment.password_verified',
                [
                    'verified' => true,
                    'comment_id' => $commentId,
                    'verification_token' => $verificationToken,
                    'expires_at' => now()->addHours(1)->toIso8601String(), // 1시간 유효
                ]
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.verify_password_failed', 500);
        }
    }
}
