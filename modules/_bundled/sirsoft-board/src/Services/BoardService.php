<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use App\Services\RoleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Exceptions\BulkApplyAbortedException;
use Modules\Sirsoft\Board\Exceptions\MenuAlreadyExistsException;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkApplySettingsRequest;
use Modules\Sirsoft\Board\Http\Resources\BoardResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시판 관리 서비스 클래스
 *
 * 게시판의 생성, 수정, 삭제 등 비즈니스 로직을 담당하며,
 * 훅 시스템과 캐시 관리 기능을 제공합니다.
 */
class BoardService
{
    use ClearsTemplateCaches;

    /**
     * BoardService 생성자
     *
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
     * @param  PostRepositoryInterface  $postRepository  게시글 리포지토리
     * @param  CommentRepositoryInterface  $commentRepository  댓글 리포지토리
     * @param  AttachmentRepositoryInterface  $attachmentRepository  첨부파일 리포지토리
     * @param  BoardPermissionService  $permissionService  게시판 권한 서비스
     * @param  MenuService  $menuService  메뉴 서비스
     * @param  RoleService  $roleService  역할 서비스
     * @param  CacheInterface  $cache  캐시 드라이버
     * @param  MenuRepositoryInterface  $menuRepository  코어 메뉴 리포지토리 (메뉴 조회용)
     */
    public function __construct(
        private BoardRepositoryInterface $boardRepository,
        private PostRepositoryInterface $postRepository,
        private CommentRepositoryInterface $commentRepository,
        private AttachmentRepositoryInterface $attachmentRepository,
        private BoardPermissionService $permissionService,
        private MenuService $menuService,
        private RoleService $roleService,
        private CacheInterface $cache,
        private MenuRepositoryInterface $menuRepository,
        private StorageInterface $storage
    ) {}

    /**
     * 게시판 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator
     */
    public function getBoards(array $filters = [])
    {
        $query = $this->boardRepository->query();

        // 권한 스코프 필터링 (본인만/역할/전체)
        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.boards.read');

        // 타입 필터
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // 검색어 필터 (name, slug)
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);

