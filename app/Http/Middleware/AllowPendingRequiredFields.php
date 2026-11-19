<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 추가 정보 입력 요구 관문({@see EnsureRequiredFieldsCompleted}) 통과 표식.
 *
 * 하는 일은 없다 — 라우트에 이 별칭(`allow.pending_required_fields`)이 붙어 있으면 비어 있는 필수 항목이 있는 회원의
 * 쓰기 요청도 막지 않는다. 회원이 빠져나갈 길(로그아웃, 내 정보와 추가 정보 저장, 비밀번호 변경, 본인인증, 탈퇴)과
 * 사용자 콘텐츠를 쓰지 않는 기술 요청에만 붙인다.
 *
 * @since 7.2.0
 */
class AllowPendingRequiredFields
{
    /**
     * 요청을 그대로 넘깁니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
