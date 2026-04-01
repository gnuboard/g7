<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Enums\PermissionType;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 게시글 API 리소스
 *
 * 게시글 정보를 API 응답 형식으로 변환합니다.
 */
class PostResource extends BaseApiResource
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
        $slug = $this->getSlug($request);

        return [
            'id' => $this->id,
            'category' => $this->category,
            'title' => $this->getFilteredTitle($slug),
            'content' => $this->getFilteredContent($request, $slug),

            // 작성자 정보
            'user_id' => $this->user?->uuid,
            'author' => $this->getAuthorInfo(includeIsGuest: true),

            // 게시글 속성
            'is_notice' => (bool) $this->is_notice,
            'is_secret' => (bool) $this->is_secret,
            'content_mode' => $this->content_mode ?? 'text',
            'is_new' => $this->isNew(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'trigger_type' => $this->trigger_type,

            // 통계
            'view_count' => $this->view_count,
            'comment_count' => (int) ($this->comments_count ?? 0),
            'reply_count' => (int) ($this->replies_count ?? 0),
            'has_attachment' => ((int) ($this->attachments_count ?? 0)) > 0,

            // 썸네일 이미지 (첫 번째 이미지 첨부파일)
            'thumbnail' => $this->relationLoaded('attachments') ? $this->getThumbnailUrl() : null,

            // 계층 구조
            'parent_id' => $this->parent_id,
            'depth' => $this->depth,
            'is_reply' => $this->parent_id !== null,

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

            // 상세 정보 (조건부 로딩)
            'board' => $this->relationLoaded('board') ? $this->getBoardInfo() : null,
            'navigation' => isset($this->navigation) ? $this->navigation : null,
            'parent' => $this->relationLoaded('parent') ? $this->getParentInfo() : null,
            'comments' => $this->relationLoaded('comments') ? CommentResource::collection($this->comments) : null,
            'attachments' => $this->relationLoaded('attachments')
                ? (($this->is_secret && ! $this->canViewSecretContent($request)) ? [] : AttachmentResource::collection($this->attachments))
                : null,
            'replies' => $this->relationLoaded('replies') ? static::collection($this->replies) : null,

            // 소유권 정보
            // is_author: 회원 글인 경우만 본인 여부 체크 (비회원 글은 세션 검증 불가하므로 항상 false)
            'is_author' => $this->user_id !== null && $request->user()?->id === $this->user_id,
            'is_guest_post' => $this->user_id === null,

            // 신고 여부 (로그인 사용자 + board 관계 로드 시에만)
            'is_already_reported' => $this->getIsAlreadyReported($request),

            // 권한 정보 (is_owner + permissions)
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 목록용 간략 정보를 배열로 변환합니다.
     *
     * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
     * $this->when() 대신 삼항 연산자를 사용해야 합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toListArray(?Request $request = null): array
    {
        $request = $request ?? request();
        $slug = $this->getSlug($request);

        return [
            'id' => $this->id,
            'slug' => $this->board_slug ?? null,
            'category' => $this->category,
            'title' => $this->title,

            // 작성자 정보
            'author' => $this->getAuthorInfo(includeIsGuest: true),

            // 게시글 속성
            'is_notice' => (bool) $this->is_notice,
            'is_secret' => (bool) $this->is_secret,
            'content_mode' => $this->content_mode ?? 'text',
            'is_new' => $this->isNew(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            // 통계
            'view_count' => $this->view_count,
            'comment_count' => (int) ($this->comments_count ?? 0),
            'reply_count' => (int) ($this->replies_count ?? 0),
            'has_attachment' => ((int) ($this->attachments_count ?? 0)) > 0,

            // 썸네일 이미지
            'thumbnail' => $this->relationLoaded('attachments') ? $this->getThumbnailUrl() : null,

            // 계층 구조
            'parent_id' => $this->parent_id,
            'depth' => $this->depth,
            'is_reply' => $this->parent_id !== null,

            // 타임스탬프
            'created_at'           => $this->formatCreatedAt($this->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),
            'deleted_at' => $this->deleted_at ? $this->formatDateTimeStringForUser($this->deleted_at) : null,

            // 본문 요약 (html 모드: strip_tags → 평문 앞 150자, text 모드: 그대로 앞 150자)
            'content_preview' => $this->getContentPreview(150),

            // 소유권 정보
            // is_author: 회원 글인 경우만 본인 여부 체크 (비회원 글은 세션 검증 불가하므로 항상 false)
            'is_author' => $this->user_id !== null && $request->user()?->id === $this->user_id,
            'is_guest_post' => $this->user_id === null,

            // 권한 정보 (is_owner + permissions)
            ...$this->resourceMeta($request),
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 공통 데이터 추출
    // =========================================================================

    /**
     * 게시판 슬러그를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 게시판 슬러그
     */
    private function getSlug(Request $request): ?string
    {
        return $this->board?->slug ?? $request->route('slug');
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
     * @param  bool  $includeIsGuest  is_guest 필드 포함 여부
     * @return array<string, mixed> 작성자 정보
     */
    private function getAuthorInfo(bool $includeIsGuest = false): array
    {
        $isGuest = $this->user_id === null;

        if ($this->user_id && $this->user) {
            $userStatus = \App\Enums\UserStatus::tryFrom($this->user->status);
            $isWithdrawn = $userStatus === \App\Enums\UserStatus::Withdrawn;

            $author = [
                'uuid' => $this->user->uuid,
                'name' => $isWithdrawn ? __('user.withdrawn_user') : $this->user->name,
                'email' => $isWithdrawn ? null : $this->user->email,
                'avatar' => $isWithdrawn ? null : $this->user->getAvatarUrl(),
                'status' => $this->user->status,
                'status_label' => $userStatus?->label() ?? $this->user->status,
            ];
        } else {
            $author = [
                'uuid' => null,
                'name' => $this->author_name,
                'email' => null,
                'avatar' => null,
                'status' => null,
                'status_label' => null,
            ];
        }

        if ($includeIsGuest) {
            $author['is_guest'] = $isGuest;
        }

        return $author;
    }

    /**
     * 썸네일 이미지 URL을 반환합니다.
     *
     * @return string|null 썸네일 URL
     */
    private function getThumbnailUrl(): ?string
    {
        $attachments = is_array($this->attachments) ? collect($this->attachments) : $this->attachments;
        $firstImage = $attachments
            ?->filter(fn ($attachment) => str_starts_with($attachment?->mime_type ?? '', 'image/'))
            ?->first();

        return $firstImage?->preview_url ?? null;
    }

    /**
     * 게시판 정보 배열을 반환합니다.
     *
     * @return array<string, mixed> 게시판 정보
     */
    private function getBoardInfo(): array
    {
        return [
            'slug' => $this->board->slug,
            'name' => $this->board->getLocalizedName(),
            'type' => $this->board->type,
            'use_comment' => $this->board->use_comment,
            'use_reply' => $this->board->use_reply,
            'use_report' => $this->board->use_report,
            'show_view_count' => $this->board->show_view_count,
            'max_reply_depth' => $this->board->max_reply_depth ?? g7_module_settings('sirsoft-board', 'basic_defaults.max_reply_depth', 5),
            'max_comment_depth' => $this->board->max_comment_depth ?? g7_module_settings('sirsoft-board', 'basic_defaults.max_comment_depth', 10),
            'report_types' => ReportReasonType::toArray(),
        ];
    }

    /**
     * 부모 게시글 정보를 반환합니다.
     *
     * @return array<string, mixed>|null 부모 게시글 정보
     */
    private function getParentInfo(): ?array
    {
        if (! $this->parent) {
            return null;
        }

        $parentResource = new static($this->parent);
        $parentData = $parentResource->toArray(request());

        // parent에 slug 정보 추가 (board 정보가 없을 수 있으므로 현재 게시판의 slug 사용)
        if (! isset($parentData['board'])) {
            $parentData['slug'] = $this->board?->slug ?? null;
        }

        return $parentData;
    }

    /**
     * 본문 요약 텍스트를 반환합니다.
     *
     * HTML 모드: strip_tags로 태그 제거 후 평문 앞 N자 추출
     * 텍스트 모드: 그대로 앞 N자 추출
     *
     * @param  int  $length  최대 길이
     * @return string 본문 요약 텍스트
     */
    private function getContentPreview(int $length = 150): string
    {
        if (empty($this->content)) {
            return '';
        }

        $mode = $this->content_mode ?? 'text';
        $plain = $mode === 'html'
            ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->content))))
            : trim(preg_replace('/\s+/', ' ', $this->content));

        $preview = mb_substr($plain, 0, $length);

        return $preview . (mb_strlen($plain) > $length ? '...' : '');
    }

    /**
     * 현재 로그인 사용자가 이 게시글을 이미 신고했는지 반환합니다.
     *
     * 비로그인 또는 board 관계 미로드 시 false 반환.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 이미 신고 여부
     */
    private function getIsAlreadyReported(Request $request): bool
    {
        $user = $request->user();
        if (! $user || ! $this->relationLoaded('board') || ! $this->board) {
            return false;
        }

        return app(ReportRepositoryInterface::class)
            ->hasUserReported($user->id, $this->board->id, 'post', $this->id);
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
     * 게시글 권한을 통합 can_* 키로 반환합니다.
     *
     * Admin/User 페이지별로 동일한 키를 사용하되,
     * 실제 체크하는 permission identifier는 컨텍스트에 따라 다릅니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, bool> 통합 권한 정보
     */
    protected function resolveAbilities(Request $request): array
    {
        $slug = $this->getSlug($request);
        if (! $slug) {
            return [];
        }

        $permissionMap = $this->isAdminRequest($request)
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

        return collect($permissionMap)
            ->mapWithKeys(fn (string $identifier, string $key) => [
                $key => $this->checkPermissionByIdentifier($identifier),
            ])
            ->toArray();
    }

    /**
     * Admin 요청 여부를 확인합니다.
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

    /**
     * 비밀글 내용 열람 가능 여부를 확인합니다.
     *
     * 열람 가능 조건:
     * 1. 작성자 본인 (회원 게시글)
     * 2. 비밀번호 검증 완료 (회원/비회원 게시글, password_verified 플래그)
     * 3. 게시판별 비밀글 읽기 권한 (posts.read-secret)
     * 4. 게시판 관리자 권한 (Admin: admin.manage / User: manager)
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 열람 가능 여부
     */
    private function canViewSecretContent(Request $request): bool
    {
        $slug = $this->getSlug($request);

        // 1. 작성자 본인 (회원 게시글)
        $user = Auth::user();
        if ($user && $this->user_id && $this->user_id === $user->id) {
            return true;
        }

        // 2. 비밀번호 검증 완료 (회원/비회원 게시글 모두 지원)
        if ($this->password_verified === true) {
            return true;
        }

        // 3. 게시판별 비밀글 읽기 권한 + 4. 게시판 관리자 권한
        if ($slug) {
            if ($this->isAdminRequest($request)) {
                // 3. 비밀글 읽기 권한 (Admin: admin.posts.read-secret)
                if ($this->checkBoardPermission($slug, 'admin.posts.read-secret')) {
                    return true;
                }
                // 4. 게시판 관리자 권한 (Admin: admin.manage)
                if ($this->checkBoardPermission($slug, 'admin.manage')) {
                    return true;
                }
            } else {
                // 3. 비밀글 읽기 권한 (User: posts.read-secret)
                if ($this->checkBoardPermission($slug, 'posts.read-secret', PermissionType::User)) {
                    return true;
                }
                // 4. 게시판 관리자 권한 (User: manager)
                if ($this->checkBoardPermission($slug, 'manager', PermissionType::User)) {
                    return true;
                }
            }
        }

        return false;
    }

    // =========================================================================
    // 콘텐츠 필터링 메서드
    // =========================================================================

    /**
     * 권한에 따라 필터링된 제목을 반환합니다.
     *
     * @param  string|null  $slug  게시판 슬러그
     * @return string 필터링된 제목
     */
    private function getFilteredTitle(?string $slug): string
    {
        // 삭제된 게시글: admin.manage 권한이 없으면 제목 숨김
        if ($this->deleted_at) {
            if (! $slug || ! $this->checkBoardPermission($slug, 'admin.manage')) {
                return __('sirsoft-board::messages.post.deleted_post_title');
            }
        }

        return $this->title;
    }

    /**
     * 권한에 따라 필터링된 내용을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return string|null 필터링된 내용
     */
    private function getFilteredContent(Request $request, ?string $slug): ?string
    {
        // 삭제된 게시글: admin.manage 권한이 없으면 내용 숨김
        if ($this->deleted_at) {
            if (! $slug || ! $this->checkBoardPermission($slug, 'admin.manage')) {
                return __('sirsoft-board::messages.post.deleted_post_content');
            }
        }

        // 비밀글: 권한 없으면 content를 null로 반환
        // - is_secret = true인 경우에만 검증 필요
        // - 일반글(is_secret = false)은 비밀번호 유무와 관계없이 공개
        if ($this->is_secret && ! $this->canViewSecretContent($request)) {
            return null;
        }

        return $this->content;
    }

}
