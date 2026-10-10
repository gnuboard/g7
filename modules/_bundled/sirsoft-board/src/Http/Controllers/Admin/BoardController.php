<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Exceptions\PermissionEscalationException;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Board\Exceptions\MenuAlreadyExistsException;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkBoardMembersRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\IndexBoardRequest;
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
     * @param  BoardTypeService  $boardTypeService  게시판 유형 서비스
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
     * @param  IndexBoardRequest  $request  목록 조회 요청 (정렬 컬럼·페이지 크기 화이트리스트 검증)
     * @return JsonResponse 게시판 목록 응답
     */
    public function index(IndexBoardRequest $request): JsonResponse
    {
        try {
            $boards = $this->boardService->getBoards($request->validated());

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
        } catch (PermissionEscalationException $e) {
            // 보유하지 않은 게시판 권한을 역할에 붙이거나 그 권한을 가진 담당자를 지정하려 한 요청
            return $this->error('exceptions.cannot_grant_unheld_permission', 403);
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
        } catch (PermissionEscalationException $e) {
            // 보유하지 않은 게시판 권한을 역할에 붙이거나 그 권한을 가진 담당자를 지정하려 한 요청
            return $this->error('exceptions.cannot_grant_unheld_permission', 403);
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
            // 생성·복사 모드에서만 채운다 — 수정 모드는 기존 명단을 그대로 다시 보내는 저장이라 검사 대상이 아니다
            $canAssignMembers = null;

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

                // 원본 명단을 그대로 채우면 담당자를 지정할 수 없는 액터(새 게시판 ACL 밖의 위임 관리자)가
                // 저장할 때 403 이다 — 그런 액터에게는 새 게시판에 허용되는 유일한 값인 본인 한 명을 관리자로 채우고
                // 스텝은 비운다(_meta.can_assign_board_members=false, K4F-2)
                $canAssignMembers = $this->boardService->canAssignBoardMembersOnNewBoard($copyData['permissions'] ?? []);
                if (! $canAssignMembers) {
                    $self = $this->selfManagerPrefill();
                    $copyData['board_manager_ids'] = $self['board_manager_ids'];
                    $copyData['board_managers'] = $self['board_managers'];
                    $copyData['board_step_ids'] = [];
                    $copyData['board_steps'] = [];
                }

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

                // 로그인한 관리자를 게시판 관리자 기본값으로 지정한다. 새 게시판의 기본 ACL 로 게시판 권한을 받지
                // 못하는 위임 관리자도 새 게시판에는 본인 한 명을 관리자로 둘 수 있어(K4F-2) 같은 값을 채우고,
                // 다른 담당자는 지정할 수 없다는 사실만 _meta.can_assign_board_members=false 로 알린다
                $canAssignMembers = $this->boardService->canAssignBoardMembersOnNewBoard(
                    $basicDefaults['default_board_permissions'] ?? []
                );
                $self = $this->selfManagerPrefill();
                $data['board_managers'] = $self['board_managers'];
                $data['board_steps'] = [];
                $data['board_manager_ids'] = $self['board_manager_ids'];
                $data['board_step_ids'] = [];
                $data['created_at'] = null;
                $data['updated_at'] = null;

                // 다국어 필드 기본값 초기화 (기본 언어 배열로)
                $fallbackLocale = config('app.fallback_locale', 'ko');
                $data['name'] = [$fallbackLocale => ''];
                $data['description'] = [$fallbackLocale => ''];

                $data['categories'] = [];
                // TagInput 입력과 일관되게 배열로 주입 (categories 와 동일 패턴).
                // 금지어 기본값은 「게시판 기본 설정」(basic_defaults)에 있다 — 스팸·보안 설정에는 금지어 칸이 없다
                $data['blocked_keywords'] = array_values($basicDefaults['blocked_keywords'] ?? []);
                $data['allowed_extensions'] = array_values($basicDefaults['allowed_extensions'] ?? []);
                // reject(is_array) 로 제거된 배열 필드 — 체크박스 초기 선택 상태 렌더에 필요
                $data['active_reaction_types'] = array_values($basicDefaults['active_reaction_types'] ?? []);

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

            // 생성·복사 모드는 BoardResource 를 거치지 않아 abilities 가 없다 — 등록 권한이 없는 관리자에게
            // 폼이 열린 채 보이고 저장할 때만 403 이 났다. 저장(POST)과 같은 권한으로 판정해 화면이 잠그게 한다
            if (! isset($data['abilities'])) {
                $data['abilities'] = [
                    'can_create' => PermissionHelper::check('sirsoft-board.boards.create', $request->user()),
                ];
            }

            $data['board_types'] = BoardTypeResource::collection(
                $this->boardTypeService->getBoardTypes()
            );

            $data['_meta'] = [
                'limits' => config('sirsoft-board.limits', []),
                // false 면 화면이 담당자 칸을 잠그고 이유를 보여 준다(생성·복사 모드), 수정 모드는 null
                'can_assign_board_members' => $canAssignMembers,
                // 「사용 반응 유형」 옆 [관리] 는 환경설정 화면으로 건너가 유형을 고치는 버튼이다 — 그 저장 권한이 없으면 잠근다
                'can_manage_reaction_types' => PermissionHelper::check('sirsoft-board.settings.update', $request->user()),
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
            return $this->error($e->getMessageKey(), $e->getCode(), null, $e->getMessageParams());
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

    /**
     * 생성·복사 폼의 관리자 칸에 채울 로그인한 관리자 본인 한 명을 만듭니다.
     *
     * @return array{board_manager_ids: array<int, string>, board_managers: array<int, array{uuid: string, name: string, email: string}>} 관리자 명단 초기값
     */
    private function selfManagerPrefill(): array
    {
        $currentUser = Auth::user();
        if (! $currentUser) {
            return ['board_manager_ids' => [], 'board_managers' => []];
        }

        return [
            'board_manager_ids' => [$currentUser->uuid],
            'board_managers' => [[
                'uuid' => $currentUser->uuid,
                'name' => $currentUser->name,
                'email' => $currentUser->email,
            ]],
        ];
    }

    /**
     * 여러 게시판의 관리자·스텝에 회원을 한꺼번에 더하거나 뺍니다.
     *
     * @param  BulkBoardMembersRequest  $request  대상 게시판·회원·구분·작업 요청
     * @return JsonResponse 변경 게시판 수·제외 수·변화 없음 수·제외 사유
     */
    public function bulkMembers(BulkBoardMembersRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->boardService->bulkUpdateBoardMembers(
                (bool) $validated['apply_all'],
                $validated['board_ids'] ?? [],
                $validated['user_uuids'],
                $validated['member_type'],
                $validated['mode'],
            );

            return $this->success(
                'sirsoft-board::messages.boards.bulk_members_success',
                $result,
                200,
                ['count' => $result['updated_count'], 'skipped' => $result['skipped_count'], 'unchanged' => $result['unchanged_count']]
            );
        } catch (PermissionEscalationException $e) {
            // 고른 게시판 중 액터가 부여할 수 없는 게시판 역할 — 전체 롤백됨(「모든 게시판」 은 그 게시판만 not_grantable 로 건너뛴다)
            return $this->error('exceptions.cannot_grant_unheld_permission', 403);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'sirsoft-board::messages.boards.bulk_members_failed');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.boards.bulk_members_failed', 500, $e->getMessage());
        }
    }
}
