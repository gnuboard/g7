<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Support\SecretContentGate;
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
        // 컨트롤러가 넘긴 부모 post(요청 속성)를 재사용해 slug 를 도출한다 — 목록에서 댓글당
        // `$this->post` lazy-load(N+1)를 피한다(KVE-2026-1914 A-4b). 미주입 경로(상세/생성/
        // admin)는 종전대로 이미 로드된 관계 또는 라우트 slug 로 폴백한다.
        $parentPost = $this->resolveParentPost($request);
        $slug = $parentPost?->board?->slug ?? $request->route('slug');

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'parent_id' => $this->parent_id,
            'content' => $this->getFilteredContent($request, $slug),

            // 작성자 정보
            'author' => $this->getAuthorInfo($request),

            // 댓글 속성
            'is_secret' => $this->is_secret,
            'status' => $this->status?->value ?? 'published',
            'status_label' => $this->status?->label() ?? __('sirsoft-board::enums.post_status.published'),
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
            // 회원 댓글만 본인 판정한다 — 비로그인(null)과 비회원 댓글(user_id null)을 같은
            // 값으로 비교하면 방문자가 비회원 댓글의 작성자로 판정된다(PostResource 와 같은 기준).
            'is_author' => $this->user_id !== null && Auth::id() === $this->user_id,
            'is_guest_comment' => $this->user_id === null,

            // 신고 여부 (로그인 사용자 + post.board 관계 로드 시에만)
            'is_already_reported' => $this->getIsAlreadyReported($request),

            // 댓글 반응 (게시판이 use_reaction + use_comment_reaction 을 모두 켰을 때만 의미 있음)
            'reaction_counts' => $this->getReactionCountsForResponse($request),
            'my_reaction_type_id' => $this->getMyReactionTypeId($request),

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
     * 이메일은 관리자 화면 응답과 본인 열람에만 싣는다 — 댓글 목록은 인증 없이 열리므로,
     * 이메일을 그대로 실으면 회원 주소록이 응답 본문으로 수집된다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 작성자 정보
     */
    private function getAuthorInfo(Request $request): array
    {
        if ($this->user_id && $this->user) {
            $userStatus = UserStatus::tryFrom($this->user->status);
            $isWithdrawn = $userStatus === UserStatus::Withdrawn;
            $canExposeEmail = $this->canExposeMemberEmail($request, $this->user_id);

            return [
                'uuid' => $this->user?->uuid,
                'name' => $isWithdrawn ? __('user.withdrawn_user') : $this->user->name,
                'email' => ($isWithdrawn || ! $canExposeEmail) ? null : $this->user->email,
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
        // 부모 post 는 컨트롤러가 넘긴 인스턴스를 재사용해 댓글당 lazy-load 를 피한다.
        $user = $request->user();
        $boardId = $this->resolveParentPost($request)?->board?->id ?? null;

        if (! $user || ! $boardId) {
            return false;
        }

        return app(ReportRepositoryInterface::class)
            ->hasUserReported($user->id, $boardId, 'comment', $this->id);
    }

    /**
     * 댓글의 반응 카운트를 응답용으로 반환합니다.
     *
     * 게시판이 반응·댓글반응을 모두 켰을 때만 값을 채우고, 활성 유형은 0 으로 채워
     * "유형은 있으나 아직 아무도 누르지 않은" 상태도 화면이 그릴 수 있게 한다.
     *
     * 반환은 반드시 객체(stdClass)다 — 정수 키 배열은 JSON 직렬화에서 리스트로 바뀌어
     * 프론트의 `counts[typeId]` 접근이 깨진다.
     *
     * @param  Request  $request  HTTP 요청
     * @return object 유형 ID => 개수
     */
    private function getReactionCountsForResponse(Request $request): object
    {
        $board = $this->resolveParentPost($request)?->board;

        if (! BoardResource::reactionsEnabled($board) || ! $board->use_comment_reaction) {
            return new \stdClass;
        }

        $counts = $this->reaction_counts ?? [];

        foreach ($this->resolveActiveReactionTypeIds($request, $board) as $typeId) {
            $key = (string) $typeId;
            $counts[$key] = (int) ($counts[$key] ?? 0);
        }

        return (object) $counts;
    }

    /**
     * 게시판이 켠 반응 유형 ID 목록을 요청 단위로 한 번만 해석합니다.
     *
     * 댓글마다 유형을 조회하면 댓글 수에 비례해 쿼리가 늘어난다(목록 응답의 N+1).
     * 같은 요청 안에서는 게시판당 결과가 불변이므로 요청 속성에 담아 재사용한다.
     * 함수 정적 변수에 담으면 프로세스가 끝날 때까지 남아, 장기 실행 프로세스가 관리자가 끄거나
     * 다시 만든 유형의 옛 ID 를 계속 쓴다 — 게시글 리소스(`PostResource::activeReactionTypeIds`)와
     * 같은 요청 속성 캐시를 쓴다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  mixed  $board  게시판 모델
     * @return array<int, int> 활성 반응 유형 ID 목록
     */
    private function resolveActiveReactionTypeIds(Request $request, $board): array
    {
        $codes = $board->active_reaction_types ?? [];
        $key = 'sirsoft-board.comment_active_reaction_type_ids.'.$board->id.':'.implode(',', $codes);

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(ReactionTypeRepositoryInterface::class)
                ->findByCodes($codes)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return $request->attributes->get($key);
    }

    /**
     * 로그인 사용자가 이 댓글에 남긴 반응 유형 ID 를 반환합니다.
     *
     * 사전 로드 판정에 isset() 을 쓰면 안 된다 — "반응을 남기지 않음"이 정당한 null 이라
     * isset() 은 프리로드가 있어도 false 가 되어 댓글마다 개별 쿼리로 조용히 퇴화한다.
     * array_key_exists 로 "키가 주입됐는지"를 본다.
     *
     * @param  Request  $request  HTTP 요청
     * @return int|null 반응 유형 ID (없으면 null)
     */
    private function getMyReactionTypeId(Request $request): ?int
    {
        if (array_key_exists('my_reaction_type_id_preloaded', $this->resource->getAttributes())) {
            $value = $this->resource->getAttributes()['my_reaction_type_id_preloaded'];

            return $value === null ? null : (int) $value;
        }

        $user = $request->user();

        if (! $user) {
            return null;
        }

        $reaction = app(ReactionRepositoryInterface::class)
            ->findByUserAndTarget($user->id, ReactionService::TARGET_COMMENT, (int) $this->id);

        return $reaction === null ? null : (int) $reaction->reaction_type_id;
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
        $slug = $this->resolveParentPost($request)?->board?->slug ?? $request->route('slug');
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

    // =========================================================================
    // 콘텐츠 필터링 메서드
    // =========================================================================

    /**
     * 부모 게시글(Post)을 조회 없이 해석합니다.
     *
     * 우선순위:
     *  1. 컨트롤러가 요청 속성으로 넘긴 인스턴스(`sirsoft_board_parent_post`) — 목록 경로에서
     *     댓글당 lazy-load(N+1)를 피하는 SSoT. 모든 댓글이 같은 인스턴스를 공유한다.
     *  2. 이미 로드된 `post` 관계(상세/생성/admin 등 미주입 경로 하위호환).
     *
     * 둘 다 없으면 null — 추가 쿼리를 유발하지 않는다(2차 게이트는 1차 방어가 담당).
     *
     * @param  Request  $request  HTTP 요청
     * @return Post|null 부모 게시글
     */
    private function resolveParentPost(Request $request): ?Post
    {
        $injected = $request->attributes->get('sirsoft_board_parent_post');
        if ($injected instanceof Post) {
            return $injected;
        }

        return $this->resource->relationLoaded('post') ? $this->post : null;
    }

    /**
     * 권한에 따라 필터링된 댓글 내용을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string|null  $slug  게시판 슬러그
     * @return string|null 필터링된 댓글 내용
     */
    private function getFilteredContent(Request $request, ?string $slug): ?string
    {
        // 부모 게시글이 비밀글이면 열람 권한 없는 요청에는 댓글 원문을 숨긴다
        // (KVE-2026-1914 이중 방어 — 1차 차단은 CommentController::index).
        // 컨트롤러가 넘긴 부모 post(요청 속성)를 재사용해 댓글당 lazy-load 없이 재확인한다.
        $parentPost = $this->resolveParentPost($request);
        if ($parentPost && $parentPost->is_secret && ! PostResource::canViewSecretForPost($parentPost, $request)) {
            return null;
        }

        // 댓글 자신이 비밀이면 댓글 작성자·부모글을 열 수 있는 사람에게만 원문을 준다
        // (KVE-2026-2371). 비밀글과 같은 모양 — content=null + is_secret 플래그로 화면이 안내한다.
        // 부모 게시글을 해석하지 못하면 게이트가 fail-closed 로 가린다.
        if ($this->is_secret
            && ! app(SecretContentGate::class)->canViewSecretComment($this->resource, $parentPost, $request)) {
            return null;
        }

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
