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
     * 리소스를 배열로 변환합니다. (상세 페이지용)
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $slug = $this->getSlug($request);

        return [
            ...$this->getCommonFields($request, $slug),

            // 상세 전용: 필터링된 제목/내용 (삭제글·비밀글 권한 체크)
            'title' => $this->getFilteredTitle($slug),
            'content' => $this->getFilteredContent($request, $slug),

            // 상세 전용: 작성자 UUID
            'user_id' => $this->user?->uuid,

            // 상세 전용: 트리거 타입
            'trigger_type' => $this->trigger_type,

            // 상세 전용: 타임스탬프
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),
            'deleted_at' => $this->deleted_at ? $this->formatDateTimeStringForUser($this->deleted_at) : null,

            // 상세 전용: IP 주소 (admin.manage 권한 보유자만)
            'ip_address' => $this->getIpAddressForResponse($slug),

            // 상세 전용: 관계 데이터 (조건부 로딩)
            'board' => $this->relationLoaded('board') ? $this->getBoardInfo() : null,
            'navigation' => $this->navigation ?? null,
            'parent' => $this->relationLoaded('parent') ? $this->getParentInfo() : null,
            'comments' => $this->relationLoaded('comments') ? CommentResource::collection($this->comments) : null,
            'attachments' => $this->getAttachmentsForResponse($request, $slug),
            'replies' => $this->relationLoaded('replies') ? static::collection($this->replies) : null,

            // 상세 전용: 신고 여부 (로그인 사용자 + board 관계 로드 시에만)
            'is_already_reported' => $this->getIsAlreadyReported($request),

            // 권한 정보 (is_owner + abilities) — 상세 페이지에서만 포함
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 목록용 간략 정보를 배열로 변환합니다.
     *
     * 목록에서는 권한 정보(resourceMeta)를 포함하지 않습니다.
     * 게시판 권한은 PostCollection의 컬렉션 레벨 abilities로 제공됩니다.
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
            ...$this->getCommonFields($request, $slug),

            // 목록 전용: 게시판 슬러그
            'slug' => $this->board_slug ?? null,

            // 목록 전용: 필터링 없는 제목 (목록은 권한 체크 불필요)
            'title' => $this->title,

            // 목록 전용: 타임스탬프
            'deleted_at' => $this->deleted_at ? $this->formatDateTimeStringForUser($this->deleted_at) : null,

            // 목록 전용: 본문 요약 (DB SUBSTRING 우선, 없으면 content에서 추출)
            'content_preview' => $this->getContentPreviewForList(150),
        ];
    }

    /**
     * 폼(글쓰기/글수정)용 경량 배열을 반환합니다.
     *
     * toArray()와 달리 권한 체크(resolveAbilities, IP, 신고 여부)를 생략하여
     * 불필요한 DB 쿼리를 방지합니다. 폼에서 필요한 필드만 포함합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toFormArray(?Request $request = null): array
    {
        $request = $request ?? request();
        $slug = $this->getSlug($request);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'content_mode' => $this->content_mode ?? 'text',
            'category' => $this->category,
            'is_notice' => (bool) $this->is_notice,
            'is_secret' => (bool) $this->is_secret,
            'parent_id' => $this->parent_id,

            // 작성자 정보
            'author' => $this->getAuthorInfo(includeIsGuest: true),

            // 타임스탬프
            'created_at'           => $this->formatCreatedAt($this->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),

            // 첨부파일 (권한 체크 없이 직접 반환 — 폼 진입 시 이미 본인/관리자 검증 완료)
            'attachments' => $this->relationLoaded('attachments')
                ? AttachmentResource::collection($this->attachments)
                : null,

            // 원글 정보 (답변글 수정 시)
            'parent' => $this->relationLoaded('parent') ? $this->getParentInfoForForm() : null,
        ];
    }

    // =========================================================================
    // 공통 필드 추출
    // =========================================================================

    /**
     * toArray()와 toListArray()에서 공유하는 공통 필드를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return array<string, mixed> 공통 필드 데이터
     */
    private function getCommonFields(Request $request, ?string $slug): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,

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

            // 썸네일 이미지 (첫 번째 이미지 첨부파일)
            'thumbnail' => $this->getThumbnailUrlFromRelations(),

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

            // 소유권 정보
            // is_author: 회원 글인 경우만 본인 여부 체크 (비회원 글은 세션 검증 불가하므로 항상 false)
            'is_author' => $this->user_id !== null && $request->user()?->id === $this->user_id,
            'is_guest_post' => $this->user_id === null,
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 데이터 추출
    // =========================================================================

    /**
     * 게시판 슬러그를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 게시판 슬러그
     */
    private function getSlug(Request $request): ?string
    {
        // route slug 우선 → board relation이 미로딩 시 lazy loading 방지
        return $request->route('slug') ?? ($this->relationLoaded('board') ? $this->board?->slug : null);
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
     * 썸네일 URL을 관계 로딩 상태에 따라 반환합니다.
     *
     * thumbnailAttachment(경량 hasOne) 우선, attachments(전체) fallback.
     * 둘 다 미로딩 시 null 반환 (lazy loading 방지).
     *
     * @return string|null 썸네일 URL
     */
    private function getThumbnailUrlFromRelations(): ?string
    {
        // 목록용 경량 관계 우선 — slug를 직접 전달하여 Board::find() N+1 방지
        if ($this->relationLoaded('thumbnailAttachment') && $this->thumbnailAttachment) {
            $attachment = $this->thumbnailAttachment;
            $slug = request()->route('slug') ?? ($this->relationLoaded('board') ? $this->board?->slug : null);

            if ($slug && $attachment->hash) {
                return '/api/modules/sirsoft-board/boards/' . $slug . '/attachment/' . $attachment->hash . '/preview';
            }

            return null;
        }

        // 상세 페이지 등에서 attachments가 로딩된 경우 fallback
        if ($this->relationLoaded('attachments')) {
            return $this->getThumbnailUrl();
        }

        return null;
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
     * 폼용 원글 정보를 반환합니다.
     *
     * getParentInfo()와 달리 toFormArray()를 사용하여
     * 불필요한 권한 체크(resolveAbilities 등)를 생략합니다.
     *
     * @return array|null 원글 정보 또는 null
     */
    private function getParentInfoForForm(): ?array
    {
        if (! $this->parent) {
            return null;
        }

        $parentResource = new static($this->parent);

        return $parentResource->toFormArray();
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
     * 목록용 본문 요약을 반환합니다.
     *
     * DB SUBSTRING으로 가져온 content_preview_raw가 있으면 우선 사용하고,
     * 없으면 content에서 추출합니다. (상세 페이지 등 content가 로딩된 경우)
     *
     * @param  int  $length  최대 길이
     * @return string 본문 요약 텍스트
     */
    private function getContentPreviewForList(int $length = 150): string
    {
        // DB SUBSTRING으로 가져온 raw가 있으면 우선 사용
        $raw = $this->content_preview_raw ?? null;
        if ($raw !== null) {
            $mode = $this->content_mode ?? 'text';
            $plain = $mode === 'html'
                ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($raw))))
                : trim(preg_replace('/\s+/', ' ', $raw));

            $preview = mb_substr($plain, 0, $length);

            return $preview . (mb_strlen($plain) > $length ? '...' : '');
        }

        // fallback: content 전체가 로딩된 경우 (상세 페이지 등)
        return $this->getContentPreview($length);
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
    // 상세 페이지 전용: 조건부 필드 메서드
    // =========================================================================

    /**
     * IP 주소를 권한에 따라 반환합니다. (admin.manage 권한 보유자만)
     *
     * @param  string|null  $slug  게시판 슬러그
     * @return string|null IP 주소
     */
    private function getIpAddressForResponse(?string $slug): ?string
    {
        if (! $slug || ! $this->checkBoardPermission($slug, 'admin.manage')) {
            return null;
        }

        return $this->ip_address;
    }

    /**
     * 첨부파일을 권한에 따라 반환합니다. (비밀글 열람 권한 체크)
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return mixed 첨부파일 리소스 컬렉션 또는 null
     */
    private function getAttachmentsForResponse(Request $request, ?string $slug): mixed
    {
        if (! $this->relationLoaded('attachments')) {
            return null;
        }

        if ($this->is_secret && ! $this->canViewSecretContent($request, $slug)) {
            return [];
        }

        return AttachmentResource::collection($this->attachments);
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
     * 참고: 목록에서는 이 메서드가 호출되지 않습니다.
     * 목록의 권한은 PostCollection::getBoardPermissions()에서 컬렉션 레벨로 제공됩니다.
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
     * 열람 가능 조건 (우선순위 순):
     * 1. 작성자 본인 (회원 게시글)
     * 2. 비밀번호 검증 완료 (회원/비회원 게시글, password_verified 플래그)
     * 3. 게시판별 비밀글 읽기 권한 (posts.read-secret)
     * 4. 게시판 관리자 권한 (Admin: admin.manage / User: manager)
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그 (getSlug 중복 호출 방지)
     * @return bool 열람 가능 여부
     */
    private function canViewSecretContent(Request $request, ?string $slug = null): bool
    {
        // 1. 작성자 본인 (회원 게시글)
        $user = Auth::user();
        if ($user && $this->user_id && $this->user_id === $user->id) {
            return true;
        }

        // 2. 비밀번호 검증 완료
        if ($this->password_verified === true) {
            return true;
        }

        // 3-4. 게시판별 권한 체크
        $slug = $slug ?? $this->getSlug($request);
        if (! $slug) {
            return false;
        }

        if ($this->isAdminRequest($request)) {
            return $this->checkBoardPermission($slug, 'admin.posts.read-secret')
                || $this->checkBoardPermission($slug, 'admin.manage');
        }

        return $this->checkBoardPermission($slug, 'posts.read-secret', PermissionType::User)
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
    }

    // =========================================================================
    // 콘텐츠 필터링 메서드 (상세 페이지 전용)
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
        if ($this->is_secret && ! $this->canViewSecretContent($request, $slug)) {
            return null;
        }

        return $this->content;
    }
}
