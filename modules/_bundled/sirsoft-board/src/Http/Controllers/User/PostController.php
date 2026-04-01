<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Enums\PermissionType;
use Modules\Sirsoft\Board\Enums\SecretMode;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\User\StorePostRequest;
use Modules\Sirsoft\Board\Http\Requests\User\UpdatePostRequest;
use Modules\Sirsoft\Board\Http\Requests\User\VerifyGuestPasswordRequest;
use Modules\Sirsoft\Board\Http\Resources\BoardResource;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 사용자용 게시글 컨트롤러
 *
 * 게시판 게시글의 CRUD 기능을 제공합니다.
 * - 비로그인 사용자도 목록/상세 조회 가능 (게시판 설정에 따름)
 * - 비밀글은 권한 체크 후 조회
 * - 회원/비회원 모두 게시글 작성 가능 (게시판 설정에 따름)
 * - 수정/삭제는 작성자 본인 또는 비회원 비밀번호 확인 필요
 */
class PostController extends PublicBaseController
{
    use ChecksBoardPermission;

    /**
     * PostController 생성자
     *
     * @param  PostService  $postService  게시글 서비스
     * @param  BoardService  $boardService  게시판 서비스
     * @param  CommentService  $commentService  댓글 서비스
     */
    public function __construct(
        private PostService $postService,
        private BoardService $boardService,
        private CommentService $commentService
    ) {}

