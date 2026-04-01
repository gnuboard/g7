<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'content' => $this->getFilteredContent($slug),

            // 작성자 정보
            'author' => $this->getAuthorInfo(),

            // 댓글 속성
            'is_secret' => $this->is_secret,
            'status' => $this->status?->value ?? 'published',
            'status_label' => $this->status?->label() ?? __('sirsoft-board::messages.post.status.published'),
            'depth' => $this->depth,
            'replies_count' => $this->replies_count ?? 0,

            // 타임스탬프
            'created_at'           => $this->formatCreatedAt($this->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),
            'deleted_at' => $this->deleted_at ? $this->formatDateTimeStringForUser($this->deleted_at) : null,

            // IP 주소 (admin.manage 권한 보유자만)
            'ip_address' => ($slug && $this->checkBoardPermission($slug, 'admin.manage'))
                ? $this->ip_address
                : null,

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
            $userStatus = \App\Enums\UserStatus::tryFrom($this->user->status);
            $isWithdrawn = $userStatus === \App\Enums\UserStatus::Withdrawn;

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
     * 비로그인 또는 post.board 관계 미로드 시 false 반환.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 이미 신고 여부
     */
    private function getIsAlreadyReported(Request $request): bool
    {
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
     * @param  string|null  $slug  게시판 슬러그
     * @return string|null 필터링된 댓글 내용
     */
    private function getFilteredContent(?string $slug): ?string
    {
        // 삭제된 댓글
        if ($this->deleted_at) {
            if (! $slug || ! $this->checkBoardPermission($slug, 'admin.manage')) {
                return __('sirsoft-board::messages.comment.deleted_comment_content');
            }
        }

        return $this->content;
    }

}
