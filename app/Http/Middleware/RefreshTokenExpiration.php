<?php

namespace App\Http\Middleware;

use App\Services\Auth\TokenExpirySlider;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RefreshTokenExpiration
{
    /**
     * 인증 토큰의 만료 시간을 슬라이딩 방식으로 갱신합니다.
     *
     * 토큰 만료까지 남은 시간이 전체 유지시간의 절반 미만이면
     * 만료 시간을 현재 시간 + 설정값으로 재설정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 인증된 사용자가 아니면 패스
        $user = $request->user();
        if (! $user) {
            return $response;
        }

        // 현재 토큰 가져오기
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->expires_at) {
            return $response;
        }

        // 컨트롤러가 이 토큰을 지웠으면(로그아웃·갱신) 늘리지 않는다 — 지운 토큰을 다시 살리지 않는다(KVE-2026-3187).
        // 연장 저장도 행이 있을 때만 바뀌는 UPDATE 다(UserRepository::updateAccessTokenExpiry).
        if (! $token->exists) {
            return $response;
        }

        // 연장 규칙은 개발자 화면 요청(ScreenAuth)과 공유한다
        app(TokenExpirySlider::class)->slide($token, $request);

        return $response;
    }
}