            $query->where(function ($q) use ($search, $locales) {
                $q->where('slug', 'like', "%{$search}%");

                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$search}%");
                }
            });
        }

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // 페이지네이션
        $perPage = $filters['per_page'] ?? 20;

        return $query->paginate($perPage);
    }

    /**
     * ID로 게시판을 조회합니다.
     *
     * @param  int  $id  게시판 ID
     *
     * @throws ModelNotFoundException
     */
    public function getBoard(int $id): Board
    {
        $board = $this->boardRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($board, 'sirsoft-board.boards.read')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        return $board;
    }

    /**
     * 슬러그로 게시판을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  bool  $checkScope  admin scope 체크 여부 (User 컨텍스트에서는 false)
     * @return Board|null 게시판 모델 (없으면 null)
     */
    public function getBoardBySlug(string $slug, bool $checkScope = true): ?Board
    {
        $board = $this->boardRepository->findBySlug($slug);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if ($checkScope && $board && ! PermissionHelper::checkScopeAccess($board, 'sirsoft-board.boards.read')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        return $board;
    }

    /**
     * 활성화된 게시판 목록을 조회합니다.
     *
     * @param  string  $orderBy  정렬 기준 (기본: created_at)
     * @param  string  $orderDirection  정렬 방향 (기본: desc)
     */
    public function getActiveBoards(
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ): Collection {
        return $this->boardRepository->getActiveBoardsOrdered($orderBy, $orderDirection);
    }

    /**
     * 메뉴용 경량 게시판 목록을 조회합니다.
     *
     * id, name, slug만 조회하며 posts COUNT 쿼리를 실행하지 않습니다.
     *
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoardsForMenu(): Collection
    {
        return $this->boardRepository->getActiveBoardsForMenu();
    }

    /**
     * 인기 게시판 목록을 조회합니다 (게시글 수 기준).
     *
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getPopularBoards(int $limit): array
    {
        // getActiveBoards()가 이미 posts_count를 단일 쿼리로 설정하므로 재사용
        $boards = $this->getActiveBoards();

        // 게시글 수 기준 정렬 후 제한
        return $boards->map(fn ($board) => [
            'id' => $board->id,
            'name' => $board->getLocalizedName(),
            'slug' => $board->slug,
            'description' => $board->getLocalizedDescription(),
            'posts_count' => $board->posts_count ?? 0,
        ])
            ->sortByDesc('posts_count')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * 게시판 통계를 캐시와 함께 조회합니다.
     *
     * @return array{users: int, boards: int, posts: int, comments: int}
     */
    public function getCachedStats(): array
    {
        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);

        return $this->cache->remember('stats', function () {
            $boardStats = $this->getActiveBoardStats();

            return [
                'users' => User::count(),
                'boards' => $boardStats['boards'],
                'posts' => $boardStats['posts'],
                'comments' => $boardStats['comments'],
            ];
        }, $ttl, tags: ['board-stats']);
    }

    /**
     * 최근 게시글을 캐시와 함께 조회합니다.
     *
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getCachedRecentPosts(int $limit): array
    {
        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);

        return $this->cache->remember(
            "recent_posts_{$limit}",
            fn () => $this->getRecentPosts($limit),
            $ttl,
            tags: ['board-posts']
        );
    }

    /**
     * 인기 게시글을 캐시와 함께 조회합니다.
     *
     * 캐시 키에 locale을 포함하지 않습니다 (쿼리 결과는 locale에 무관).
     *
     * @param  string  $period  기간 (today, week, month, year)
     * @param  int  $limit  조회 개수
     */
    public function getCachedPopularPosts(string $period = 'week', int $limit = 20): array
    {
        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);

        return $this->cache->remember(
            "popular_posts_{$period}_{$limit}",
            fn () => $this->getPopularPosts($period, $limit),
            $ttl,
            tags: ['board-posts']
        );
    }

    /**
     * 인기 게시판을 캐시와 함께 조회합니다.
     *
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getCachedPopularBoards(int $limit): array
    {
        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);

        return $this->cache->remember(
            "popular_boards_{$limit}",
            fn () => $this->getPopularBoards($limit),
            $ttl,
            tags: ['board-list']
        );
    }

    /**
     * 메뉴용 게시판 목록을 캐시와 함께 조회합니다.
     *
     * @return Collection 활성 게시판 컬렉션
     */
    public function getCachedActiveBoardsForMenu(): Collection
    {
        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);

        return $this->cache->remember(
            'board_menu',
            fn () => $this->getActiveBoardsForMenu(),
            $ttl,
            tags: ['board-menu']
        );
    }

    /**
     * 게시판을 생성합니다.
     *
     * @param  array  $data  게시판 생성 데이터
     * @return Board 생성된 게시판
     *
     * @throws \Exception 역할/권한 생성 실패 시 롤백
     */
    public function createBoard(array $data): Board
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-board.board.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-board.board.filter_create_data', $data);

        // name이 문자열이면 다국어 형식으로 변환
        if (isset($data['name']) && is_string($data['name'])) {
            $data['name'] = [
                config('app.locale', 'ko') => $data['name'],
            ];
        }

        // 생성자/수정자 정보 추가
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        // DB에 저장되지 않는 필드 분리 (별도 처리)
        $permissions = $data['permissions'] ?? [];
        $boardManagerIds = $data['board_manager_ids'] ?? null;
        $boardStepIds = $data['board_step_ids'] ?? null;
        $addToMenu = (bool) ($data['add_to_menu'] ?? false);
        unset($data['permissions'], $data['board_manager_ids'], $data['board_step_ids'], $data['add_to_menu']);

        // 게시판 생성
        $board = $this->boardRepository->create($data);

        // 게시판별 관리자/스텝 역할 생성
        try {
            $this->createBoardRoles($board);
            HookManager::doAction('sirsoft-board.roles.after_create', $board);
        } catch (\Exception $e) {
            Log::error('Board role creation failed', [
                'board_id' => $board->id,
                'slug' => $board->slug,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            // 역할 생성 실패 시 게시판 삭제 (롤백)
            $this->boardRepository->delete($board->id);
            throw $e;
        }

        // 게시판 권한 생성 (9개 권한 동적 생성)
        try {
            // 역할 생성 후, Manager/Step 역할을 permissions에 자동 추가
            $permissions = $this->injectBoardRolesToPermissions($permissions, $board->slug);

            $this->permissionService->ensureBoardPermissions($board, $permissions);
            HookManager::doAction('sirsoft-board.permissions.after_create', $board);
        } catch (\Exception $e) {
            Log::error('Board permission creation failed', [
                'board_id' => $board->id,
                'slug' => $board->slug,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            // 권한 생성 실패 시 역할 및 게시판 삭제 (롤백)
            $this->deleteBoardRoles($board);
            $this->boardRepository->delete($board->id);
            throw $e;
        }

        // 게시판 관리자/스텝 사용자 역할 동기화
        $syncData = [];
        if ($boardManagerIds !== null) {
            $syncData['board_manager_ids'] = $boardManagerIds;
        }
        if ($boardStepIds !== null) {
            $syncData['board_step_ids'] = $boardStepIds;
        }
        $this->syncBoardRoleUsers($board, $syncData);

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-board.board.after_create', $board, $data);

        // 관리자 메뉴 추가 토글 (선택). 메뉴는 부수 기능이므로 실패해도 게시판 생성은 롤백하지 않음.
        if ($addToMenu) {
            $this->tryAddToAdminMenu($board);
        }

        // 캐시 클리어
        $this->clearBoardCaches($board->slug, $board->id);

        // 게시판 생성 성공 로그 (info 레벨)
        Log::info('Board created successfully', [
            'board_id' => $board->id,
            'slug' => $board->slug,
            'type' => $board->type,
            'user_id' => Auth::id(),
        ]);

        return $board;
    }

    /**
     * 게시판을 수정합니다.
     *
     * @param  int  $id  게시판 ID
     * @param  array  $data  수정할 데이터
     * @return Board 수정된 게시판
     */
    public function updateBoard(int $id, array $data): Board
    {
        $board = $this->boardRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($board, 'sirsoft-board.boards.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-board.board.before_update', $board, $data);

        $snapshot = $board->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-board.board.filter_update_data', $data, $board);

        // 관리자 메뉴 토글 분리 (DB 컬럼 아님). null = 미전송(변경 없음)
        $addToMenu = array_key_exists('add_to_menu', $data) ? (bool) $data['add_to_menu'] : null;
        unset($data['add_to_menu']);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        // name이 문자열이면 다국어 형식으로 변환
        if (isset($data['name']) && is_string($data['name'])) {
            $data['name'] = [
                config('app.locale', 'ko') => $data['name'],
            ];
        }

        // 게시판 수정
        $updatedBoard = $this->boardRepository->update($id, $data);

        // 권한 업데이트 (permissions 테이블은 유지, role_permissions만 동기화)
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            try {
                $this->permissionService->updateBoardPermissions($updatedBoard, $data['permissions']);
                HookManager::doAction('sirsoft-board.permissions.after_update', $updatedBoard);
            } catch (\Exception $e) {
                Log::error('게시판 권한 업데이트 실패', [
                    'board_id' => $updatedBoard->id,
                    'slug' => $updatedBoard->slug,
                    'error' => $e->getMessage(),
                ]);
                // 권한 업데이트 실패 시에도 게시판 수정은 완료된 상태
            }
        }

        // 게시판 이름 변경 시 연관 역할명 동기화
        if (isset($data['name'])) {
            $this->syncBoardRoleNames($updatedBoard);
        }

        // 게시판 관리자/스텝 사용자 역할 동기화
        $this->syncBoardRoleUsers($updatedBoard, $data);

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-board.board.after_update', $updatedBoard, $data, $snapshot);

        // 관리자 메뉴 토글 적용 (변화분만 반영). 미전송이면 무동작.
        if ($addToMenu !== null) {
            $this->syncAdminMenu($updatedBoard, $addToMenu);
        }

        // 캐시 클리어
        $this->clearBoardCaches($updatedBoard->slug, $updatedBoard->id);

        return $updatedBoard;
    }

    /**
     * 게시판을 삭제합니다.
     *
     * 게시판 레코드 및 관련된 모든 데이터를 영구 삭제합니다.
     *
     * @param  int  $id  게시판 ID
     * @param  bool  $forceDelete  사용되지 않음 (하드 딜리트만 지원)
     */
    public function deleteBoard(int $id, bool $forceDelete = false): void
    {
        $board = $this->boardRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($board, 'sirsoft-board.boards.delete')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // Before 훅 - 삭제 전 검증/차단
        HookManager::doAction('sirsoft-board.board.before_delete', $board);

        // 그누보드7 규정: DB CASCADE 금지 → 어플리케이션에서 명시적 삭제
        DB::transaction(function () use ($board) {
            // 1. 첨부파일 스토리지 일괄 삭제 (물리 파일)
            // 첨부파일 저장 경로: attachments/{slug}/...
            $this->deleteAttachmentFiles($board->slug);

            // 2. 연관 데이터 영구 삭제 (board_id 기준)
            // 게시판을 영구 삭제하므로 하위 게시글·댓글·첨부 DB 레코드도 함께 영구 삭제한다
            // (소프트 삭제 시 게시판이 사라진 뒤 접근 불가한 고아 데이터로 잔존)
            $this->attachmentRepository->forceDeleteByBoardId($board->id);
            $this->commentRepository->forceDeleteByBoardId($board->id);
            $this->postRepository->forceDeleteByBoardId($board->id);

            // 3. 게시판 권한 삭제 (그누보드7 규정: detach 후 삭제)
            $this->permissionService->removeBoardPermissions($board);
            HookManager::doAction('sirsoft-board.permissions.after_delete', $board->slug);

            // 4. 게시판별 관리자/스텝 역할 삭제
            $this->deleteBoardRoles($board);
            HookManager::doAction('sirsoft-board.roles.after_delete', $board->slug);

            // 5. 등록된 메뉴 제거 (addToAdminMenu()로 등록된 경우에만)
            $menu = Menu::where('url', '/admin/board/'.$board->slug)->first();
            if ($menu) {
                $this->menuService->deleteMenu($menu);
            }

            // 6. 게시판 영구 삭제
            $board->forceDelete();
        });

        Log::info('Board deleted', [
            'board_id' => $board->id,
            'slug' => $board->slug,
            'user_id' => Auth::id(),
        ]);

        // After 훅 - 삭제 후 처리 (로그 등)
        HookManager::doAction('sirsoft-board.board.after_delete', $board);

        // 캐시 클리어
        $this->clearBoardCaches($board->slug, $board->id);
    }

    /**
     * 게시판 복사를 위한 데이터를 반환합니다.
     * 실제 게시판을 생성하지 않고, 복사할 데이터만 반환합니다.
     *
     * @param  int  $id  복사할 게시판 ID
     * @return array 복사할 게시판 데이터
     */
    public function copyBoard(int $id): array
    {
        $originalBoard = $this->boardRepository->findOrFail($id);

        // 권한 스코프 접근 체크 (본인만/역할/전체)
        if (! PermissionHelper::checkScopeAccess($originalBoard, 'sirsoft-board.boards.read')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // Before 훅 - 복사 전 검증
        HookManager::doAction('sirsoft-board.board.before_copy', $originalBoard);

        // 원본 게시판 설정 복사 (게시글은 복사하지 않음)
        $copyData = $originalBoard->toArray();

        // 자동 생성 필드 제거
        unset($copyData['id'], $copyData['created_at'], $copyData['updated_at']);

        // slug는 copy 붙여서 (사용자가 변경 필요함)
        $copyData['slug'] = $copyData['slug'].'-copy';

        // 이름에 (copy) 표시 (배열일 때만 처리)
        $nameValue = $originalBoard->name;
        if (is_string($nameValue)) {
            $decoded = json_decode($nameValue, true);
            if (is_array($decoded) && ! empty($decoded)) {
                // 다국어 필드 (JSON 배열)
                $copyData['name'] = array_map(function ($name) {
                    return $name.' (copy)';
                }, $decoded);
            }
        } elseif (is_array($nameValue) && ! empty($nameValue)) {
            // 이미 배열인 경우 (다국어 필드)
            $copyData['name'] = array_map(function ($name) {
                return $name.' (copy)';
            }, $nameValue);
        }

        // 역할/관계 파생 필드 보강
        // toArray() 는 DB 컬럼만 반환하므로, 폼이 필요로 하는 다음 필드가 누락된다:
        //  - board_manager_ids / board_managers / board_step_ids / board_steps (역할 기반)
        //  - permissions (권한 accessor 기반)
        // 이를 채우지 않으면 복제 폼 저장 시 board_manager_ids 누락으로 422,
        // permissions 부재로 권한 미복사가 발생한다.
        $originalSlug = $originalBoard->slug;

        // 1) 관리자/스텝 역할 데이터 — BoardResource 와 동일 산출 구조 재사용 (SSoT)
        $roleData = BoardResource::getBoardRoleData("sirsoft-board.{$originalSlug}");
        $copyData['board_manager_ids'] = $roleData['board_manager_ids'];
        $copyData['board_managers'] = $roleData['board_managers'];
        $copyData['board_step_ids'] = $roleData['board_step_ids'];
        $copyData['board_steps'] = $roleData['board_steps'];

        // 2) 권한 정보 — 옛 slug 스코프(manager/step) identifier 는 제거하여 교차 게시판 누수 차단.
        //    새 게시판의 manager/step 역할은 생성 시 injectBoardRolesToPermissions 가 자동 주입한다.
        //    비-스코프 역할(member 등)은 그대로 보존된다.
        $copyData['permissions'] = $this->stripBoardScopeRoles(
            $originalBoard->permissions ?? [],
            $originalSlug
        );

        // 3) 관리자 메뉴 등록 토글 — 폼 Toggle(add_to_menu) 초기값.
        //    toArray() 에는 없는 파생 필드이므로, 누락 시 Toggle 이 undefined 로 초기화되어
        //    저장 시 boolean 검증에 걸린다(422). 원본 게시판의 메뉴 등록 상태를 승계한다.
        $copyData['add_to_menu'] = $this->isInAdminMenu($originalBoard);

        // After 훅 - 복사 데이터 필터 (보강된 5필드를 리스너가 참조/변형할 수 있도록 보강 이후 호출)
        $copyData = HookManager::applyFilters('sirsoft-board.board.filter_copy_data', $copyData, $originalBoard);

        return $copyData;
    }

    /**
     * 권한 배열에서 특정 게시판 스코프(manager/step) 역할 identifier 를 제거합니다.
     *
     * 복제 시 원본 게시판의 `sirsoft-board.{slug}.manager` / `.step` 역할이
     * 새 게시판 권한에 attach 되어 발생하는 교차 게시판 권한 누수를 차단합니다.
     * (attachRoles 가 전역 whereIn 조회라 board 스코프가 적용되지 않음)
     *
     * 새 게시판의 manager/step 역할은 생성 시 injectBoardRolesToPermissions 가
     * 자동 주입하므로 복원되며, 비-스코프 역할(member 등)은 그대로 보존됩니다.
     *
     * @param  array  $permissions  권한 배열 (키: permission_key, 값: [role_identifiers] or null)
     * @param  string  $slug  제거 대상 게시판 slug
     * @return array 스코프 역할이 제거된 권한 배열
     */
    private function stripBoardScopeRoles(array $permissions, string $slug): array
    {
        $scopedRoles = [
            "sirsoft-board.{$slug}.manager",
            "sirsoft-board.{$slug}.step",
        ];

        foreach ($permissions as $key => $roles) {
            if (! is_array($roles)) {
                // null(전체 허용) 또는 단일 값은 그대로 둔다
                continue;
            }

            $filtered = array_values(array_diff($roles, $scopedRoles));
            $permissions[$key] = $filtered;
        }

        return $permissions;
    }

    /**
     * 게시판별 관리자/스텝 역할을 생성합니다.
     *
     * @param  Board  $board  대상 게시판
     * @return array{manager: Role, step: Role} 생성된 역할 배열
     */
    private function createBoardRoles(Board $board): array
    {
        $boardNameKo = $board->name['ko'] ?? $board->name['en'] ?? $board->slug;
        $boardNameEn = $board->name['en'] ?? $board->name['ko'] ?? $board->slug;

        // 중복 방어: 이전 삭제 실패 등으로 role이 남아있을 수 있음
        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.manager"],
            [
                'name' => [
                    'ko' => "{$boardNameKo} 게시판 관리자",
                    'en' => "{$boardNameEn} Board Manager",
                ],
                'description' => [
                    'ko' => "{$boardNameKo} 게시판의 관리자 역할",
                    'en' => "Manager role for {$boardNameEn} board",
                ],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => 'sirsoft-board',
            ]
        );

        $stepRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.step"],
            [
                'name' => [
                    'ko' => "{$boardNameKo} 게시판 스텝",
                    'en' => "{$boardNameEn} Board Step",
                ],
                'description' => [
                    'ko' => "{$boardNameKo} 게시판의 스텝 역할",
                    'en' => "Step role for {$boardNameEn} board",
                ],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => 'sirsoft-board',
            ]
        );

        return ['manager' => $managerRole, 'step' => $stepRole];
    }

    /**
     * 게시판별 관리자/스텝 역할을 삭제합니다.
     *
     * @param  Board  $board  대상 게시판
     */
    private function deleteBoardRoles(Board $board): void
    {
        $roleIdentifiers = [
            "sirsoft-board.{$board->slug}.manager",
            "sirsoft-board.{$board->slug}.step",
        ];

        $roles = Role::whereIn('identifier', $roleIdentifiers)->get();

        foreach ($roles as $role) {
            // 그누보드7 규정: detach 후 삭제
            $role->permissions()->detach();
            $role->users()->detach();
            $role->delete();
        }
    }

    /**
     * 게시판명 변경 시 연관 역할(관리자/스텝)의 이름을 동기화합니다.
     *
     * @param  Board  $board  수정된 게시판
     */
    private function syncBoardRoleNames(Board $board): void
    {
        $boardNameKo = $board->name['ko'] ?? $board->name['en'] ?? $board->slug;
        $boardNameEn = $board->name['en'] ?? $board->name['ko'] ?? $board->slug;

        $roleUpdates = [
            "sirsoft-board.{$board->slug}.manager" => [
                'name' => [
                    'ko' => "{$boardNameKo} 게시판 관리자",
                    'en' => "{$boardNameEn} Board Manager",
                ],
                'description' => [
                    'ko' => "{$boardNameKo} 게시판의 관리자 역할",
                    'en' => "Manager role for {$boardNameEn} board",
                ],
            ],
            "sirsoft-board.{$board->slug}.step" => [
                'name' => [
                    'ko' => "{$boardNameKo} 게시판 스텝",
                    'en' => "{$boardNameEn} Board Step",
                ],
                'description' => [
                    'ko' => "{$boardNameKo} 게시판의 스텝 역할",
                    'en' => "Step role for {$boardNameEn} board",
                ],
            ],
        ];

        foreach ($roleUpdates as $identifier => $attributes) {
            Role::where('identifier', $identifier)->update($attributes);
        }
    }

    /**
     * 게시판 관리자/스텝 역할에 사용자를 동기화합니다.
     *
     * @param  Board  $board  대상 게시판
     * @param  array  $data  요청 데이터 (board_manager_ids, board_step_ids 포함)
     */
    private function syncBoardRoleUsers(Board $board, array $data): void
    {
        $roleMap = [
            'board_manager_ids' => "sirsoft-board.{$board->slug}.manager",
            'board_step_ids' => "sirsoft-board.{$board->slug}.step",
        ];

        foreach ($roleMap as $dataKey => $roleIdentifier) {
            if (! array_key_exists($dataKey, $data)) {
                continue;
            }

            $role = Role::where('identifier', $roleIdentifier)->first();
            if (! $role) {
                Log::warning('게시판 역할을 찾을 수 없습니다.', [
                    'board_slug' => $board->slug,
                    'role_identifier' => $roleIdentifier,
                ]);

                continue;
            }

            $userUuids = $data[$dataKey] ?? [];
            $userIds = User::whereIn('uuid', $userUuids)->pluck('id')->toArray();
            $role->users()->sync($userIds);
        }
    }

    /**
     * 게시판 관련 캐시를 클리어합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int|null  $id  게시판 ID
     */
    private function clearBoardCaches(string $slug, ?int $id = null): void
    {
        // 모듈 캐시 (ModuleCacheDriver — `g7:module.sirsoft-board:` 접두사 자동 적용)
        $this->cache->forget('boards:list');

        if ($id) {
            $this->cache->forget("boards:id:{$id}");
        }

        $this->cache->forget("boards:slug:{$slug}");

        // 파생 캐시 태그 무효화 (통계, 게시글, 메뉴, 게시판 목록)
        $this->cache->flushTags(['board-stats']);
        $this->cache->flushTags(['board-posts']);
        $this->cache->flushTags(['board-menu']);
        $this->cache->flushTags(['board-list']);

        // 템플릿 라우트 캐시 클리어
        $this->clearAllTemplateRoutesCaches();
    }

    /**
     * 전체 게시판 캐시를 무효화합니다 (외부 호출용).
     */
    public function clearAllBoardCaches(): void
    {
        $this->cache->forget('boards:list');
        $this->cache->flushTags(['board-stats']);
        $this->cache->flushTags(['board-posts']);
        $this->cache->flushTags(['board-menu']);
        $this->cache->flushTags(['board-list']);
    }

    /**
     * 전체 게시글 수를 집계합니다.
     *
     * @return int 전체 게시글 개수
     */
    public function getTotalPostsCount(): int
    {
        return $this->boardRepository->getTotalPostsCount();
    }

    /**
     * 전체 댓글 수를 집계합니다.
     *
     * @return int 전체 댓글 개수
     */
    public function getTotalCommentsCount(): int
    {
        return $this->boardRepository->getTotalCommentsCount();
    }

    /**
     * 활성 게시판의 통계를 조회합니다 (게시판 수, 게시글 수, 댓글 수).
     *
     * @return array{boards: int, posts: int, comments: int}
     */
    public function getActiveBoardStats(): array
    {
        $stats = $this->boardRepository->getActiveBoardStats();

        return [
            'boards' => (int) $stats->boards_count,
            'posts' => (int) $stats->posts_total,
            'comments' => (int) $stats->comments_total,
        ];
    }

    /**
     * 최근 게시글을 조회합니다. (UNION)
     *
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getRecentPosts(int $limit): array
    {
        return $this->boardRepository->getRecentPosts($limit);
    }

    /**
     * 인기 게시글 목록 조회
     *
     * @param  string  $period  기간 (today, week, month, all)
     * @param  int  $limit  조회 개수
     */
    public function getPopularPosts(string $period = 'week', int $limit = 20): array
    {
        return $this->boardRepository->getPopularPosts($period, $limit);
    }

    /**
     * 특정 게시판의 최근 게시물을 조회합니다.
     *
     * 단일 게시판의 최근 게시물을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getBoardRecentPosts(string $slug, int $limit): array
    {
        return $this->boardRepository->getBoardRecentPosts($slug, $limit);
    }

    /**
     * 게시판 ID로 최근 게시물을 조회합니다 (slug 재조회 없음).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getBoardRecentPostsById(int $boardId, int $limit): array
    {
        return $this->boardRepository->getBoardRecentPostsById($boardId, $limit);
    }

    /**
     * 게시판을 관리자 메뉴에 추가합니다.
     *
     * @param  int  $id  게시판 ID
     * @return Menu 생성된 메뉴
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없을 때
     * @throws MenuAlreadyExistsException 동일한 URL의 메뉴가 이미 존재할 때
     */
    public function addToAdminMenu(int $id): Menu
    {
        // Before Hook: 메뉴 추가 전
        HookManager::doAction('sirsoft-board.board.before_add_to_menu', $id);

        // 게시판 조회
        $board = $this->getBoard($id);

        $menuUrl = '/admin/board/'.$board->slug;

        // 중복 체크: 동일한 URL의 메뉴가 이미 존재하는지 확인
        $existingMenu = Menu::where('url', $menuUrl)->first();

        if ($existingMenu) {
            throw new MenuAlreadyExistsException(__('sirsoft-board::messages.boards.menu_already_exists'));
        }

        // 부모 메뉴("게시판 관리", slug=sirsoft-board) 하위로 등록. 부모 미존재 시 최상위(null)로 폴백.
        $parentMenu = $this->menuRepository->findBySlug('sirsoft-board');

        // 메뉴 데이터 준비
        $menuData = [
            'name' => $this->buildMenuName($board),  // 다국어 필드 (게시판명 + "게시판" 접미사)
            'slug' => 'board-'.$board->slug,
            'url' => $menuUrl,
            'icon' => 'fas fa-clipboard-list',
            'parent_id' => $parentMenu?->id,
            'is_active' => true,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
        ];

        // Filter Hook: 메뉴 데이터 필터링
        $menuData = HookManager::applyFilters('sirsoft-board.board.filter_menu_data', $menuData, $board);

        // 메뉴 생성 (코어 MenuService 사용 - 코어 수정 없음)
        $menu = $this->menuService->createMenu($menuData);

        // After Hook: 메뉴 추가 후
        HookManager::doAction('sirsoft-board.board.after_add_to_menu', $menu, $board);

        return $menu;
    }

    /**
     * 게시판이 관리자 메뉴에 등록되어 있는지 확인합니다.
     *
     * 메뉴 식별은 URL(/admin/board/{slug}) 기준입니다.
     *
     * @param  Board  $board  대상 게시판
     * @return bool 등록 여부
     */
    public function isInAdminMenu(Board $board): bool
    {
        return $this->menuRepository->findBySlug('board-'.$board->slug) !== null;
    }

    /**
     * 게시판을 관리자 메뉴에서 제거합니다.
     *
     * 등록된 메뉴가 없으면 무동작입니다. 메뉴 식별은 URL(/admin/board/{slug}) 기준입니다.
     *
     * @param  int  $id  게시판 ID
     * @return bool 제거 성공 여부 (제거할 메뉴가 없으면 false)
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없을 때
     */
    public function removeFromAdminMenu(int $id): bool
    {
        // Before Hook: 메뉴 제거 전
        HookManager::doAction('sirsoft-board.board.before_remove_from_menu', $id);

        $board = $this->getBoard($id);

        $menu = $this->menuRepository->findBySlug('board-'.$board->slug);

        if (! $menu) {
            return false;
        }

        // 메뉴 삭제 (코어 MenuService 사용 - 코어 수정 없음)
        $this->menuService->deleteMenu($menu);

        // After Hook: 메뉴 제거 후 (활동 로그용)
        HookManager::doAction('sirsoft-board.board.after_remove_from_menu', $menu, $board);

        return true;
    }

    /**
     * 관리자 메뉴에 표시할 다국어 메뉴명을 생성합니다 (게시판명 + "게시판" 접미사).
     *
     * 예: {"ko":"공지사항","en":"Notice"} → {"ko":"공지사항 게시판","en":"Notice Board"}
     *
     * @param  Board  $board  대상 게시판
     * @return array<string, string> 로케일별 메뉴명
     */
    private function buildMenuName(Board $board): array
    {
        $names = is_array($board->name) ? $board->name : [config('app.locale', 'ko') => (string) $board->name];

        $result = [];
        foreach ($names as $locale => $value) {
            if ($value === null || $value === '') {
                $result[$locale] = $value;

                continue;
            }
            $suffix = __('sirsoft-board::messages.boards.menu_name_suffix', [], $locale);
            $result[$locale] = trim($value.' '.$suffix);
        }

        return $result;
    }

    /**
     * 관리자 메뉴 등록 상태를 토글 값에 맞춰 동기화합니다 (변화분만 반영).
     *
     * @param  Board  $board  대상 게시판
     * @param  bool  $shouldBeInMenu  메뉴에 표시해야 하는지 여부
     */
    private function syncAdminMenu(Board $board, bool $shouldBeInMenu): void
    {
        $isInMenu = $this->menuRepository->findBySlug('board-'.$board->slug) !== null;

        if ($shouldBeInMenu && ! $isInMenu) {
            $this->tryAddToAdminMenu($board);
        } elseif (! $shouldBeInMenu && $isInMenu) {
            $this->removeFromAdminMenu($board->id);
        }
    }

    /**
     * 관리자 메뉴 추가를 시도합니다 (실패해도 게시판 생성/수정 본체는 롤백하지 않음).
     *
     * 동일 URL 메뉴가 이미 존재하면 조용히 스킵합니다. 메뉴는 부수 편의 기능이므로
     * 메뉴 단계의 실패가 게시판 작업 전체를 실패시키지 않도록 방어적으로 처리합니다.
     *
     * @param  Board  $board  대상 게시판
     */
    private function tryAddToAdminMenu(Board $board): void
    {
        try {
            $this->addToAdminMenu($board->id);
        } catch (MenuAlreadyExistsException $e) {
            // 이미 등록된 동일 게시판 메뉴 - 스킵
            Log::info('Admin menu already exists, skipped', [
                'board_id' => $board->id,
                'slug' => $board->slug,
            ]);
        } catch (\Exception $e) {
            Log::warning('Admin menu registration failed, skipped', [
                'board_id' => $board->id,
                'slug' => $board->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // 통합 검색 메서드
    // =========================================================================

    /**
     * 활성화된 게시판 목록을 조회합니다 (통합 검색용).
     *
     * @param  string|null  $slug  특정 게시판 슬러그 (null이면 전체)
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoardsForSearch(?string $slug = null): Collection
    {
        return $this->boardRepository->getActiveBoards($slug);
    }

    /**
     * 필터용 전체 활성 게시판 목록을 배열로 반환합니다.
     *
     * @return array<int, array{slug: string, name: string}> 활성 게시판 목록
     */
    public function getActiveBoardsListForFilter(): array
    {
        return $this->boardRepository->getActiveBoardsList()
            ->map(fn ($board) => [
                'slug' => $board->slug,
                'name' => $board->getLocalizedName(),
            ])
            ->toArray();
    }

    /**
     * 환경설정 기본값을 기존 게시판에 일괄 적용합니다.
     *
     * 선택된 필드의 환경설정 기본값을 boards 테이블에 반영합니다.
     * 권한 관련 필드(default_board_permissions)는 권한 서비스를 통해 별도 처리합니다.
     * overrideValues가 제공된 경우 DB에 저장된 기본값 대신 해당 값을 사용합니다.
     *
     * 컬럼 업데이트와 권한 적용을 단일 트랜잭션으로 처리하여, 한 지점이라도
     * 실패하면 전체 변경을 롤백합니다(원자적 처리, 부분 적용 방지). 첫 실패에서
     * 즉시 중단하고 BulkApplyAbortedException 을 던지며, 캐시 무효화·성공 훅은
     * 트랜잭션이 성공한 뒤에만 실행합니다.
     *
     * @param  array<string>  $fields  적용할 필드 목록
     * @param  bool  $applyAll  전체 게시판 적용 여부
     * @param  array<int>  $boardIds  특정 게시판 ID 목록 (applyAll=false일 때 사용)
     * @param  array<string, mixed>  $overrideValues  저장 없이 직접 적용할 값 (비어있으면 DB 기본값 사용)
     * @return int 업데이트된 게시판 수
     *
     * @throws BulkApplyAbortedException 일괄 적용 중 한 지점이라도 실패한 경우 (전체 롤백됨)
     */
    public function bulkApplySettings(array $fields, bool $applyAll, array $boardIds = [], array $overrideValues = []): int
    {
        // Before 훅
        HookManager::doAction('sirsoft-board.settings.before_bulk_apply', $fields, $applyAll, $boardIds);

        // 환경설정 기본값 조회 (overrideValues가 있으면 병합하여 우선 적용)
        $basicDefaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        if (! empty($overrideValues)) {
            $basicDefaults = array_merge($basicDefaults, $overrideValues);
        }

        // boards 테이블 컬럼 필드와 권한 필드 분리
        $boardColumnFields = BulkApplySettingsRequest::getBoardColumnFields();
        $permissionFields = BulkApplySettingsRequest::getPermissionFields();

        // 컬럼 업데이트 데이터 구성
        $updateData = [];
        foreach ($fields as $field) {
            if (in_array($field, $boardColumnFields) && array_key_exists($field, $basicDefaults)) {
                $updateData[$field] = $basicDefaults[$field];
            }
        }

        // 권한 필드 처리 (개별 권한 키: manager, posts.read, admin.manage 등)
        $selectedPermissionKeys = array_values(array_filter(
            $fields,
            fn (string $f) => str_contains($f, '.') || in_array($f, $permissionFields, true)
        ));

        // 권한 적용 대상 게시판 조회 (순번 식별 및 총 대상 수 계산)
        $boards = collect();
        $selectedPermissions = [];
        if (! empty($selectedPermissionKeys) && isset($basicDefaults['default_board_permissions'])) {
            $allDefaultPermissions = $basicDefaults['default_board_permissions'];

            // 선택된 키만 필터링 (전체 선택이어도 동일하게 처리)
            $selectedPermissions = array_intersect_key(
                $allDefaultPermissions,
                array_flip($selectedPermissionKeys)
            );

            $query = $this->boardRepository->query();
            if (! $applyAll && ! empty($boardIds)) {
                $query->whereIn('id', $boardIds);
            }
            $boards = $query->get();
        }

        $total = $boards->count();

        try {
            $updatedCount = DB::transaction(function () use ($updateData, $applyAll, $boardIds, $boards, $selectedPermissions, $total) {
                $count = 0;

                // boards 테이블 컬럼 일괄 업데이트 (단일 DML — 실패 시 게시판 특정 불가)
                if (! empty($updateData)) {
                    try {
                        $count = $this->boardRepository->bulkUpdate($updateData, $applyAll, $boardIds);
                    } catch (\Throwable $e) {
                        throw BulkApplyAbortedException::forColumns($total, $e);
                    }
                }

                // 권한 적용 루프 (첫 실패에서 즉시 중단 → 전체 롤백)
                if (! empty($selectedPermissions)) {
                    foreach ($boards as $index => $board) {
                        try {
                            $this->permissionService->updateBoardPermissions(
                                $board,
                                $this->convertPermissionsFormat($selectedPermissions, $board->slug),
                                array_keys($selectedPermissions)
                            );
                        } catch (BulkApplyAbortedException $e) {
                            throw $e;
                        } catch (\Throwable $e) {
                            throw BulkApplyAbortedException::forBoard($board, $index, $total, $e);
                        }
                    }

                    // 권한만 적용한 경우에도 카운트 반영
                    if (empty($updateData)) {
                        $count = $total;
                    }
                }

                return $count;
            });
        } catch (BulkApplyAbortedException $e) {
            // 전체 롤백 완료 — 추적용 로그 + aborted 훅 발화 후 재throw
            Log::warning('게시판 환경설정 일괄 적용 중단 (전체 롤백)', [
                'failed_board' => $e->boardInfo(),
                'failed_at' => $e->failedAt,
                'total' => $e->total,
                'fields' => $fields,
                'error' => $e->getPrevious()?->getMessage(),
            ]);

            // 훅 인자는 큐 직렬화(HookArgumentSerializer)를 거치므로 Exception 객체는 전달 불가
            // (Model/Enum/Collection/스칼라/배열만 보존). 따라서 중단 정보를 배열로 전달한다.
            HookManager::doAction('sirsoft-board.settings.after_bulk_apply_aborted', $fields, $e->toLogContext());

            throw $e;
        }

        // 캐시 초기화 (boards:list 및 파생 태그 일괄 무효화) — 성공 후에만
        $this->clearAllBoardCaches();

        // After 훅 — 성공 후에만
        HookManager::doAction('sirsoft-board.settings.after_bulk_apply', $fields, $updatedCount);

        return $updatedCount;
    }

    /**
     * 환경설정 권한 형식을 게시판 권한 업데이트 형식으로 변환합니다.
     *
     * 환경설정의 default_board_permissions 구조를 updateBoardPermissions()에 맞게 변환합니다.
     * 'key.name' → 'key_name' 형식의 프론트엔드 키로 변환합니다.
     * (Manager/Step 자동주입 없음 — 일괄적용 시 해당 게시판의 manager/step 역할을 건드리지 않음)
     *
     * @param  array<string, array<string>>  $defaultPermissions  환경설정 권한 기본값
     * @param  string  $slug  게시판 슬러그
     * @return array<string, array{roles: array<string>}> 변환된 권한 배열
     */
    private function convertPermissionsFormat(array $defaultPermissions, string $slug): array
    {
        $permissions = [];
        foreach ($defaultPermissions as $key => $roles) {
            $frontendKey = str_replace('.', '_', $key);
            $permissions[$frontendKey] = ['roles' => is_array($roles) ? $roles : []];
        }

        return $permissions;
    }

    /**
     * 게시판 첨부파일 디렉토리를 스토리지에서 일괄 삭제합니다.
     *
     * 첨부파일 저장 경로는 슬러그 기준입니다 (AttachmentService::upload):
     *  - 최종: {slug}/{Y/m/d}/{filename}
     *  - 임시: {slug}/temp/{tempKey}/{filename}
     * 따라서 StorageInterface::deleteDirectory('attachments', '{slug}') 로
     * 해당 게시판의 최종·임시 첨부 파일을 한 번에 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     */
    private function deleteAttachmentFiles(string $slug): void
    {
        try {
            $this->storage->deleteDirectory('attachments', $slug);
        } catch (\Exception $e) {
            Log::warning('게시판 첨부파일 스토리지 삭제 실패 (계속 진행)', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * permissions 배열에 게시판별 Manager/Step 역할을 추가합니다.
     *
     * @param  array  $permissions  권한 설정 배열
     * @param  string  $slug  게시판 slug
     * @return array Manager/Step이 추가된 권한 설정 배열
     */
    private function injectBoardRolesToPermissions(array $permissions, string $slug): array
    {
        $managerIdentifier = "sirsoft-board.{$slug}.manager";
        $stepIdentifier = "sirsoft-board.{$slug}.step";
        $stepExcludedKeys = ['admin.manage', 'manager'];

        // permissions가 비어있으면 모듈 환경설정 기본값을 기반으로 생성
        if (empty($permissions)) {
            $basicDefaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);
            $configDefaults = $basicDefaults['default_board_permissions'] ?? [];

            // 모듈 설정이 없으면 config 파일의 권한 정의 키를 기반으로 fallback
            if (empty($configDefaults)) {
                $permissionDefinitions = config('sirsoft-board.board_permission_definitions', []);
                $configDefaults = array_fill_keys(array_keys($permissionDefinitions), []);
            }

            foreach ($configDefaults as $key => $roles) {
                $defaultRoles = is_array($roles) ? $roles : [];

                // Manager 역할 추가 (모든 권한)
                $defaultRoles[] = $managerIdentifier;

                // Step 역할 추가 (admin.manage, manager 제외)
                if (! in_array($key, $stepExcludedKeys)) {
                    $defaultRoles[] = $stepIdentifier;
                }

                $frontendKey = str_replace('.', '_', $key);
                $permissions[$frontendKey] = ['roles' => $defaultRoles];
            }

            return $permissions;
        }

        // 프론트엔드에서 보낸 permissions에 Manager/Step 주입
        foreach ($permissions as $key => $permData) {
            if (! isset($permData['roles']) || ! is_array($permData['roles'])) {
                continue;
            }

            $roles = $permData['roles'];

            // 이미 포함되어 있으면 중복 추가하지 않음
            if (in_array($managerIdentifier, $roles)) {
                continue;
            }

            // Manager 역할 추가 (모든 권한)
            $roles[] = $managerIdentifier;

            // Step 역할 추가 (admin.manage, manager 제외)
            $permKeyDot = str_replace('_', '.', $key);
            if (! in_array($permKeyDot, $stepExcludedKeys)) {
                $roles[] = $stepIdentifier;
            }

            $permissions[$key]['roles'] = $roles;
        }

        return $permissions;
    }
}
