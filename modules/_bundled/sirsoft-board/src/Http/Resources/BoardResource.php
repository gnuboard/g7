<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use App\Models\Permission;
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
            'blocked_keywords' => $this->formatBlockedKeywords($isFormRequest),

            // 파일 업로드 설정
            'use_file_upload' => $this->use_file_upload,
            'max_file_size' => $this->max_file_size,
            'max_file_count' => $this->max_file_count,
            'allowed_extensions' => $this->formatAllowedExtensions($isFormRequest),

            // 표시 설정
            'new_display_hours' => $this->new_display_hours ?? 24,

            // 게시판 관리 인원 (역할 기반)
            'board_managers' => $this->getBoardRoleUsers("sirsoft-board.{$this->slug}.manager"),
            'board_steps' => $this->getBoardRoleUsers("sirsoft-board.{$this->slug}.step"),
            'board_manager_ids' => $this->getBoardRoleUserIds("sirsoft-board.{$this->slug}.manager"),
            'board_step_ids' => $this->getBoardRoleUserIds("sirsoft-board.{$this->slug}.step"),

            // 알림 설정
            'notify_author' => $this->notify_author,
            'notify_admin_on_post' => $this->notify_admin_on_post,
            'notify_author_channels' => $this->notify_author_channels ?? [],
            'notify_admin_on_post_channels' => $this->notify_admin_on_post_channels ?? [],

            // 타임스탬프
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

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
     * 차단 키워드를 포맷팅합니다.
     *
     * @param  bool  $isFormRequest  폼 요청 여부
     * @return string|array 포맷팅된 차단 키워드
     */
    private function formatBlockedKeywords(bool $isFormRequest): string|array
    {
        if ($isFormRequest && is_array($this->blocked_keywords)) {
            return implode(', ', $this->blocked_keywords);
        }

        return $this->blocked_keywords ?? [];
    }

    /**
     * 허용 확장자를 포맷팅합니다.
     *
     * @param  bool  $isFormRequest  폼 요청 여부
     * @return string|array 포맷팅된 허용 확장자
     */
    private function formatAllowedExtensions(bool $isFormRequest): string|array
    {
        if ($isFormRequest && is_array($this->allowed_extensions)) {
            return implode(', ', $this->allowed_extensions);
        }

        return $this->allowed_extensions ?? [];
    }

    /**
     * 게시판 역할에 할당된 사용자 목록을 반환합니다.
     *
     * @param  string  $roleIdentifier  역할 identifier
     * @return array 사용자 목록 [{id, name, email}, ...]
     */
    private function getBoardRoleUsers(string $roleIdentifier): array
    {
        $role = \App\Models\Role::where('identifier', $roleIdentifier)->first();

        if (! $role) {
            return [];
        }

        return $role->users->map(fn ($user) => [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
        ])->values()->toArray();
    }

    /**
     * 게시판 역할에 할당된 사용자 ID 목록을 반환합니다.
     *
     * @param  string  $roleIdentifier  역할 identifier
     * @return array 사용자 ID 배열
     */
    private function getBoardRoleUserIds(string $roleIdentifier): array
    {
        $role = \App\Models\Role::where('identifier', $roleIdentifier)->first();

        if (! $role) {
            return [];
        }

        return $role->users->pluck('uuid')->toArray();
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
        /** @var \App\Models\User|null $user */
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
            ];

        $result = [];

        foreach ($permissionMap as $canKey => $identifier) {
            $permission = Permission::where('identifier', $identifier)->first();

            if (! $permission) {
                continue;
            }

            $result[$canKey] = $user
                ? $user->hasPermission($permission->identifier, $permission->type)
                : $this->checkGuestPermission($permission);
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
            $guestRole = \App\Models\Role::where('identifier', 'guest')->first();
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
