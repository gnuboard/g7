<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional Sanctum 인증 미들웨어
 *
 * Bearer 토큰이 있으면 Sanctum 인증을 시도합니다.
 * - 토큰 있음 + 유효 → 인증된 사용자로 요청 처리
 * - 토큰 있음 + 만료 → 비회원(guest)으로 요청 처리 (공개 페이지 접근 허용)
 * - 토큰 있음 + 무효(위조) → 401 Unauthorized
 * - 토큰 없음 → 비회원(guest)으로 요청 처리
 *
 * 사용 예시:
 * Route::middleware('optional.sanctum')->get('/layouts/{name}.json', ...);
 */
class OptionalSanctumMiddleware
{
    /**
     * 요청 처리
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 이미 인증된 사용자 확인 (다른 미들웨어에서 처리된 경우)
        if ($request->user()) {
            return $next($request);
        }

        // Bearer 토큰 확인
        $token = $request->bearerToken();

        if ($token) {
            // 토큰 직접 조회하여 존재 및 만료 여부 확인
            $accessToken = PersonalAccessToken::findToken($token);

            if (! $accessToken) {
                // 토큰이 DB에 없음 → 위조/무효 토큰 → 401
                return ResponseHelper::unauthorized('auth.invalid_token');
            }

            // 만료 시간 확인 (만료된 토큰은 guest로 통과)
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                // 토큰 만료 → guest로 통과 (로그인 페이지 접근 허용)
                return $next($request);
            }

            // 유효한 토큰 → Sanctum 인증 진행
            $authMiddleware = app(Authenticate::class);

            try {
                $authMiddleware->handle($request, function ($req) {
                    return response('ok'); // 더미 응답 (인증만 처리)
                }, 'sanctum');
            } catch (AuthenticationException $e) {
                // 토큰 인증 실패 → 401 반환
                return ResponseHelper::unauthorized('auth.invalid_token');
            }
        }
        // 토큰 없음 → guest로 통과

        return $next($request);
    }
}
