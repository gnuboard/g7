<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Exceptions\CommentDepthExceededException;
use Modules\Sirsoft\Board\Exceptions\PostNotCommentableException;
use Modules\Sirsoft\Board\Http\Requests\DestroyCommentRequest;
use Modules\Sirsoft\Board\Http\Requests\StoreCommentRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateCommentRequest;
use Modules\Sirsoft\Board\Http\Requests\VerifyCommentPasswordRequest;
use Modules\Sirsoft\Board\Http\Resources\CommentResource;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardService;
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
     * @param  BoardService  $boardService  게시판 서비스
     */
    public function __construct(
        private CommentService $commentService,
        private BoardService $boardService,
        private PostRepositoryInterface $postRepository
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
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->error('sirsoft-board::messages.boards.not_found', 404);
            }

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            // 비밀글 댓글 게이팅(KVE-2026-1914): 부모 게시글이 비밀글이면 열람 권한이 없는
            // 요청에는 댓글 목록을 노출하지 않는다(게시글 상세와 동일 정책, SecretContentGate SSoT).
            $post = $this->postRepository->find($slug, $postId);

            // 부모 글을 못 읽으면 막는다(fail-closed). `find` 는 슬러그로 게시판을 먼저 찾는데
            // 그 게시판이 없으면 null 을 돌려주므로, 통과시키면 비밀 게이트가 있어야 할 자리에서
            // 무게이트로 댓글 목록이 나간다.
            if (! $post) {
                return $this->error('sirsoft-board::messages.posts.not_found', 404);
            }

            if ($post->is_secret && ! PostResource::canViewSecretForPost($post)) {
                return $this->success(
                    'sirsoft-board::messages.comments.index_success',
                    CommentResource::collection([])
                );
            }

            // 이미 조회한 부모 post 를 CommentResource 로 전달한다(KVE-2026-1914 이중 방어 A-4b).
            // Resource 는 이 인스턴스를 재사용해 (a) 2차 비밀 게이트를 댓글당 lazy-load 없이
            // 재확인하고 (b) toArray 의 slug 도출도 재사용한다 — 목록의 댓글당 board_posts
            // 조회(N+1)를 제거한다. 컨트롤러가 SSoT 로 부모 post 를 쥐고 있으므로 추가 쿼리 0.
            if ($post) {
                request()->attributes->set('sirsoft_board_parent_post', $post);
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
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->error('sirsoft-board::messages.boards.not_found', 404);
            }

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            $data = $request->validated();
            // 경로의 게시글을 SSoT 로 고정 (요청 본문 값이 경로와 갈라지지 않도록)
            $data['post_id'] = $postId;
            $data['ip_address'] = $request->ip();

            // user_id 설정 (인증 필수)
            $data['user_id'] = Auth::id();

            $comment = $this->commentService->createComment($slug, $data);

            // 쿨다운 캐시 기록 (댓글 생성 성공 후)
            $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
            $cooldown = (int) ($spamSecurity['comment_cooldown_seconds'] ?? 0);
            if ($cooldown > 0) {
                $identifier = Auth::id() ?? $request->ip();
                $this->commentService->recordCommentCooldown($slug, $identifier, $cooldown);
            }

            return $this->successWithResource(
                'sirsoft-board::messages.comment.create_success',
                new CommentResource($comment),
                201
            );
        } catch (ModelNotFoundException) {
            return $this->error('sirsoft-board::messages.boards.not_found', 404);
        } catch (CommentDepthExceededException $e) {
            // 요청 단계 검증을 우회해 Service 관문에 걸린 경우 — 사용자 입력 문제이므로 422
            return $this->error($e->getMessageKey(), 422, null, $e->getMessageParams());
        } catch (PostNotCommentableException $e) {
            // 블라인드·삭제된 게시글 — 서버 오류가 아니라 게시글 상태 문제이므로 422 + 사유 전달
            return $this->error($e->getMessageKey(), 422, null, $e->getMessageParams());
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
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->error('sirsoft-board::messages.boards.not_found', 404);
            }

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            // 경로의 게시글에 속한 댓글만 조회 (교차 게시글 접근 차단)
            $comment = $this->commentService->getComment($slug, $commentId, $postId);

            // 권한 확인 (Service에서 처리)
            $canUpdate = $this->commentService->canUpdate(
                $comment,
                Auth::id(),
                $request->input('password'),
                $slug
            );

            // 비회원 댓글: 평문 비밀번호 재전송 대신 검증 토큰으로도 본인 확인 (게시글과 동형)
            if (! $canUpdate && $request->filled('verification_token')) {
                $canUpdate = $this->commentService->consumeCommentVerifyToken(
                    $slug,
                    $commentId,
                    (string) $request->input('verification_token')
                );
            }

            if (! $canUpdate) {
                return $this->forbidden('sirsoft-board::messages.comment.update_forbidden');
            }

            // 검증된 필드만 반영 (미검증 입력의 대량 할당 차단).
            // password/verification_token 은 본인 확인용이므로 저장 데이터에서 제거
            $data = collect($request->validated())->except(['password', 'verification_token'])->toArray();
            $updatedComment = $this->commentService->updateComment($slug, $commentId, $data, $postId);

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
     * @param  DestroyCommentRequest  $request  댓글 삭제 요청 (비회원 password 형식 검증)
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 댓글 삭제 결과 응답
     */
    public function destroy(DestroyCommentRequest $request, string $slug, int $postId, int $commentId): JsonResponse
    {
        try {
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->error('sirsoft-board::messages.boards.not_found', 404);
            }

            if (! $board->use_comment) {
                return $this->error('sirsoft-board::messages.comments.comments_disabled', 403);
            }

            // 경로의 게시글에 속한 댓글만 조회 (교차 게시글 접근 차단)
            $comment = $this->commentService->getComment($slug, $commentId, $postId);

            // 비회원인 경우 password 파라미터 필요 (형식 검증은 DestroyCommentRequest — 배열 주입 422 차단)
            $password = $request->validated('password');

            // 권한 확인 (Service에서 처리)
            $canDelete = $this->commentService->canDelete(
                $comment,
                Auth::id(),
                $password,
                $slug
            );

            // 비회원 댓글: 평문 비밀번호 재전송 대신 검증 토큰으로도 본인 확인 (게시글과 동형)
            $verificationToken = $request->validated('verification_token');
            if (! $canDelete && ! empty($verificationToken)) {
                $canDelete = $this->commentService->consumeCommentVerifyToken(
                    $slug,
                    $commentId,
                    $verificationToken
                );
            }

            if (! $canDelete) {
                return $this->forbidden('sirsoft-board::messages.comment.delete_forbidden');
            }

            $this->commentService->deleteComment($slug, $commentId, 'user', $postId);

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
     * @param  VerifyCommentPasswordRequest  $request  비밀번호 확인 요청
     * @param  string  $slug  게시판 slug
     * @param  int  $commentId  댓글 ID
     * @return JsonResponse 비밀번호 검증 결과 응답
     */
    public function verifyPassword(VerifyCommentPasswordRequest $request, string $slug, int $commentId): JsonResponse
    {
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

            // 검증 성공 시 1회용 토큰을 발급하고 캐시에 저장한다.
            // (게시글과 동형 — update/destroy 가 이 토큰을 소비해 평문 비밀번호 재전송을 대체)
            $verificationToken = Str::random(32);
            $tokenResult = $this->commentService->storeCommentVerifyToken($slug, $commentId, $verificationToken);

            return $this->success(
                'sirsoft-board::messages.comment.password_verified',
                [
                    'verified' => true,
                    'comment_id' => $commentId,
                    'verification_token' => $tokenResult['token'],
                    'expires_at' => $tokenResult['expires_at'],
                ]
            );
        } catch (ModelNotFoundException) {
            return $this->error('sirsoft-board::messages.comment.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.comment.verify_password_failed', 500);
        }
    }
}
