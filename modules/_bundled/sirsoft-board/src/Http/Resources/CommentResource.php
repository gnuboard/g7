<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 댓글 API 리소스
 *
 * 댓글 정보를 API 응답 형식으로 변환합니다.
 */
class CommentResource extends BaseApiResource
{
    use ChecksBoardPermission;
    use FormatsBoardDate;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $slug = $this->post?->board?->slug ?? $request->route('slug');

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'parent_id' => $this->parent_id,
            'content' => $this->getFilteredContent($request, $slug),

            // 작성자 정보
            'author' => $this->getAuthorInfo(),

            // 댓글 속성
            'is_secret' => $this->is_secret,
            'status' => $this->status?->value ?? 'published',
            'status_label' => $this->status?->label() ?? __('sirsoft-board::messages.post.status.published'),
            'depth' => $this->depth,
            'replies_count' => $this->replies_count ?? 0,

            // 타임스탬프
            'created_at' => $this->formatCreatedAt($this->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),
            'deleted_at' => $this->deleted_at ? $this->formatDateTimeStringForUser($this->deleted_at) : null,

            // 게시글 삭제로 함께 숨겨진(cascade) 댓글 여부.
            // 프론트는 이 플래그로 cascade 댓글을 사용자 직접 삭제분과 구분해
            // 마스킹 없이 원문을 노출한다
            'is_cascade_deleted' => $this->deleted_at !== null
                && $this->trigger_type === TriggerType::Cascade,

            // IP 주소 (admin.manage 권한 보유자만)
            'ip_address' => ($slug && $this->checkBoardPermission($slug, 'admin.manage'))
                ? $this->ip_address
                : null,

            // 처리 이력(블라인드/복원 등) — admin.manage 권한 보유자만, 민감 필드 제외
            'action_logs' => $this->getActionLogsForResponse($slug),

            // 소유권 정보
            'is_author' => Auth::id() === $this->user_id,
            'is_guest_comment' => $this->user_id === null,

            // 신고 여부 (로그인 사용자 + post.board 관계 로드 시에만)
            'is_already_reported' => $this->getIsAlreadyReported($request),

            // 권한 정보 (is_owner + permissions)
            ...$this->resourceMeta($request),
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 공통 데이터 추출
    // =========================================================================

    /**
     * 처리 이력(action_logs)을 권한에 따라 반환합니다. (admin.manage 권한 보유자만)
     *
     * 블라인드/복원/삭제 사유·처리자명·처리일을 노출하되, 민감 필드(admin_id, ip_address)는
     * 제외합니다. 비권한자에게는 null 을 반환하여 사유가 누출되지 않도록 합니다.
     *
     * @param  string|null  $slug  게시판 슬러그
     * @return array<int, array<string, mixed>>|null 표시용 처리 이력 목록
     */
    private function getActionLogsForResponse(?string $slug): ?array
    {
        if (! $slug || ! $this->checkBoardPermission($slug, 'admin.manage')) {
            return null;
        }

        $logs = $this->action_logs ?? [];

        return array_map(static fn (array $log): array => [
            'action' => $log['action'] ?? null,
            'reason' => $log['reason'] ?? null,
            'admin_name' => $log['admin_name'] ?? null,
            'created_at' => $log['created_at'] ?? null,
        ], $logs);
    }

    /**
     * 작성자 정보 배열을 반환합니다.
     *
     * 회원 상태별 정보:
     * - active: 전체 정보 (이름, 이메일, 아바타, 상태)
     * - inactive: 기본 정보 + "휴면" 상태
     * - blocked: 기본 정보 + "차단" 상태
     * - withdrawn: 익명화 ("탈퇴한 사용자")
     *
     * @return array<string, mixed> 작성자 정보
     */
    private function getAuthorInfo(): array
    {
        if ($this->user_id && $this->user) {
            $userStatus = UserStatus::tryFrom($this->user->status);
            $isWithdrawn = $userStatus === UserStatus::Withdrawn;

            return [
                'uuid' => $this->user?->uuid,
                'name' => $isWithdrawn ? __('user.withdrawn_user') : $this->user->name,
                'email' => $isWithdrawn ? null : $this->user->email,
                'avatar' => $isWithdrawn ? null : $this->user->getAvatarUrl(),
                'status' => $this->user->status,
                'status_label' => $userStatus?->label() ?? $this->user->status,
                'is_guest' => false,
            ];
        }

        return [
            'uuid' => null,
            'name' => $this->author_name,
            'email' => null,
            'avatar' => null,
            'status' => null,
            'status_label' => null,
            'is_guest' => true,
        ];
    }

    /**
     * 현재 로그인 사용자가 이 댓글을 이미 신고했는지 반환합니다.
     *
     * Controller에서 사전 로드(is_already_reported_preloaded)된 값이 있으면
     * DB 쿼리 없이 반환합니다. (N+1 방지)
     * 사전 로드가 없는 경우(목록 등) fallback으로 개별 쿼리를 실행합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 이미 신고 여부
     */
    private function getIsAlreadyReported(Request $request): bool
    {
        // 사전 로드된 값이 있으면 DB 쿼리 없이 반환 (N+1 방지)
        if (isset($this->resource->is_already_reported_preloaded)) {
            return $this->resource->is_already_reported_preloaded;
        }

        // fallback: 개별 쿼리 (목록 등 사전 로드 미적용 경로)
        $user = $request->user();
        $boardId = $this->post?->board?->id ?? null;

        if (! $user || ! $boardId) {
            return false;
        }

        return app(ReportRepositoryInterface::class)
            ->hasUserReported($user->id, $boardId, 'comment', $this->id);
    }

