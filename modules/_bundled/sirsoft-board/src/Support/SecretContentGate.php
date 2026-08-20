<?php

namespace Modules\Sirsoft\Board\Support;

use App\Enums\PermissionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Post;
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
 * 3. 게시판별 비밀글 읽기 권한 (posts.read-secret)
 * 4. 게시판 관리자 권한 (Admin: admin.manage / User: manager)
 */
class SecretContentGate
{
    use ChecksBoardPermission;

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

        if ($this->isAdminRequest($request)) {
            return $this->checkBoardPermission($slug, 'admin.posts.read-secret')
                || $this->checkBoardPermission($slug, 'admin.manage');
        }

        return $this->checkBoardPermission($slug, 'posts.read-secret', PermissionType::User)
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
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
