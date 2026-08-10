<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 요청에서 세션만 시작하는 미들웨어.
 *
 * EnsureFrontendRequestsAreStateful과 달리:
 * - 세션 파이프라인 실행 (EncryptCookies, AddQueuedCookies, StartSession)
 * - sanctum 속성 미설정 → Sanctum Guard가 세션 인증을 시도하지 않음 (Bearer 토큰만 확인)
 * - CSRF 검증 생략 (API는 Bearer 토큰으로 인증)
 *
 * 적용 대상: 로그인/로그아웃 라우트 (세션 생성/파기용, /dev 대시보드 인증 지원)
 */
class StartApiSession
{
    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        return (new Pipeline(app()))
            ->send($request)
            ->through($this->sessionPipeline())
            ->then(fn ($request) => $next($request));
    }

    /**
     * 세션 파이프라인 미들웨어 목록을 반환합니다.
     *
     * CSRF 검증(VerifyCsrfToken)과 sanctum 속성 설정은 포함하지 않습니다.
     *
     * @return array<class-string> 미들웨어 클래스 목록
     */
    protected function sessionPipeline(): array
    {
        return [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ];
    }
}