    /**
     * 게시글 목록을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 게시글 목록 응답
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        try {
            // 게시판 조회 및 활성화 확인
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 목록 조회 파라미터 빌드 (필터 + perPage)
            $listParams = $this->postService->buildListParams($request->all(), [
                'context' => 'user',
                'board' => $board,
                'userAgent' => $request->header('User-Agent'),
            ]);

            // 삭제된 게시글 포함 여부 (manager 권한 + 토글 ON 시에만 포함)
            $canViewDeleted = $this->checkBoardPermission($slug, 'manager', PermissionType::User);
            $withTrashed = $canViewDeleted && $request->boolean('del');

            // 게시글 목록 조회
            $posts = $this->postService->getPosts($slug, $listParams['filters'], $listParams['perPage'], withTrashed: $withTrashed, context: 'user');
            $totalNormalPosts = $this->postService->getTotalNormalPosts($slug, $listParams['filters'], $withTrashed, context: 'user');

            // PostCollection 구성
            $collection = new PostCollection($posts);
            $collection->setTotalNormalPosts($totalNormalPosts);
            $collection->setOrderDirection($listParams['filters']['order_direction']);

            // BoardResource로 boardInfo 생성
            $boardResource = new BoardResource($board);

            return $this->success(
                'sirsoft-board::messages.posts.fetch_success',
                $collection->withBoardInfo($boardResource->toBoardInfoForUser())
            );
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글 상세 정보를 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 게시글 상세 정보 응답
     */
    public function show(Request $request, string $slug, string|int $id): JsonResponse
    {
        $id = (int) $id;

        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 게시글 조회
            $post = $this->postService->getPost($slug, $id, context: 'user');

            // 삭제된 게시글은 manager 권한 필요
            if ($post->trashed()) {
                if (! $this->checkBoardPermission($slug, 'manager', PermissionType::User)) {
                    throw new PostNotFoundException($id);
                }
            }

            // 조회수 증가 (캐시 기반 중복 방지)
            $this->postService->incrementViewCountOnce($slug, $id);

            // 댓글/첨부파일 카운트 포함하여 게시글 조회
            $post = $this->postService->getPostWithCounts($slug, $id);

            // board 관계 수동 설정
            $post->setRelation('board', $board);

            // manager 권한 체크 (삭제 게시글/댓글 포함 여부 결정)
            $canViewDeleted = $this->checkBoardPermission($slug, 'manager', PermissionType::User);

            // 댓글 로드 (게시판 comment_order 설정 적용, manager 권한 + 토글 ON 시 삭제 댓글 포함)
            $withTrashedComments = $canViewDeleted && $request->boolean('del_cmt');
            $comments = $this->commentService->getCommentsByPostId($slug, $id, context: 'user', withTrashed: $withTrashedComments);

            // 댓글의 post에 board 관계 수동 설정 (CommentResource의 권한 체크에 필요)
            foreach ($comments as $comment) {
                $comment->setRelation('post', $post);
            }

            // 정렬된 댓글을 post에 설정
            $post->setRelation('comments', $comments);

            // 이전/다음 게시글 조회 (manager 권한 + del=1 시 삭제된 게시글 포함)
            $withTrashedNav = $canViewDeleted && $request->boolean('del');
            $post->navigation = $this->postService->getAdjacentPosts($slug, $id, filters: [], withTrashed: $withTrashedNav);

            // 비밀글 권한 체크 및 content 필터링은 PostResource에서 처리
            return $this->successWithResource(
                'sirsoft-board::messages.posts.fetch_success',
                new PostResource($post)
            );
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글을 생성합니다.
     *
     * @param  StorePostRequest  $request  게시글 생성 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 생성된 게시글 응답
     */
    public function store(StorePostRequest $request, string $slug): JsonResponse
    {
        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 파일 업로드 허용 여부 확인
            if ($request->hasFile('files')) {
                // 게시판에서 파일 업로드를 비활성화한 경우
                if (! $board->use_file_upload) {
                    return $this->error('sirsoft-board::messages.posts.file_upload_not_allowed', 403);
                }

                // 파일 업로드 권한 확인
                if (! $this->checkBoardPermission($slug, 'attachments.upload', PermissionType::User)) {
                    return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
                }
            }

            // 요청 데이터 준비
            $data = $request->validated();

            // user_id 설정 (인증 필수)
            $data['user_id'] = Auth::id();

            // IP 주소 설정
            $data['ip_address'] = $request->ip();

            // secret_mode에 따른 비밀글 설정 검증
            if ($board->secret_mode === SecretMode::Disabled) {
                // 비밀글 기능 비활성화된 경우 is_secret=true 요청 거부
                if (! empty($data['is_secret'])) {
                    return $this->error('sirsoft-board::messages.posts.secret_post_not_allowed', 403);
                }
            } elseif ($board->secret_mode === SecretMode::Always) {
                // 비밀글 필수인 경우 자동으로 is_secret=true 설정
                $data['is_secret'] = true;
            }
            // secret_mode='enabled'인 경우 사용자 선택에 따름 (별도 처리 불필요)

            // 게시글 생성
            $post = $this->postService->createPost($slug, $data);

            // 쿨다운 캐시 기록 (게시글 생성 성공 후)
            $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
            $cooldown = (int) ($spamSecurity['post_cooldown_seconds'] ?? 0);
            if ($cooldown > 0) {
                $identifier = Auth::id() ?? $request->ip();
                Cache::put("post_cooldown_{$slug}_{$identifier}", true, $cooldown);
            }

            // board 관계 수동 설정
            $post->setRelation('board', $board);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.create_success',
                new PostResource($post),
                201
            );
        } catch (BoardNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글을 수정합니다.
     *
     * @param  UpdatePostRequest  $request  게시글 수정 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 수정된 게시글 응답
     */
    public function update(UpdatePostRequest $request, string $slug, string|int $id): JsonResponse
    {
        $id = (int) $id;

        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 게시글 조회
            $post = $this->postService->getPost($slug, $id, context: 'user');

            // 수정 권한 확인
            if (! $this->canModifyPost($post, $request)) {
                return $this->error('sirsoft-board::messages.posts.modify_permission_denied', 403);
            }

            // 게시글 수정
            $data = $request->validated();
            $post = $this->postService->updatePost($slug, $id, $data);

            // board 관계 수동 설정
            $post->setRelation('board', $board);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.update_success',
                new PostResource($post)
            );
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글을 삭제합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(Request $request, string $slug, string|int $id): JsonResponse
    {
        $id = (int) $id;

        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 게시글 조회
            $post = $this->postService->getPost($slug, $id, context: 'user');

            // 삭제 권한 확인
            if (! $this->canModifyPost($post, $request)) {
                return $this->error('sirsoft-board::messages.posts.delete_permission_denied', 403);
            }

            // 게시글 삭제 (소프트 삭제)
            $this->postService->deletePost($slug, $id, 'user');

            return $this->success('sirsoft-board::messages.posts.delete_success');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 비밀글 조회를 위한 비밀번호를 검증합니다.
     *
     * 비밀글의 내용을 조회할 때 사용합니다. (posts.read 권한 필요)
     * 검증 성공 시 게시글 내용과 첨부파일을 포함하여 반환합니다.
     *
     * @param  VerifyGuestPasswordRequest  $request  비밀번호 검증 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 검증 결과 응답 (content, attachments 포함)
     */
    public function verifyPassword(VerifyGuestPasswordRequest $request, string $slug, string|int $id): JsonResponse
    {
        $id = (int) $id;

        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 게시글 조회 (첨부파일 포함)
            $post = $this->postService->getPostWithCounts($slug, $id);
            $post->setRelation('board', $board);

            // 비밀번호 검증 (Service 사용)
            $password = $request->validated('password');
            $verifyResult = $this->postService->verifyPassword($post, $password);

            if (! $verifyResult['success']) {
                return $this->error($verifyResult['error_key'], $verifyResult['error_code']);
            }

            // 검증 성공 - password_verified 플래그 설정하여 PostResource에서 content 포함
            $post->password_verified = true;

            // successWithResource 사용: $this->when() 조건부 필드가 올바르게 직렬화됨
            // (toArray 직접 호출 시 MissingValue 객체가 반환되는 문제 방지)
            return $this->successWithResource(
                'sirsoft-board::messages.posts.password_verified',
                new PostResource($post)
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.password_verify_failed', 500, $e->getMessage());
        }
    }

    /**
     * 수정/삭제를 위한 비밀번호를 검증합니다.
     *
     * 게시글 수정 또는 삭제 전 권한 확인용입니다. (posts.write 권한 필요)
     * 검증 성공 시 임시 토큰을 반환합니다.
     *
     * @param  VerifyGuestPasswordRequest  $request  비밀번호 검증 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 검증 결과 응답 (verification_token 포함)
     */
    public function verifyPasswordForModify(VerifyGuestPasswordRequest $request, string $slug, string|int $id): JsonResponse
    {
        $id = (int) $id;

        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // 게시글 조회
            $post = $this->postService->getPost($slug, $id, context: 'user');

            // 비밀번호 검증 (Service 사용)
            $password = $request->validated('password');
            $verifyResult = $this->postService->verifyPassword($post, $password);

            if (! $verifyResult['success']) {
                return $this->error($verifyResult['error_key'], $verifyResult['error_code']);
            }

            // 검증 성공 시 임시 토큰 생성 및 캐시 저장 (1시간 유효)
            $verificationToken = Str::random(32);
            $expiresAt = now()->addHours(1);
            Cache::put("board_post_verify_{$slug}_{$id}_{$verificationToken}", true, $expiresAt);

            return $this->success(
                'sirsoft-board::messages.posts.password_verified',
                [
                    'verified' => true,
                    'post_id' => $id,
                    'verification_token' => $verificationToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                ]
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.password_verify_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글 폼 메타 데이터를 반환합니다.
     *
     * 게시판 설정 정보, 파일 업로드 설정, 카테고리 목록 등 폼 렌더링에 필요한 메타 정보를 반환합니다.
     * 수정 모드 시 기존 첨부파일 정보, 답글 모드 시 원글 정보를 포함합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 폼 메타 데이터 응답
     */
    public function getFormMeta(Request $request, string $slug): JsonResponse
    {
        try {
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            // Admin과 동일하게 BoardResource 사용
            // user_abilities 포함을 위해 include_user_abilities 파라미터 설정
            $request->merge(['include_user_abilities' => true]);
            $boardResource = new BoardResource($board);
            $boardData = $boardResource->toArray($request);

            // 게시글 폼에서는 게시판 이름을 로컬라이즈된 문자열로 반환
            $boardData['name'] = $board->getLocalizedName();

            $metaData = [
                'board' => $boardData,
            ];

            // 수정 모드: 작성자 정보, 작성일, 첨부파일 정보 포함
            if ($request->filled('post_id') && $request->get('post_id') !== 'undefined' && $request->get('post_id') !== '') {
                $postId = (int) $request->get('post_id');
                $post = $this->postService->getPost($slug, $postId, context: 'user');

                // 회원 게시글이고 본인이 아닌 경우 권한 에러
                if ($post->user_id && Auth::id() !== $post->user_id) {
                    if (! $this->checkBoardPermission($slug, 'admin.manage')) {
                        return $this->error('sirsoft-board::messages.posts.no_permission', 403);
                    }
                }

                // 비회원 게시글인 경우 비밀번호 확인 필요
                $requiresPassword = ! $post->user_id && $post->password;

                // verification_token이 유효하면 비밀번호 확인 불필요
                if ($requiresPassword && $request->filled('verification_token')) {
                    $token = $request->get('verification_token');
                    if ($this->isVerificationTokenValid($slug, $postId, $token)) {
                        $requiresPassword = false;
                    }
                }

                $metaData['requires_password'] = $requiresPassword;
                $metaData['is_guest_post'] = ! $post->user_id;

                // 첨부파일 관계 로드
                $post->load('attachments');

                $postResource = new PostResource($post);
                $postData = $postResource->toArray($request);

                $metaData['author'] = $postData['author'] ?? null;
                $metaData['created_at'] = $postData['created_at'] ?? null;
                $metaData['attachments'] = $postData['attachments'] ?? [];

                // 수정 시 원글 정보가 있으면 포함
                if (! empty($postData['parent'])) {
                    $metaData['parent_post'] = $postData['parent'];
                }
            }
            // 답변글 모드: 원글 정보 포함
            elseif ($request->filled('parent_id') && $request->get('parent_id') !== 'undefined' && $request->get('parent_id') !== '') {
                $parentId = (int) $request->get('parent_id');

                if (! $board->use_reply) {
                    return $this->error('sirsoft-board::validation.post.reply_not_allowed', 403);
                }

                $parentPost = $this->postService->getPost($slug, $parentId, context: 'user');
                $parentPostResource = new PostResource($parentPost);
                $metaData['parent_post'] = $parentPostResource->toArray($request);
            }

            return $this->success('sirsoft-board::messages.posts.form_meta_retrieved', $metaData);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException((int) ($request->get('post_id') ?? $request->get('parent_id') ?? 0));
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.form_meta_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글 폼 화면용 데이터를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 폼 데이터 응답
     */
    public function getFormData(Request $request, string $slug): JsonResponse
    {
        try {
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
            if (! $board || ! $board->is_active) {
                throw new BoardNotFoundException($slug);
            }

            $formData = [];

            // 수정 모드
            if ($request->filled('post_id') && $request->get('post_id') !== 'undefined' && $request->get('post_id') !== '') {
                $postId = (int) $request->get('post_id');
                $post = $this->postService->getPost($slug, $postId, context: 'user');

                // 회원 게시글이고 본인이 아니거나, 관리자도 아닌 경우 권한 에러
                if ($post->user_id && Auth::id() !== $post->user_id) {
                    if (! $this->checkBoardPermission($slug, 'admin.manage')) {
                        return $this->error('sirsoft-board::messages.posts.no_permission', 403);
                    }
                }

                // 비밀글 또는 비회원 글의 검증 처리
                // 1. verification_token으로 검증 (권장 - 비밀번호 재전송 불필요)
                // 2. password로 검증 (fallback)
                if ($request->filled('verification_token')) {
                    $token = $request->get('verification_token');
                    if ($this->isVerificationTokenValid($slug, $postId, $token)) {
                        $post->password_verified = true;
                    }
                } elseif ($request->filled('password') && $post->password) {
                    $password = $request->get('password');
                    if (Hash::check($password, $post->password)) {
                        $post->password_verified = true;
                    }
                }

                $postResource = new PostResource($post);
                $postData = $postResource->toArray($request);

                $formData = [
                    'id' => $postData['id'] ?? null,
                    'title' => $postData['title'] ?? '',
                    'content' => $postData['content'] ?? '',
                    'content_mode' => $postData['content_mode'] ?? 'text',
                    'category' => $postData['category'] ?? null,
                    'is_notice' => $postData['is_notice'] ?? false,
                    'is_secret' => $postData['is_secret'] ?? false,
                    'parent_id' => $postData['parent_id'] ?? null,
                    'attachments' => $postData['attachments'] ?? [],
                    // 비회원 글 수정 시 verification_token 유지 (PUT 요청에 필요)
                    'verification_token' => $request->get('verification_token', ''),
                ];
            }
            // 답변글 모드
            elseif ($request->filled('parent_id') && $request->get('parent_id') !== 'undefined' && $request->get('parent_id') !== '') {
                $parentId = (int) $request->get('parent_id');

                if (! $board->use_reply) {
                    return $this->error('sirsoft-board::validation.post.reply_not_allowed', 403);
                }
                $parentPost = $this->postService->getPost($slug, $parentId, context: 'user');

                $formData = [
                    'title' => 'Re: '.$parentPost->title,
                    'content' => '',
                    'content_mode' => 'text',
                    'category' => $parentPost->category ?? null,
                    'is_notice' => false,
                    'is_secret' => $parentPost->is_secret ?? false,
                    'parent_id' => $parentId,
                ];
            }
            // 생성 모드
            else {
                $formData = [
                    'title' => '',
                    'content' => '',
                    'content_mode' => 'text',
                    'category' => null,
                    'is_notice' => false,
                    'is_secret' => $board->secret_mode->value === 'always',
                    'parent_id' => null,
                ];
            }

            return $this->success('sirsoft-board::messages.posts.form_data_retrieved', $formData);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException((int) ($request->get('post_id') ?? $request->get('parent_id') ?? 0));
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.form_data_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글 수정/삭제 권한을 확인합니다.
     *
     * 다음 조건 중 하나라도 만족하면 수정/삭제 가능:
     * - 작성자 본인 (로그인 사용자의 user_id 일치)
     * - 비회원 게시글인 경우 비밀번호 확인
     * - 게시판 관리자 (admin.manage 권한)
     * - 시스템 관리자 (Super Admin 역할)
     *
     * @param  \Modules\Sirsoft\Board\Models\Post  $post  게시글 모델
     * @param  Request  $request  HTTP 요청
     * @return bool 수정/삭제 가능 여부
     */
    private function canModifyPost($post, Request $request): bool
    {
        $slug = $post->board->slug;

        // 1. 게시판 관리자 권한 확인 (admin.manage 권한)
        if (Auth::check() && $this->checkBoardPermission($slug, 'admin.manage')) {
            return true;
        }

        // 2. 작성자 본인 확인 (회원 게시글)
        if (Auth::check() && $post->user_id && Auth::id() === $post->user_id) {
            return true;
        }

        // 3. 비회원 게시글인 경우 verification_token 또는 비밀번호로 확인
        if (! $post->user_id && $post->password) {
            // 3-1. verification_token 확인 (권장)
            $token = $request->input('verification_token');
            if ($token && $this->isVerificationTokenValid($slug, $post->id, $token)) {
                return true;
            }

            // 3-2. 비밀번호 확인 (fallback)
            $password = $request->input('password');
            if ($password && Hash::check($password, $post->password)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Verification Token 헬퍼 메서드
    // =========================================================================

    /**
     * verification_token이 유효한지 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $token  검증 토큰
     * @return bool 토큰 유효 여부
     */
    private function isVerificationTokenValid(string $slug, int $postId, string $token): bool
    {
        return (bool) Cache::get("board_post_verify_{$slug}_{$postId}_{$token}");
    }

    // =========================================================================
    // 사용자 공개 게시글 조회 메서드 (공개 프로필용)
    // =========================================================================

    /**
     * 특정 사용자의 공개 게시글 목록을 조회합니다.
     *
     * 게시판 슬러그 없이 모든 게시판에서 해당 사용자의 공개 게시글을 조회합니다.
     * 비밀글은 제외됩니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  User  $user  사용자 모델 (Route Model Binding, uuid 기반)
     * @return JsonResponse 게시글 목록 (페이지네이션)
     */
    public function userPosts(Request $request, User $user): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $perPage = min(max($perPage, 1), 100);

            $filters = [
                'sort' => $request->input('sort', 'latest'),
            ];

            $result = $this->postService->getUserPublicPosts($user->id, $filters, $perPage);

            return $this->success('sirsoft-board::messages.posts.fetch_success', $result);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 사용자의 게시글/댓글 통계를 조회합니다.
     *
     * 공개 프로필 페이지에서 사용됩니다.
     * status=published인 게시글/댓글만 카운트합니다.
     *
     * @param  User  $user  사용자 모델 (Route Model Binding, uuid 기반)
     * @return JsonResponse 통계 정보
     */
    public function userPostsStats(User $user): JsonResponse
    {
        try {
            $stats = $this->postService->getUserPublicStats($user->id);

            return $this->success('common.success', $stats);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.fetch_failed', 500, $e->getMessage());
        }
    }
}
