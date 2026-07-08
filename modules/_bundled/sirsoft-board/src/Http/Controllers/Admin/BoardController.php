<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Board\Exceptions\MenuAlreadyExistsException;
use Modules\Sirsoft\Board\Http\Requests\StoreBoardRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateBoardRequest;
use Modules\Sirsoft\Board\Http\Resources\BoardCollection;
use Modules\Sirsoft\Board\Http\Resources\BoardResource;
use Modules\Sirsoft\Board\Http\Resources\BoardTypeResource;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\BoardTypeService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자용 게시판 관리 컨트롤러
 *
 * 게시판의 생성, 수정, 삭제, 조회 등 관리자 전용 기능을 제공합니다.
 */
class BoardController extends AdminBaseController
{
    use ChecksBoardPermission;

    /**
     * BoardController 생성자
     *
     * @param  BoardService  $boardService  게시판 서비스
     */
    public function __construct(
        private BoardService $boardService,
        private BoardTypeService $boardTypeService
    ) {
        parent::__construct();
    }

    /**
     * 게시판 목록을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 게시판 목록 응답
     */
    // audit:allow controller-base-request-injection reason: GET 목록 조회. all()로 필터·페이징 파라미터만 읽음 (검증 불필요)
    public function index(Request $request): JsonResponse
    {
        try {
            $boards = $this->boardService->getBoards($request->all());

            // BoardCollection의 withPermissions 사용
            $collection = new BoardCollection($boards);

            return $this->success(
                'sirsoft-board::messages.boards.fetch_success',
                $collection->withPermissions()
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시판 상세 정보를 조회합니다.
     *
     * @param  Board  $board  라우트 바인딩된 게시판 (slug 또는 ID)
     * @return JsonResponse 게시판 상세 정보 응답
     */
    public function show(Board $board): JsonResponse
    {
        try {
            $board = $this->boardService->getBoard($board->id);

            return $this->successWithResource(
                'sirsoft-board::messages.boards.fetch_success',
                new BoardResource($board)
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 슬러그로 게시판 상세 정보를 조회합니다.
     *
     * parent_id 쿼리 파라미터가 있으면 원글 정보도 함께 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @param  PostService  $postService  게시글 서비스 (DI)
     * @return JsonResponse 게시판 상세 정보 응답
     */
    // audit:allow controller-base-request-injection reason: GET 슬러그 상세 조회. 경로 파라미터(slug)만 사용 (검증 불필요)
    public function showBySlug(Request $request, string $slug, PostService $postService): JsonResponse
    {
        try {
            $board = $this->boardService->getBoardBySlug($slug);

            if (! $board) {
                return $this->notFound('sirsoft-board::messages.boards.error_404');
            }

            // 관리자 라우트에서는 항상 사용자 권한 정보 포함
            $request->merge(['include_user_abilities' => true]);

            $responseData = (new BoardResource($board))->toArray($request);

            // parent_id가 있으면 원글 정보도 함께 반환
            $parentId = $request->query('parent_id');
            if ($parentId) {
                try {
                    $parentPost = $postService->getPost($slug, (int) $parentId);
                    $parentPostData = (new PostResource($parentPost))->toArray($request);
                    // 답변글 기본 제목 추가
                    $parentPostData['reply_title'] = 'RE: '.$parentPost->title;
                    $responseData['parent_post'] = $parentPostData;
                } catch (ModelNotFoundException $e) {
                    // 원글이 없으면 null로 설정
                    $responseData['parent_post'] = null;
                }
            }

            return $this->success(
                'sirsoft-board::messages.boards.fetch_success',
                $responseData
            );
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 새로운 게시판을 생성합니다.
     *
     * @param  StoreBoardRequest  $request  게시판 생성 요청
     * @return JsonResponse 생성된 게시판 정보 응답
     */
    public function store(StoreBoardRequest $request): JsonResponse
    {
        try {
            $board = $this->boardService->createBoard($request->validated());

            return $this->successWithResource(
                'sirsoft-board::messages.boards.create_success',
                new BoardResource($board),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'sirsoft-board::messages.boards.validation_failed');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시판 정보를 수정합니다.
     *
     * @param  UpdateBoardRequest  $request  게시판 수정 요청
     * @param  Board  $board  라우트 바인딩된 게시판 (slug 또는 ID)
     * @return JsonResponse 수정된 게시판 정보 응답
     */
    public function update(UpdateBoardRequest $request, Board $board): JsonResponse
    {
        try {
            $board = $this->boardService->updateBoard($board->id, $request->validated());

            return $this->successWithResource(
                'sirsoft-board::messages.boards.update_success',
                new BoardResource($board)
            );
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'sirsoft-board::messages.boards.validation_failed');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시판을 영구 삭제합니다.
     *
     * @param  Board  $board  라우트 바인딩된 게시판 (slug 또는 ID)
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 삭제 성공 응답
     */
    // audit:allow controller-base-request-injection reason: DELETE 단건 삭제. force_delete 불리언 플래그만 input()으로 읽음 (검증 불필요)
    public function destroy(Board $board, Request $request): JsonResponse
    {
        try {
            $forceDelete = (bool) $request->input('force_delete', false);
            $this->boardService->deleteBoard($board->id, $forceDelete);

            return $this->success('sirsoft-board::messages.boards.delete_success');
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시판 폼에 필요한 데이터를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 폼 데이터 응답
     */
    // audit:allow controller-base-request-injection reason: GET 폼 데이터 조회. board_id 존재 여부로 생성/수정 모드만 분기 (검증 불필요)
    public function getFormData(Request $request): JsonResponse
    {
        try {
            $basicDefaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);

            // 수정 모드 — board_slug(신규, slug 라우팅) 또는 board_id(하위호환) 로 식별
            if ($request->filled('board_slug') || $request->filled('board_id')) {
                $request->merge(['include_permissions' => true]);

                $board = $request->filled('board_slug')
                    ? $this->boardService->getBoardBySlug($request->board_slug)
                    : $this->boardService->getBoard((int) $request->board_id);

                if (! $board) {
                    return $this->notFound('sirsoft-board::messages.boards.error_404');
                }
                // 폼 토글 초기값: 관리자 메뉴 등록 여부를 모델 속성으로 전달
                $board->is_in_admin_menu = $this->boardService->isInAdminMenu($board);
                $data = (new BoardResource($board))->toArray($request);
            }
            // 복사 모드
            elseif ($request->filled('copy_id')) {
                $copyData = $this->boardService->copyBoard($request->copy_id);

                $copyData['permissions'] = BoardResource::formatPermissionsForFrontend(
                    $copyData['permissions'] ?? []
                );

                $data = $copyData;
            }
            // 생성 모드
            else {
                $defaults = collect($basicDefaults);

                $data = $defaults->reject(fn ($value) => is_array($value))->toArray();

                // 수정 모드 전용 필드 명시적 null/빈값 설정 (수정→생성 이동 시 이전 값 제거)
                $data['id'] = null;
                $data['slug'] = null;
                $data['is_active'] = true;
                $data['add_to_menu'] = false;

                // 로그인한 관리자를 게시판 관리자 기본값으로 지정
                $currentUser = Auth::user();
                $data['board_managers'] = $currentUser ? [[
                    'uuid' => $currentUser->uuid,
                    'name' => $currentUser->name,
                    'email' => $currentUser->email,
                ]] : [];
                $data['board_steps'] = [];
                $data['board_manager_ids'] = $currentUser ? [$currentUser->uuid] : [];
                $data['board_step_ids'] = [];
                $data['created_at'] = null;
                $data['updated_at'] = null;

                // 다국어 필드 기본값 초기화 (기본 언어 배열로)
                $fallbackLocale = config('app.fallback_locale', 'ko');
                $data['name'] = [$fallbackLocale => ''];
                $data['description'] = [$fallbackLocale => ''];

                $data['categories'] = [];
                $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
                // TagInput 입력과 일관되게 배열로 주입 (categories 와 동일 패턴)
                $data['blocked_keywords'] = array_values($spamSecurity['blocked_keywords'] ?? []);
                $data['allowed_extensions'] = array_values($basicDefaults['allowed_extensions'] ?? []);

                // depth 필드는 reject(is_array)로 걸러지지 않지만 basic_defaults가 비어있을 수 있으므로 명시적 기본값 보장
                $limits = config('sirsoft-board.limits', []);
                if (! isset($data['max_reply_depth'])) {
                    $data['max_reply_depth'] = $limits['max_reply_depth_min'] ?? 1;
                }
                if (! isset($data['max_comment_depth'])) {
                    $data['max_comment_depth'] = $limits['max_comment_depth_min'] ?? 0;
                }

                $data['permissions'] = BoardResource::formatPermissionsForFrontend(
                    $basicDefaults['default_board_permissions'] ?? []
                );
            }

            $data['board_types'] = BoardTypeResource::collection(
                $this->boardTypeService->getBoardTypes()
            );

            $data['_meta'] = [
                'limits' => config('sirsoft-board.limits', []),
            ];

            return $this->success('sirsoft-board::messages.boards.form_data_retrieved', $data);
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.form_data_failed', 500, $e->getMessage());
        }
    }

    /**
     * 게시판을 관리자 메뉴에 추가합니다.
     *
     * @param  Board  $board  게시판 모델 (slug/id 라우트 바인딩)
     * @return JsonResponse 메뉴 추가 결과 응답
     */
    public function addToAdminMenu(Board $board): JsonResponse
    {
        try {
            // Service 계층에 위임
            $menu = $this->boardService->addToAdminMenu($board->id);

            return $this->success('sirsoft-board::messages.boards.menu_added_success', ['menu' => $menu]);
        } catch (MenuAlreadyExistsException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.error_404');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'sirsoft-board::messages.boards.menu_add_failed');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.menu_add_failed', 500, $e->getMessage());
        }
    }
}
