<?php

namespace Modules\Sirsoft\Board\Support;

use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시판 관리 권한의 게시판 스코프 재적용 가드
 *
 * 블라인드·복원·일괄 처리 라우트는 미들웨어가 `sirsoft-board.{slug}.admin.manage` 하나만 본다.
 * 그 권한의 스코프 메타는 `resource_route_key = board` 인데 라우트가 `{slug}` 를 쓰므로 미들웨어의
 * 스코프 검사(라우트 Model 바인딩 때만 동작)가 돌지 않는다. 그래서 서비스 진입부에서 이 가드로
 * 같은 판정을 다시 한다 — 「본인이 만든 게시판만」으로 위임받은 관리자가 남의 게시판 글을
 * 블라인드·복원·일괄 처리하지 못한다.
 *
 * 보유 확인은 라우트 미들웨어 몫이고 이 가드는 스코프만 본다(보유하지 않은 사용자는 유효 스코프가
 * 없어 통과한다 — 서비스를 내부에서 부르는 경로를 막지 않는다). 비로그인(시스템·스케줄) 호출과
 * 신고 처리(`reports.manage` 관할 — 호출부가 triggerType 을 넘긴다)는 대상이 아니다.
 */
final class BoardManageScope
{
    use ChecksBoardPermission;

    /**
     * 게시판 관리 권한이 이 게시판의 스코프 안인지 확인하고, 밖이면 403 예외를 던집니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $action  관리 권한 액션 ('admin.manage' 또는 'manager')
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 스코프 밖 게시판인 경우 (`auth.scope_denied`)
     */
    public static function assert(string $slug, string $action = 'admin.manage'): void
    {
        $guard = new self;

        if (! $guard->passesBoardScope($slug, "sirsoft-board.{$slug}.{$action}")) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }
}
