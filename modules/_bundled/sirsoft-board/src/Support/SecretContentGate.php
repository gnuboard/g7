<?php

namespace Modules\Sirsoft\Board\Support;

use App\Enums\PermissionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 비밀글 원문 열람 권한 판정 SSoT
 *
 * 비밀글 게이팅이 PostResource 한 곳에만 있던 탓에 이커머스 연동 훅·댓글 목록 등
 * 다른 경로가 서버측 마스킹 없이 원문을 반환하던 결함(KVE-2026-1914)을 막기 위해,
 * 열람 판정 규칙을 한 지점으로 모읍니다. PostResource·리스너·댓글 경로가 모두
 * 이 게이트를 호출해 규칙 드리프트를 방지합니다.
 *
 * 열람 가능 조건 (우선순위 순):
 * 1. 작성자 본인 (회원 게시글)
 * 2. 비밀번호 검증 완료 (`password_verified` 플래그 — 상세 컨텍스트에서만 설정, 리스트 미적용)
 * 2-b. 비밀번호 검증 응답으로 받은 열람 확인 토큰 (`X-Board-Secret-View-Token` 헤더)
 * 3. 게시판별 비밀글 읽기 권한 (posts.read-secret)
 * 4. 게시판 관리자 권한 (Admin: admin.manage / User: manager)
 *
 * 2 와 2-b 는 같은 사실("비밀번호를 맞혔다")의 두 표현입니다. 2 는 검증한 그 응답 안에서만
 * 살고, 2-b 는 그 사실을 다음 요청으로 넘깁니다 — 비회원 작성자가 자기 비밀글에 댓글을 다는
 * 흐름은 별도 요청이라 2-b 가 없으면 성립하지 않습니다.
 */
class SecretContentGate
{
    use ChecksBoardPermission;

    /**
     * 열람 확인 토큰을 싣는 요청 헤더 이름.
     */
    public const VIEW_TOKEN_HEADER = 'X-Board-Secret-View-Token';

    /**
     * 주어진 게시글의 비밀 원문을 현재 요청자가 열람할 수 있는지 판정합니다.
     *
     * 게시판 슬러그를 해석할 수 없으면(라우트 슬러그 부재 + board 미로딩) 안전하게
     * false(마스킹)로 실패합니다.
     *
     * @param  Post  $post  대상 게시글
     * @param  Request|null  $request  HTTP 요청 (미지정 시 현재 요청)
     * @return bool 열람 가능 여부
     */
    public function canView(Post $post, ?Request $request = null): bool
    {
        $request = $request ?? request();

        // 1. 작성자 본인 (회원 게시글)
        $user = Auth::user();
        if ($user && $post->user_id && $post->user_id === $user->id) {
            return true;
        }

        // 2. 비밀번호 검증 완료 (상세 컨텍스트에서만 세팅됨)
        if (($post->password_verified ?? false) === true) {
            return true;
        }

        // 3-4. 게시판별 권한 체크
        $slug = $this->resolveSlug($post, $request);
        if (! $slug) {
            return false;
        }

        // 2-b. 직전 요청에서 비밀번호를 맞히고 받은 열람 확인 토큰.
        //
        // 위 2번 플래그는 비밀번호를 검증한 그 응답 안에서만 삽니다. 그런데 댓글·답글·신고는
        // 각각 별도 요청이라, 토큰이 없으면 원문을 연 사람도 그 사실을 증명할 방법이 없어
        // 화면이 내준 버튼이 전부 거부됩니다. 토큰은 게시글 단위로 묶이고 유효기간이 있어
        // 권한 범위는 비밀번호를 아는 것과 같습니다.
        if ($this->hasSecretViewToken($post, $request, $slug)) {
            return true;
        }

        if ($this->isAdminRequest($request)) {
            return $this->checkBoardPermission($slug, 'admin.posts.read-secret')
                || $this->checkBoardPermission($slug, 'admin.manage');
        }

        return $this->checkBoardPermission($slug, 'posts.read-secret', PermissionType::User)
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
    }

