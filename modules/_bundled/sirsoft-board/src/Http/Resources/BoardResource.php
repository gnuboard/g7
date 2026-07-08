<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시판 API 리소스
 *
 * 게시판 정보를 API 응답 형식으로 변환합니다.
 */
class BoardResource extends BaseApiResource
{
    use ChecksBoardPermission;

    /**
     * 게시판 상세 정보를 배열로 변환합니다 (관리자용 전체 정보).
     *
     * 관리자 페이지에서 사용하는 모든 게시판 정보를 포함합니다.
     * FormData 요청 시 원본 다국어 배열을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $isFormRequest = $this->isFormRequest($request);

        return [
            // 기본 정보
            'id' => $this->id,
            'name' => $isFormRequest ? $this->name : $this->getLocalizedName(),
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'type' => $this->type,
            'description' => $isFormRequest ? $this->description : $this->getLocalizedDescription(),

            // 페이지네이션 설정
            'per_page' => $this->per_page,
            'per_page_mobile' => $this->per_page_mobile,
            'order_by' => $this->order_by,
            'order_direction' => $this->order_direction,

            // 분류
            'categories' => $this->categories ?? [],

            // 기능 설정
            'show_view_count' => $this->show_view_count,
            'secret_mode' => $this->secret_mode,
            'use_comment' => $this->use_comment,
            'use_reply' => $this->use_reply,
            'max_reply_depth' => $this->max_reply_depth,
            'use_report' => $this->use_report,
            'comment_order' => $this->comment_order,
            'max_comment_depth' => $this->max_comment_depth,

            // 글자 수 제한
            'min_title_length' => $this->min_title_length,
            'max_title_length' => $this->max_title_length,
            'min_content_length' => $this->min_content_length,
            'max_content_length' => $this->max_content_length,
            'min_comment_length' => $this->min_comment_length,
            'max_comment_length' => $this->max_comment_length,

            // 키워드 필터링
            'blocked_keywords' => $this->formatBlockedKeywords(),

            // 파일 업로드 설정
            'use_file_upload' => $this->use_file_upload,
            'max_file_size' => $this->max_file_size,
            'max_file_count' => $this->max_file_count,
            'allowed_extensions' => $this->formatAllowedExtensions(),

            // 관리자 메뉴 등록 여부 (폼 토글 초기값). 폼 요청 시에만 포함.
            // 값은 Controller(getFormData)가 모델에 세팅한 is_in_admin_menu 속성에서 읽음.
            'add_to_menu' => $isFormRequest ? (bool) ($this->is_in_admin_menu ?? false) : null,

            // 표시 설정
            'new_display_hours' => $this->new_display_hours ?? 24,

            // 게시판 관리 인원 (역할 기반, 역할별 1회 조회로 통합)
            ...self::getBoardRoleData("sirsoft-board.{$this->slug}"),

            // 알림 설정
            'notify_author' => $this->notify_author,
            'notify_admin_on_post' => $this->notify_admin_on_post,

            // 타임스탬프 (UTC 저장 → 사용자 타임존 변환 출력)
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 조건부 포함: 게시판 권한 설정 정보
            'permissions' => ($request->has('include_permissions') || $request->routeIs('*.show'))
                ? static::formatPermissionsForFrontend($this->permissions ?? [])
                : null,

            // 조건부 포함: 카테고리별 게시글 개수
            'category_post_counts' => isset($this->category_post_counts)
                ? $this->category_post_counts
                : null,

            // 조건부 포함: 게시글 총 개수
            'posts_count' => isset($this->posts_count)
                ? $this->posts_count
                : null,

            // 조건부 포함: 사용자별 권한 정보 (can_* 형식)
            'user_abilities' => $request->has('include_user_abilities')
                ? $this->getUserBoardAbilities($request)
                : null,

            // 표준 권한 메타 (is_owner + permissions)
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 게시판 목록용 리소스를 배열로 변환합니다 (경량).
     *
     * 전체 게시판 목록 페이지에서 사용하는 최소 정보만 포함합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toListArray(?Request $request = null): array
    {
        $request = $request ?? request();

        return [
            'id' => $this->getValue('id'),
            'name' => $this->getLocalizedName(),
            'slug' => $this->getValue('slug'),
            'description' => $this->getLocalizedDescription(),
            'posts_count' => $this->getValue('posts_count') ?? 0,
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 게시글 목록 API 응답에 포함할 게시판 정보를 반환합니다 (User용).
     *
     * PostCollection::withBoardInfo()에 전달할 데이터를 생성합니다.
     *
     * @return array<string, mixed> 게시판 정보 배열
     */
    public function toBoardInfoForUser(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->getLocalizedName(),
            'description' => $this->getLocalizedDescription(),
            'type' => $this->type,
            'categories' => $this->categories ?? [],
            'show_category' => $this->hasCategories(),
            'settings' => [
                'use_file_upload' => $this->use_file_upload,
                'use_comment' => $this->use_comment,
                'use_reply' => $this->use_reply,
                'use_report' => $this->use_report,
                'secret_mode' => $this->secret_mode,
                'show_view_count' => $this->show_view_count,
                'per_page' => $this->per_page,
                'posts_per_page' => $this->per_page,
                'posts_per_page_mobile' => $this->per_page_mobile ?? $this->per_page,
                'new_display_hours' => $this->new_display_hours,
                'order_by' => $this->order_by instanceof \BackedEnum ? $this->order_by->value : ($this->order_by ?? 'created_at'),
                'order_direction' => $this->order_direction instanceof \BackedEnum ? $this->order_direction->value : ($this->order_direction ?? 'desc'),
            ],
        ];
    }

    /**
     * 게시글 목록 API 응답에 포함할 게시판 정보를 반환합니다 (Admin용).
     *
     * PostCollection::withBoardInfo()에 전달할 데이터를 생성합니다.
     *
     * @return array<string, mixed> 게시판 정보 배열
     */
    public function toBoardInfoForAdmin(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->getLocalizedName(),
            'type' => $this->type,
            'categories' => $this->categories ?? [],
            'show_category' => $this->hasCategories(),
            'settings' => [
                'use_file_upload' => $this->use_file_upload,
                'use_comment' => $this->use_comment,
                'use_reply' => $this->use_reply,
                'use_report' => $this->use_report,
                'secret_mode' => $this->secret_mode,
                'per_page' => $this->per_page,
                'per_page_mobile' => $this->per_page_mobile ?? $this->per_page,
                'order_by' => $this->order_by instanceof \BackedEnum ? $this->order_by->value : ($this->order_by ?? 'created_at'),
                'order_direction' => $this->order_direction instanceof \BackedEnum ? $this->order_direction->value : ($this->order_direction ?? 'desc'),
            ],
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 요청 판단
    // =========================================================================

    /**
     * 폼 데이터 요청 여부를 확인합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 폼 데이터 요청 여부
     */
    private function isFormRequest(Request $request): bool
    {
        return $request->routeIs('*.form-data') || $request->routeIs('*.copy');
    }

    // =========================================================================
    // 헬퍼 메서드 - 데이터 포맷팅
    // =========================================================================

    /**
     * 차단 키워드를 배열로 반환합니다.
     *
     * 폼/조회 요청 모두 배열로 반환합니다. 폼은 TagInput, 조회는 목록 표시에서
     * 동일하게 배열을 사용합니다 (categories 와 동일 패턴).
     *
     * @return array 차단 키워드 배열
     */
    private function formatBlockedKeywords(): array
    {
        return $this->blocked_keywords ?? [];
    }

    /**
     * 허용 확장자를 배열로 반환합니다.
     *
     * 폼/조회 요청 모두 배열로 반환합니다 (TagInput 입력과 일관).
     *
     * @return array 허용 확장자 배열
     */
    private function formatAllowedExtensions(): array
    {
        return $this->allowed_extensions ?? [];
    }

    /**
     * 게시판 역할에 할당된 사용자 목록을 반환합니다.
     *
     * @param  string  $roleIdentifier  역할 identifier
     * @return array 사용자 목록 [{id, name, email}, ...]
     */
    /**
     * 게시판 역할(manager/step) 사용자 정보를 1회 조회로 통합 반환합니다.
     *
     * 기존 getBoardRoleUsers() + getBoardRoleUserIds()가 역할별 2회씩 조회하던 것을
     * 역할별 1회 조회로 통합합니다.
     *
     * 복제(BoardService::copyBoard) 경로와 공유하기 위해 public static 으로 노출한다.
     * (copyBoard 가 동일 산출 구조를 재사용하도록 SSoT 통합)
     *
     * @param  string  $rolePrefix  역할 접두사 (예: "sirsoft-board.free")
     * @return array board_managers, board_steps, board_manager_ids, board_step_ids
     */
    public static function getBoardRoleData(string $rolePrefix): array
    {
        $result = [
            'board_managers' => [],
            'board_steps' => [],
            'board_manager_ids' => [],
            'board_step_ids' => [],
        ];

        $roles = Role::whereIn('identifier', [
            "{$rolePrefix}.manager",
            "{$rolePrefix}.step",
        ])->with('users')->get();

        foreach ($roles as $role) {
            $users = $role->users->map(fn ($user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->toArray();

            $ids = $role->users->pluck('uuid')->toArray();

            if (str_ends_with($role->identifier, '.manager')) {
                $result['board_managers'] = $users;
                $result['board_manager_ids'] = $ids;
            } else {
                $result['board_steps'] = $users;
                $result['board_step_ids'] = $ids;
            }
        }

        return $result;
    }

    // =========================================================================
    // 권한 관련 메서드
    // =========================================================================

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-board.boards.create',
            'can_update' => 'sirsoft-board.boards.update',
            'can_delete' => 'sirsoft-board.boards.delete',
        ];
    }

    /**
     * 권한 정보를 프론트엔드 형식으로 변환합니다 (객체 형식).
     *
     * 키의 점(.)을 언더스코어(_)로 변환하여 프론트엔드에서
     * dot notation 경로로 접근 가능하게 합니다.
     * 예: 'posts.list' -> 'posts_list'
     *
     * @param  array  $permissions  권한 배열 (키: permission_key, 값: [role_identifiers] or null)
     * @return array 프론트엔드 형식 객체 { 'posts_list': { _key, mode, roles } }
     */
    public static function formatPermissionsForFrontend(array $permissions): array
    {
        $formatted = [];

        foreach ($permissions as $key => $roles) {
            $frontendKey = str_replace('.', '_', $key);
            $isAll = $roles === null || (is_array($roles) && empty($roles));

            // roles 배열 통일
            if ($isAll) {
                $rolesArray = [];
            } elseif (is_array($roles)) {
                $rolesArray = $roles;
            } else {
                $rolesArray = [$roles];
            }

            $formatted[$frontendKey] = [
                '_key' => $frontendKey,
                'name' => $key,
                'mode' => $isAll ? 'all' : 'roles',
                'roles' => $rolesArray,
            ];
        }

        return $formatted;
    }

    /**
     * 현재 사용자의 게시판 권한 정보를 can_* 형식으로 반환합니다.
     *
     * DB의 permissions 테이블에서 해당 게시판의 권한들을 조회하여
     * 동적으로 권한을 체크합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, bool> 사용자 권한 정보 (키: can_* 형식, 값: true/false)
     */
    protected function getUserBoardAbilities(Request $request): array
    {
        /** @var User|null $user */
        $user = Auth::user();
        $slug = $this->getValue('slug');
        $isAdminRoute = $this->isAdminRoute($request);

        // 컨텍스트에 맞는 permissionMap 구성 (PostResource.resolveAbilities()와 동일 키)
        $permissionMap = $isAdminRoute
            ? [
                'can_read' => "sirsoft-board.{$slug}.admin.posts.read",
                'can_write' => "sirsoft-board.{$slug}.admin.posts.write",
                'can_read_secret' => "sirsoft-board.{$slug}.admin.posts.read-secret",
                'can_read_comments' => "sirsoft-board.{$slug}.admin.comments.read",
                'can_write_comments' => "sirsoft-board.{$slug}.admin.comments.write",
                'can_upload' => "sirsoft-board.{$slug}.admin.attachments.upload",
                'can_download' => "sirsoft-board.{$slug}.admin.attachments.download",
                'can_manage' => "sirsoft-board.{$slug}.admin.manage",
            ]
            : [
                'can_read' => "sirsoft-board.{$slug}.posts.read",
                'can_write' => "sirsoft-board.{$slug}.posts.write",
                'can_read_secret' => "sirsoft-board.{$slug}.posts.read-secret",
                'can_read_comments' => "sirsoft-board.{$slug}.comments.read",
                'can_write_comments' => "sirsoft-board.{$slug}.comments.write",
                'can_upload' => "sirsoft-board.{$slug}.attachments.upload",
                'can_download' => "sirsoft-board.{$slug}.attachments.download",
                'can_manage' => "sirsoft-board.{$slug}.manager",
                // 유저 화면에서 관리자 게시판 화면 진입 게이트 (Admin 타입 admin.manage 보유자만 true)
                'can_access_admin' => "sirsoft-board.{$slug}.admin.manage",
            ];

        // 권한 일괄 조회 (개별 Permission::where() → whereIn 1회)
        $identifiers = array_values($permissionMap);
        $permissions = Permission::whereIn('identifier', $identifiers)->get()->keyBy('identifier');

        $result = [];

        if ($user) {
            // 사용자의 역할별 권한을 한 번만 로드 (identifier + type 쌍으로 메모리 체크)
            $userPermissions = $user->roles()
                ->with('permissions')
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->map(fn ($p) => $p->identifier.'|'.($p->type instanceof \BackedEnum ? $p->type->value : $p->type))
                ->unique()
                ->toArray();

            foreach ($permissionMap as $canKey => $identifier) {
                $permission = $permissions->get($identifier);
                if (! $permission) {
                    continue;
                }

                $typeValue = $permission->type instanceof \BackedEnum ? $permission->type->value : $permission->type;
                $result[$canKey] = in_array($permission->identifier.'|'.$typeValue, $userPermissions);
            }
        } else {
            foreach ($permissionMap as $canKey => $identifier) {
                $permission = $permissions->get($identifier);
                if (! $permission) {
                    continue;
                }

                $result[$canKey] = $this->checkGuestPermission($permission);
            }
        }

        return $result;
    }

    /**
     * 비회원(Guest)의 권한을 확인합니다.
     *
     * Guest role에 해당 권한이 부여되어 있는지 확인합니다.
     *
     * @param  Permission  $permission  권한 객체
     * @return bool 권한 보유 여부
     */
    private function checkGuestPermission(Permission $permission): bool
    {
        static $guestRole = null;

        if ($guestRole === null) {
            $guestRole = Role::where('identifier', 'guest')->first();
        }

        if (! $guestRole) {
            return false;
        }

        return $guestRole->permissions()->where('permissions.id', $permission->id)->exists();
    }

    /**
     * Admin 라우트 여부를 확인합니다.
     *
     * Controller 네임스페이스로 판단합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool Admin 라우트 여부
     */
    private function isAdminRoute(Request $request): bool
    {
        $controller = $request->route()?->getController();

        if (! $controller) {
            return false;
        }

        return str_contains(get_class($controller), '\\Admin\\');
    }
}
