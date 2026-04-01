<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\BlindPostRequest;
use Modules\Sirsoft\Board\Http\Requests\RestorePostRequest;
use Modules\Sirsoft\Board\Http\Requests\StorePostRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdatePostRequest;
use Modules\Sirsoft\Board\Http\Resources\BoardResource;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자용 게시글 관리 컨트롤러
 *
 * 게시판 게시글의 조회, 생성, 수정, 삭제, 블라인드 처리 등 관리자 전용 기능을 제공합니다.
 */
class PostController extends AdminBaseController
{
    use ChecksBoardPermission;

    /**
     * PostController 생성자
     *
     * @param  PostService  $postService  게시글 서비스
     * @param  BoardService  $boardService  게시판 서비스
     */
    public function __construct(
        private PostService $postService,
        private BoardService $boardService
    ) {
        parent::__construct();
    }

    /**
     * 게시글 목록을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 게시글 목록 응답
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.posts.read)
        try {
            // 게시판 조회
            $board = $this->boardService->getBoardBySlug($slug);
            if (! $board) {
                throw new BoardNotFoundException($slug);
            }

            // 목록 조회 파라미터 빌드 (필터 + perPage, 게시판 설정 적용)
            $listParams = $this->postService->buildListParams($request->all(), [
                'context' => 'admin',
                'board' => $board,
            ]);

            // 삭제된 게시글 포함 여부 (admin.manage 또는 admin.control 권한)
            $canViewDeleted = $this->checkBoardPermission($slug, 'admin.manage');

            // 게시글 목록 조회
            $posts = $this->postService->getPosts($slug, $listParams['filters'], $listParams['perPage'], withTrashed: $canViewDeleted);
            $totalNormalPosts = $this->postService->getTotalNormalPosts($slug, $listParams['filters'], $canViewDeleted);

            // PostCollection 구성
            $collection = new PostCollection($posts);
            $collection->setTotalNormalPosts($totalNormalPosts);
            $collection->setOrderDirection($listParams['filters']['order_direction']);

            // BoardResource로 boardInfo 생성
            $boardResource = new BoardResource($board);

            return $this->success(
                'sirsoft-board::messages.posts.fetch_success',
                $collection->withBoardInfo($boardResource->toBoardInfoForAdmin())
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
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 게시글 상세 정보 응답
     */
    public function show(string $slug, string|int $id): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.posts.read)
        // 문자열로 전달된 ID를 정수로 변환
        $id = (int) $id;

        try {
            // 게시글 조회 (상태 확인을 위해 먼저 조회)
            $post = $this->postService->getPost($slug, $id);

            // 삭제된 게시글은 admin.manage 권한 필요
            if ($post->deleted_at !== null) {
                if (! $this->checkBoardPermission($slug, 'admin.manage')) {
                    return $this->error('sirsoft-board::messages.posts.deleted_post_access_denied', 403);
                }
            }

            // 삭제된 게시글 포함 여부 판단
            $canViewDeleted = $this->checkBoardPermission($slug, 'admin.manage')
                || $this->checkBoardPermission($slug, 'admin.control');

            // 상세 정보 로드 (조회수 증가, 댓글 로드, 이전/다음 게시글 조회)
            $post = $this->postService->loadPostDetail($slug, $id, $canViewDeleted);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.fetch_success',
                new PostResource($post)
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            // 404 예외는 그대로 던짐
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
     * @return JsonResponse 생성된 게시글 정보 응답
     */
    public function store(StorePostRequest $request, string $slug): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.posts.write)
        try {
            $validated = $request->validated();

            // 작성자 정보 및 IP 주소 자동 설정
            $validated['user_id'] = Auth::id();
            $validated['ip_address'] = $request->ip();

            // 파일은 별도로 전달 (validated에서 제외)
            $files = $request->file('files', []);
            unset($validated['files']);

            // 첨부파일 ID 배열 추출
            $attachmentIds = $validated['attachment_ids'] ?? [];
            unset($validated['attachment_ids']);

            $post = $this->postService->createPost($slug, $validated, $files, $attachmentIds);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.create_success',
                new PostResource($post),
                201
            );
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
     * @return JsonResponse 수정된 게시글 정보 응답
     */
    public function update(UpdatePostRequest $request, string $slug, string|int $id): JsonResponse
    {
        // 문자열로 전달된 ID를 정수로 변환
        $id = (int) $id;

        // 게시글 조회 (권한 체크를 위해)
        try {
            $post = $this->postService->getPost($slug, $id);
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        }

        // 삭제된 게시글은 admin.manage 권한 필요
        if ($post->deleted_at !== null) {
            if ($response = $this->authorizeOrFail($slug, 'admin.manage')) {
                return $response;
            }
        } else {
            // 일반 게시글: admin.manage (타인 글) 또는 admin.write (본인 글)
            if ($response = $this->authorizePostModification($slug, $post->user_id)) {
                return $response;
            }
        }

        try {
            $validated = $request->validated();

            // 첨부파일 ID 배열 추출
            $attachmentIds = $validated['attachment_ids'] ?? [];
            unset($validated['attachment_ids']);

            $post = $this->postService->updatePost($slug, $id, $validated, $attachmentIds);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.update_success',
                new PostResource($post)
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            // 404 예외는 그대로 던짐
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글을 삭제합니다 (소프트 삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(string $slug, string|int $id): JsonResponse
    {
        // 문자열로 전달된 ID를 정수로 변환
        $id = (int) $id;

        // 게시글 조회 (권한 체크를 위해)
        try {
            $post = $this->postService->getPost($slug, $id);
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        }

        // 이미 삭제된 게시글은 admin.manage 권한 필요
        if ($post->deleted_at !== null) {
            if ($response = $this->authorizeOrFail($slug, 'admin.manage')) {
                return $response;
            }
        } else {
            // 일반 게시글: admin.manage (타인 글) 또는 admin.write (본인 글)
            if ($response = $this->authorizePostModification($slug, $post->user_id)) {
                return $response;
            }
        }

        try {
            $deletedPost = $this->postService->deletePost($slug, $id, 'admin');

            return $this->successWithResource(
                'sirsoft-board::messages.posts.delete_success',
                new PostResource($deletedPost)
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            // 404 예외는 그대로 던짐
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글을 블라인드 처리합니다.
     *
     * @param  BlindPostRequest  $request  블라인드 처리 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 블라인드 처리된 게시글 정보 응답
     */
    public function blind(BlindPostRequest $request, string $slug, string|int $id): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.manage)
        // 문자열로 전달된 ID를 정수로 변환
        $id = (int) $id;

        try {
            $validated = $request->validated();
            $reason = $validated['reason'] ?? '';

            $post = $this->postService->blindPost($slug, $id, $reason);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.blind_success',
                new PostResource($post)
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            // 404 예외는 그대로 던짐
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.blind_failed', 500, $e->getMessage());
        }
    }

    /**
     * 블라인드 처리된 게시글을 복원합니다.
     *
     * @param  RestorePostRequest  $request  복원 요청
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $id  게시글 ID
     * @return JsonResponse 복원된 게시글 정보 응답
     */
    public function restore(RestorePostRequest $request, string $slug, string|int $id): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.manage)
        // 문자열로 전달된 ID를 정수로 변환
        $id = (int) $id;

        try {
            $validated = $request->validated();
            $reason = $validated['reason'] ?? null;

            $post = $this->postService->restorePost($slug, $id, $reason);

            return $this->successWithResource(
                'sirsoft-board::messages.posts.restore_success',
                new PostResource($post)
            );
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException($id);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            // 404 예외는 그대로 던짐
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.restore_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글 폼 입력용 데이터를 반환합니다. (API 전송용)
     *
     * 생성/수정/답변글 작성 모드에 따라 적절한 폼 데이터를 반환합니다.
     * - 생성 모드: 빈 폼 데이터
     * - 수정 모드: 기존 게시글 데이터
     * - 답변글 모드: 기본값이 설정된 폼 데이터
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 폼 입력 데이터 응답
     */
    public function getFormData(Request $request, string $slug): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.posts.write)
        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug);
            if (! $board) {
                throw new BoardNotFoundException($slug);
            }

            $formData = [];

            // 수정 모드: post_id가 있으면 기존 게시글 데이터 로드
            if ($request->filled('post_id')) {
                $postId = (int) $request->get('post_id');
                $post = $this->postService->getPost($slug, $postId);

                $postResource = new PostResource($post);
                $postData = $postResource->toArray($request);

                // form에 필요한 필드만 추출 (API 전송용)
                $formData = [
                    'id' => $postData['id'] ?? null,
                    'title' => $postData['title'] ?? '',
                    'content' => $postData['content'] ?? '',
                    'content_mode' => $postData['content_mode'] ?? 'text',
                    'category' => $postData['category'] ?? null,
                    'is_notice' => $postData['is_notice'] ?? false,
                    'is_secret' => $postData['is_secret'] ?? false,
                    'parent_id' => $postData['parent_id'] ?? null,
                ];
            }
            // 답변글 모드: parent_id가 있으면 기본값 설정
            elseif ($request->filled('parent_id')) {
                // 답글 기능이 비활성화된 경우 접근 차단
                if (! $board->use_reply) {
                    return $this->error('sirsoft-board::validation.post.reply_not_allowed', 404);
                }

                $parentId = (int) $request->get('parent_id');
                $parentPost = $this->postService->getPost($slug, $parentId);

                // 답변글 기본값 설정
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
            // 생성 모드: 빈 폼 데이터
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
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.form_data_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시글 폼 화면 표시용 메타 데이터를 반환합니다. (읽기 전용)
     *
     * 게시판 정보, 원글 정보, 작성자 정보 등 화면 표시에 필요한 데이터를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 폼 메타 데이터 응답
     */
    public function getFormMeta(Request $request, string $slug): JsonResponse
    {
        // 권한은 라우트 미들웨어에서 체크됨 (sirsoft-board.{slug}.admin.posts.write)
        try {
            // 게시판 정보 조회
            $board = $this->boardService->getBoardBySlug($slug);
            if (! $board) {
                throw new BoardNotFoundException($slug);
            }

            // 관리자 라우트에서는 항상 사용자 권한 정보 포함
            $request->merge(['include_user_abilities' => true]);

            $boardResource = new \Modules\Sirsoft\Board\Http\Resources\BoardResource($board);
            $boardData = $boardResource->toArray($request);

            // 게시글 폼에서는 게시판 이름을 로컬라이즈된 문자열로 반환
            $boardData['name'] = $board->getLocalizedName();

            $metaData = [
                'board' => $boardData,
            ];

            // 수정 모드: 작성자 정보, 작성일, 첨부파일 정보 포함
            if ($request->filled('post_id')) {
                $postId = (int) $request->get('post_id');
                $post = $this->postService->getPost($slug, $postId);

                // 첨부파일 및 원글 관계 로드
                $post->load(['attachments', 'parent']);

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
            elseif ($request->filled('parent_id')) {
                $parentId = (int) $request->get('parent_id');
                $parentPost = $this->postService->getPost($slug, $parentId);

                // 블라인드 또는 삭제된 게시글에는 답글 작성 불가
                if ($parentPost->status === \Modules\Sirsoft\Board\Enums\PostStatus::Blinded) {
                    return $this->error('sirsoft-board::validation.post.parent_id.blinded', 403);
                }
                if ($parentPost->status === \Modules\Sirsoft\Board\Enums\PostStatus::Deleted) {
                    return $this->error('sirsoft-board::validation.post.parent_id.deleted', 403);
                }

                $parentPostResource = new PostResource($parentPost);
                $metaData['parent_post'] = $parentPostResource->toArray($request);
            }

            return $this->success('sirsoft-board::messages.posts.form_meta_retrieved', $metaData);
        } catch (ModelNotFoundException $e) {
            throw new PostNotFoundException((int) ($request->get('post_id') ?? $request->get('parent_id') ?? 0));
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException|PostNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.posts.form_meta_failed', 500, $e->getMessage());
        }
    }

    /**
     * 현재 사용자가 게시글의 작성자인지 확인합니다.
     *
     * @param  int  $postUserId  게시글 작성자 ID
     * @return bool 본인 여부
     */
    private function isPostOwner(int $postUserId): bool
    {
        return Auth::check() && Auth::id() === $postUserId;
    }

    /**
     * 게시글 수정/삭제 권한을 확인합니다.
     *
     * - admin.manage 권한: 모든 글 수정/삭제 가능 (비회원 글 포함)
     * - admin.write 권한: 본인 글만 수정/삭제 가능
     * - 비회원 게시글: admin.manage 권한 필요
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int|null  $postUserId  게시글 작성자 ID (비회원은 null)
     * @return JsonResponse|null 권한이 없으면 JsonResponse, 있으면 null
     */
    private function authorizePostModification(string $slug, ?int $postUserId): ?JsonResponse
    {
        // admin.manage 권한이 있으면 모든 글 수정/삭제 가능
        if ($this->checkBoardPermission($slug, 'admin.manage')) {
            return null;
        }

        // 비회원 게시글은 admin.manage 권한 필요 (위에서 통과 안 됨)
        if ($postUserId === null) {
            return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
        }

        // admin.posts.write 권한이 있고 본인 글이면 수정/삭제 가능
        if ($this->checkBoardPermission($slug, 'admin.posts.write') && $this->isPostOwner($postUserId)) {
            return null;
        }

        return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
    }
}