    /**
     * 비밀 댓글의 원문을 현재 요청자가 열람할 수 있는지 판정합니다.
     *
     * 게이트가 게시글에만 걸려 있어, 공개 게시글에 달린 비밀 댓글은 목록·상세 어느 경로로도
     * 원문이 그대로 나가던 결함(KVE-2026-2371)을 막습니다. 비밀 댓글은 게시글과 소유자 집합이
     * 다릅니다 — 댓글 작성자 본인, 대댓글이면 **부모 댓글 작성자**(답글 알림으로 이미 본문을 받는
     * 사람), 그리고 **부모 게시글을 연 사람**(글 작성자·비밀번호를 맞혀 열람 토큰을 받은 사람·
     * 비밀글 읽기 권한자·게시판 관리자)이 봅니다. 마지막 집합은 부모 게시글에 canView() 를 그대로
     * 적용해 얻으므로 규칙이 갈라지지 않습니다.
     *
     * 부모 게시글을 받지 못했고 post 관계도 로드되지 않았으면 lazy-load(N+1) 대신 false 로
     * 실패합니다(fail-closed). 부모 댓글 작성자는 목록 경로가 `parent_comment_user_id_preloaded`
     * 로 미리 실어 주고, 실리지 않은 경로에서만 한 건 조회합니다.
     *
     * @param  Comment  $comment  대상 댓글
     * @param  Post|null  $parentPost  부모 게시글 (호출부가 이미 가진 인스턴스)
     * @param  Request|null  $request  HTTP 요청 (미지정 시 현재 요청)
     * @return bool 열람 가능 여부
     */
    public function canViewSecretComment(Comment $comment, ?Post $parentPost = null, ?Request $request = null): bool
    {
        // 1. 댓글 작성자 본인 (회원 댓글 — 비회원 댓글은 본인 판정 불가)
        $user = Auth::user();
        if ($user && $comment->user_id && $comment->user_id === $user->id) {
            return true;
        }

        $parentPost = $parentPost ?? ($comment->relationLoaded('post') ? $comment->post : null);
        if (! $parentPost) {
            return false;
        }

        // 2. 부모 게시글의 원문을 열 수 있는 사람 (글 작성자·비밀번호 검증·열람 토큰·권한자·관리자)
        if ($this->canView($parentPost, $request)) {
            return true;
        }

        // 3. 대댓글이면 부모 댓글 작성자 (회원 댓글)
        if ($user && $comment->parent_id) {
            $parentCommentUserId = $this->resolveParentCommentUserId($comment);

            return $parentCommentUserId !== null && $parentCommentUserId === (int) $user->id;
        }

        return false;
    }

    /**
     * 댓글 목록의 대댓글마다 부모 댓글 작성자 ID 를 미리 실어 둡니다.
     *
     * 비밀 대댓글 판정(canViewSecretComment 의 3번)이 댓글마다 부모를 조회하지 않도록, 목록을
     * 이미 쥔 호출부가 한 번 돌려 준다. 부모가 이 목록 밖(다른 페이지)이면 싣지 않고 남겨
     * 게이트가 한 건 조회로 폴백하게 한다 — null 을 실으면 "부모가 비회원 댓글" 로 읽혀 막힌다.
     *
     * @param  iterable<Comment>  $comments  같은 게시글의 댓글 목록
     * @return void
     */
    public static function preloadParentCommentOwners(iterable $comments): void
    {
        $ownerById = [];
        foreach ($comments as $comment) {
            $ownerById[(int) $comment->id] = $comment->user_id;
        }

        foreach ($comments as $comment) {
            if ($comment->parent_id && array_key_exists((int) $comment->parent_id, $ownerById)) {
                $comment->parent_comment_user_id_preloaded = $ownerById[(int) $comment->parent_id];
            }
        }
    }

