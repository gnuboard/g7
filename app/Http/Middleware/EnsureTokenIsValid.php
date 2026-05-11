<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenIsValid
{
    /**
     * 들어오는 요청을 처리하고 토큰 유효성을 확인합니다.
     *
     * @param Request $request HTTP 요청
     * @param Closure $next 다음 미들웨어 또는 요청 핸들러
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => __('auth.unauthenticated'),
            ], 401);
        }

        return $next($request);
    }
}
