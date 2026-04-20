<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Board\Exceptions\MenuAlreadyExistsException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Modules\Sirsoft\Board\Http\Requests\StoreBoardRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateBoardRequest;
use Modules\Sirsoft\Board\Http\Resources\BoardCollection;
use Modules\Sirsoft\Board\Http\Resources\BoardResource;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Http\Resources\BoardTypeResource;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\BoardTypeService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

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
     * @param  int  $id  게시판 ID
     * @return JsonResponse 게시판 상세 정보 응답
     */
    public function show(int $id): JsonResponse
    {
        try {
            $board = $this->boardService->getBoard($id);

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
     * @param  int  $id  게시판 ID
     * @return JsonResponse 수정된 게시판 정보 응답
     */
    public function update(UpdateBoardRequest $request, int $id): JsonResponse
    {
        try {
            $board = $this->boardService->updateBoard($id, $request->validated());

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
     * @param  int  $id  게시판 ID
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 삭제 성공 응답
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        try {
            $forceDelete = (bool) $request->input('force_delete', false);
            $this->boardService->deleteBoard($id, $forceDelete);

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
    public function getFormData(Request $request): JsonResponse
    {
        try {
            $basicDefaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);

            // 수정 모드
            if ($request->filled('board_id')) {
                $request->merge(['include_permissions' => true]);

                $board = $this->boardService->getBoard($request->board_id);
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
                $data['blocked_keywords'] = collect($spamSecurity['blocked_keywords'] ?? [])->join(',');
                $data['allowed_extensions'] = collect($basicDefaults['allowed_extensions'] ?? [])->join(',');

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
     * @param  int  $id  게시판 ID
     * @return JsonResponse 메뉴 추가 결과 응답
     */
    public function addToAdminMenu(int $id): JsonResponse
    {
        try {
            // Service 계층에 위임
            $menu = $this->boardService->addToAdminMenu($id);

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