    /**
     * 대댓글의 부모 댓글 작성자 ID 를 해석합니다.
     *
     * 목록 경로는 댓글 전량을 이미 쥐고 있어 `parent_comment_user_id_preloaded` 로 넘겨준다
     * (댓글마다 조회하지 않게). 그 값이 없는 경로(생성 응답 등 단건)에서만 한 건 조회합니다.
     *
     * @param  Comment  $comment  대댓글
     * @return int|null 부모 댓글 작성자 ID (비회원 댓글이거나 부모가 없으면 null)
     */
    private function resolveParentCommentUserId(Comment $comment): ?int
    {
        $attributes = $comment->getAttributes();
        if (array_key_exists('parent_comment_user_id_preloaded', $attributes)) {
            $value = $attributes['parent_comment_user_id_preloaded'];

            return $value === null ? null : (int) $value;
        }

        if ($comment->relationLoaded('parent')) {
            $owner = $comment->parent?->user_id;

            return $owner === null ? null : (int) $owner;
        }

        $owner = app(CommentRepositoryInterface::class)
            ->findByBoardId((int) $comment->board_id, (int) $comment->parent_id)
            ?->user_id;

        return $owner === null ? null : (int) $owner;
    }

    /**
     * 비밀글의 하위 콘텐츠를 생성할 수 있는지 판정합니다.
     *
     * 읽기 게이트(KVE-2026-1914)는 원문 노출만 막았고, 하위 생성 경로(댓글·대댓글·답글·
     * 신고)는 부모 게시글의 열람 권한을 재적용하지 않아 무권한 사용자가 비밀글에 댓글을
     * 달거나 신고를 남길 수 있었다(KVE-2026-2044). 판정은 읽기와 같은 canView() 를
     * 재사용해 강도 분기를 만들지 않는다 — 작성자·비밀글 읽기 권한자·게시판 관리자는
     * 그대로 통과한다.
     *
     * 비밀글이 아니면 항상 통과한다.
     *
     * @param  Post  $post  부모 게시글
     * @param  Request|null  $request  HTTP 요청 (미지정 시 현재 요청)
     * @return bool 하위 콘텐츠 생성 가능 여부
     */
    public function canWriteChild(Post $post, ?Request $request = null): bool
    {
        if (! $post->is_secret) {
            return true;
        }

        return $this->canView($post, $request);
    }

    /**
     * 요청이 제시한 열람 확인 토큰이 이 게시글에 대해 유효한지 판정합니다.
     *
     * 토큰은 헤더로만 받습니다 — 본문으로 받으면 댓글·답글·신고 각각의 FormRequest 에
     * 필드를 더해야 하고, 그 중 한 곳만 빠져도 그 경로에서만 조용히 거부됩니다.
     *
     * @param  Post  $post  대상 게시글
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return bool 유효한 토큰이 제시되었으면 true
     */
    private function hasSecretViewToken(Post $post, Request $request, string $slug): bool
    {
        $token = $request->header(self::VIEW_TOKEN_HEADER);

        if (! is_string($token) || $token === '' || ! $post->id) {
            return false;
        }

        return app(PostService::class)->hasValidSecretViewToken($slug, (int) $post->id, $token);
    }

    /**
     * 게시판 슬러그를 해석합니다.
     *
     * 라우트 슬러그를 우선 사용하고, 없으면 로드된 board 관계에서 가져옵니다.
     * board 가 미로딩이면 lazy loading N+1 을 피하기 위해 null 을 반환합니다
     * (호출부가 fail-closed 마스킹).
     *
     * @param  Post  $post  대상 게시글
     * @param  Request  $request  HTTP 요청
     * @return string|null 게시판 슬러그
     */
    private function resolveSlug(Post $post, Request $request): ?string
    {
        return $request->route('slug') ?? ($post->relationLoaded('board') ? $post->board?->slug : null);
    }
}
