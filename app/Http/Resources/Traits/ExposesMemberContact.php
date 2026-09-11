<?php

namespace App\Http\Resources\Traits;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 회원 개인 연락처(이메일 등) 노출 판정을 제공합니다.
 *
 * Resource 와 Collection 이 같은 판정을 써야 하므로 트레이트로 둔다 — 한쪽에만 두면
 * 다른 쪽이 자기 판정을 따로 만들고, 그러면 두 판정이 어긋나는 순간 한쪽이 우회로가 된다.
 */
trait ExposesMemberContact
{
    /**
     * 이 응답에 회원 개인 연락처(이메일)를 실어도 되는지 판정합니다.
     *
     * 목록·상세·댓글처럼 인증 없이 열람되는 공개 경로에 회원 이메일이 실리면,
     * 그 화면이 이메일을 그리지 않더라도 응답 본문에서 회원 주소록을 그대로 수집할 수 있다.
     * 열람 자격은 라우트가 정한다 — 관리자 라우트는 이미 권한 미들웨어를 통과한 뒤이므로,
     * 판정 근거는 "요청이 도달한 컨트롤러가 관리자 컨트롤러인가" 하나이며 클라이언트가
     * 바꿀 수 없다. 헤더·쿼리 같은 클라이언트 입력으로 판정하면 게이트가 곧 우회로가 된다.
     *
     * 본인 데이터는 예외다 — 자기 이메일은 이미 아는 값이라 가려도 보호되는 것이 없고,
     * 마이페이지 계열 화면이 자기 정보를 그린다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략 시 현재 요청)
     * @param  int|null  $ownerId  그 이메일의 주인 사용자 ID (본인 열람 허용 판정용)
     * @return bool 이메일을 직렬화해도 되는지 여부
     */
    protected function canExposeMemberEmail(?Request $request = null, ?int $ownerId = null): bool
    {
        if ($ownerId !== null && Auth::id() === $ownerId) {
            return true;
        }

        $request = $request ?? request();

        return $request->route()?->getController() instanceof AdminBaseController;
    }
}
