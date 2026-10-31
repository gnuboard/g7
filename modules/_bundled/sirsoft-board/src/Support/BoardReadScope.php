<?php

namespace Modules\Sirsoft\Board\Support;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시글 읽기 권한(`posts.read`·`comments.read`)의 스코프 재적용 보조
 *
 * 두 권한의 스코프 메타는 `resource_route_key = post|comment`, `owner_key = user_id` 다. 게시판 라우트는
 * `{postId}`·`{commentId}` 를 쓰므로 미들웨어의 스코프 검사(라우트 Model 바인딩 때만 동작)가 돌지 않는다.
 * 상세·목록은 서비스·저장소가 스코프를 다시 걸지만, 그 밖의 경로 — 신고·스크랩·반응(단건 대상),
 * 최근글·인기글(캐시된 배열), 공개 프로필·통합검색(여러 게시판 가로지르기) — 는 이 클래스로 같은 판정을 한다.
 *
 * 판정은 코어 `PermissionHelper::checkScopeAccess` 와 같은 의미다: 전체(null)=모두, self=작성자가 나,
 * role=작성자가 나와 역할을 하나라도 공유(비회원 글은 닫힘). 보유 여부는 호출부(라우트 미들웨어·
 * `PermissionHelper::check`)가 먼저 본다 — 이 클래스는 스코프만 본다. 비회원은 스코프를 가질 수 없다.
 */
final class BoardReadScope
{
    /**
     * 단건 대상(글·댓글)이 열람자의 읽기 스코프 안인지 확인하고, 밖이면 403 예외를 던집니다.
     *
     * 대상이 없으면 그대로 돌아간다 — 존재하지 않음(404)은 호출부의 기존 흐름이 판정한다.
     * 댓글은 댓글 읽기 스코프와 그 부모 글의 읽기 스코프를 모두 본다(라우트가 두 권한을 함께 요구).
     *
     * @param  Board  $board  게시판
     * @param  string  $targetType  대상 유형 ('post' 또는 'comment')
     * @param  int  $targetId  대상 ID
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 스코프 밖 대상인 경우 (`auth.scope_denied`)
     */
    public static function assertTargetReadable(Board $board, string $targetType, int $targetId): void
    {
        if (! Auth::check()) {
            return;
        }

        $posts = app(PostRepositoryInterface::class);
        $prefix = "sirsoft-board.{$board->slug}";

        if ($targetType === 'comment') {
            $comment = app(CommentRepositoryInterface::class)->findByBoardId($board->id, $targetId);
            if ($comment === null) {
                return;
            }

            if (! PermissionHelper::checkScopeAccess($comment, "{$prefix}.comments.read")) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            $post = $posts->findByBoardId($board->id, (int) $comment->post_id);
        } else {
            $post = $posts->findByBoardId($board->id, $targetId);
        }

        if ($post !== null && ! PermissionHelper::checkScopeAccess($post, "{$prefix}.posts.read")) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * 캐시된 목록 항목(작성자 ID 만 아는 배열)이 열람자의 읽기 스코프 안인지 판정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int|null  $ownerId  글 작성자 회원 ID (비회원 글이면 null)
     * @return bool 스코프 안이면 true
     */
    public static function allowsItem(string $slug, ?int $ownerId): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return true;
        }

        $scope = self::effectiveScope($user, "sirsoft-board.{$slug}.posts.read");

        if ($scope === null) {
            return true;
        }

        if ($ownerId === null) {
            return false;
        }

        return $scope === 'self'
            ? $ownerId === (int) $user->id
            : self::sharesRole($user, $ownerId);
    }

    /**
     * 특정 회원의 글을 모은 목록(공개 프로필)에서 열람자가 그 회원 글을 읽을 수 없는 게시판 ID 를 돌려줍니다.
     *
     * 목록의 작성자가 한 명이라 게시판 단위로 판정이 끝난다 — self 면 작성자가 나일 때만,
     * role 이면 작성자와 역할을 공유할 때만 그 게시판 글이 보인다.
     *
     * @param  iterable<Board>  $boards  열람자가 읽기 권한을 가진 게시판들
     * @param  int  $ownerId  목록 작성자 회원 ID
     * @return array<int, int> 제외할 게시판 ID
     */
    public static function excludedBoardIdsForOwner(iterable $boards, int $ownerId): array
    {
        $excluded = [];

        foreach ($boards as $board) {
            if (! self::allowsItem((string) $board->slug, $ownerId)) {
                $excluded[] = (int) $board->id;
            }
        }

        return $excluded;
    }

    /**
     * 여러 게시판을 가로지르는 쿼리(통합검색)에 걸 읽기 스코프 명세를 만듭니다.
     *
     * 반환 `self`·`role` 이 모두 비면 스코프가 없는 것이다(저장소는 아무것도 덧붙이지 않는다).
     *
     * @param  iterable<Board>  $boards  열람자가 읽기 권한을 가진 게시판들
     * @param  User|null  $user  열람자 (null 이면 현재 인증 사용자, 비회원이면 스코프 없음)
     * @return array{full: array<int, int>, self: array<int, int>, role: array<int, int>, user_id: int|null, role_ids: array<int, int>} 명세
     */
    public static function resolve(iterable $boards, ?User $user = null): array
    {
        $user ??= Auth::user();
        $spec = ['full' => [], 'self' => [], 'role' => [], 'user_id' => null, 'role_ids' => []];

        foreach ($boards as $board) {
            $scope = $user instanceof User
                ? self::effectiveScope($user, "sirsoft-board.{$board->slug}.posts.read")
                : null;

            $spec[$scope ?? 'full'][] = (int) $board->id;
        }

        if ($user instanceof User && ($spec['self'] !== [] || $spec['role'] !== [])) {
            $spec['user_id'] = (int) $user->id;
            $spec['role_ids'] = $spec['role'] !== []
                ? $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return $spec;
    }

    /**
     * 권한의 유효 스코프를 돌려줍니다 (스코프 메타가 없는 권한이면 null — 전체로 본다).
     *
     * @param  User  $user  열람자
     * @param  string  $identifier  권한 식별자
     * @return string|null null(전체)·'self'·'role'
     */
    private static function effectiveScope(User $user, string $identifier): ?string
    {
        if (PermissionHelper::getOwnerKey($identifier) === null) {
            return null;
        }

        $scope = $user->getEffectiveScopeForPermission($identifier);

        return in_array($scope, ['self', 'role'], true) ? $scope : null;
    }

    /**
     * 작성자가 열람자와 역할을 하나라도 공유하는지 확인합니다 (요청 단위 메모).
     *
     * @param  User  $viewer  열람자
     * @param  int  $ownerId  작성자 회원 ID
     * @return bool 공유하면 true
     */
    private static function sharesRole(User $viewer, int $ownerId): bool
    {
        $request = request();
        $memo = $request->attributes->get(BoardPermissionCacheKeys::READ_SCOPE_ROLE_PEERS, []);
        $key = $viewer->id.'|'.$ownerId;

        if (! array_key_exists($key, $memo)) {
            $myRoleIds = $viewer->roles()->pluck('roles.id')->all();
            $owner = app(UserRepositoryInterface::class)->findById($ownerId);

            $memo[$key] = $owner !== null
                && $myRoleIds !== []
                && $owner->roles()->whereIn('roles.id', $myRoleIds)->exists();
            $request->attributes->set(BoardPermissionCacheKeys::READ_SCOPE_ROLE_PEERS, $memo);
        }

        return $memo[$key];
    }
}
