<?php

namespace App\Http\Middleware;

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

        // 설정에서 토큰 유지시간 조회 (분)
        $lifetime = (int) g7_core_settings('security.auth_token_lifetime', 30);
        if ($lifetime === 0) {
            return $response; // 무한대면 갱신 불필요
        }

        // 남은 시간 계산 (초 단위로 정확히 계산)
        $remainingSeconds = now()->diffInSeconds($token->expires_at, false);
        $thresholdSeconds = ($lifetime / 2) * 60;

        // 임계값 미만이고 아직 만료 전이면 갱신
        if ($remainingSeconds > 0 && $remainingSeconds < $thresholdSeconds) {
            $token->expires_at = now()->addMinutes($lifetime);
            $token->save();

            // 세션 만료 시간도 토큰과 동기화 (웹 세션이 있는 경우)
            if ($request->hasSession()) {
                config(['session.lifetime' => $lifetime]);
            }
        }

        return $response;
    }
}