    // =========================================================================
    // 권한 관련 메서드
    // =========================================================================

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'user_id';
    }

    /**
     * 댓글 권한을 통합 can_* 키로 반환합니다.
     *
     * Admin/User 페이지별로 동일한 키를 사용하되,
     * 실제 체크하는 permission identifier는 컨텍스트에 따라 다릅니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, bool> 통합 권한 정보
     */
    protected function resolveAbilities(Request $request): array
    {
        $slug = $this->post?->board?->slug ?? $request->route('slug');
        if (! $slug) {
            return [];
        }

        $permissionMap = $this->isAdminRequest($request)
            ? [
                'can_read' => "sirsoft-board.{$slug}.admin.comments.read",
                'can_write' => "sirsoft-board.{$slug}.admin.comments.write",
                'can_manage' => "sirsoft-board.{$slug}.admin.manage",
            ]
            : [
                'can_write' => "sirsoft-board.{$slug}.comments.write",
            ];

        return collect($permissionMap)
            ->mapWithKeys(fn (string $identifier, string $key) => [
                $key => $this->checkPermissionByIdentifier($identifier),
            ])
            ->toArray();
    }

    /**
     * Admin 요청 여부를 확인합니다.
     *
     * Controller 네임스페이스로 판단합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool Admin 요청 여부
     */
    private function isAdminRequest(Request $request): bool
    {
        $controller = $request->route()?->getController();

        if (! $controller) {
            return false;
        }

        return str_contains(get_class($controller), '\\Admin\\');
    }

    // =========================================================================
    // 콘텐츠 필터링 메서드
    // =========================================================================

    /**
     * 권한에 따라 필터링된 댓글 내용을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return string|null 필터링된 댓글 내용
     */
    private function getFilteredContent(Request $request, ?string $slug): ?string
    {
        // 게시글 삭제로 함께 숨겨진(cascade) 댓글은 사용자가 직접 지운 것이 아니므로
        // 마스킹하지 않고 원문을 그대로 노출한다 (글을 볼 수 있는 사람이면 누구나).
        $isCascadeDeleted = $this->deleted_at !== null
            && $this->trigger_type === TriggerType::Cascade;

        // 삭제된 댓글(사용자 직접 삭제 등): 관리 권한(manager/admin.manage)이 없으면 내용 숨김
        if ($this->deleted_at && ! $isCascadeDeleted && ! $this->canViewDeletedContent($request, $slug)) {
            return __('sirsoft-board::messages.comment.deleted_comment_content');
        }

        // 블라인드 댓글: 원문 열람 권한(관리자 또는 작성자 본인)이 없으면 content를 null로 반환
        // - 블라인드 안내 문구는 그대로 노출하되, 원문만 권한자 한정
        // - null 반환 시 프론트의 '원문 보기' 버튼이 자동으로 미노출됨
        if ($this->status === PostStatus::Blinded && ! $this->canViewBlindedContent($request, $slug)) {
            return null;
        }

        return $this->content;
    }

    /**
     * 블라인드 댓글 원문 열람 가능 여부를 확인합니다.
     *
     * 열람 가능 조건 (OR):
     * 1. 작성자 본인 (회원 댓글 — 비회원 댓글은 본인 판정 불가)
     * 2. 게시판 관리자 (Admin: admin.manage / User: manager)
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return bool 원문 열람 가능 여부
     */
    private function canViewBlindedContent(Request $request, ?string $slug): bool
    {
        // 1. 작성자 본인 (회원 댓글)
        $user = Auth::user();
        if ($user && $this->user_id && $this->user_id === $user->id) {
            return true;
        }

        // 2. 게시판 관리자 권한
        if (! $slug) {
            return false;
        }

        if ($this->isAdminRequest($request)) {
            return $this->checkBoardPermission($slug, 'admin.manage');
        }

        return $this->checkBoardPermission($slug, 'manager', PermissionType::User);
    }

    /**
     * 삭제된 댓글 원문 열람 가능 여부를 확인합니다.
     *
     * 열람 가능 조건: 게시판 관리자 (Admin: admin.manage / User: manager).
     * 블라인드(canViewBlindedContent)와 달리 작성자 본인은 인정하지 않습니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return bool 원문 열람 가능 여부
     */
    private function canViewDeletedContent(Request $request, ?string $slug): bool
    {
        if (! $slug) {
            return false;
        }

        if ($this->isAdminRequest($request)) {
            return $this->checkBoardPermission($slug, 'admin.manage');
        }

        return $this->checkBoardPermission($slug, 'manager', PermissionType::User)
            || $this->checkBoardPermission($slug, 'admin.manage');
    }
}
