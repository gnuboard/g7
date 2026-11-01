<?php

namespace App\Http\Middleware;

use App\Support\Csp\CspPolicyBuilder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 코어 CSP 헤더 부착 미들웨어 (#158)
 *
 * 관리자 환경설정 > 보안 > 「콘텐츠 보안 정책」 모드가 끔이 아니면 `web` 그룹의 HTML 응답에
 * `Content-Security-Policy`(강제) 또는 `Content-Security-Policy-Report-Only`(점검만)를 붙인다.
 * 정책 내용은 {@see CspPolicyBuilder} 가 조립한다.
 *
 * 붙이지 않는 응답:
 *   - HTML 이 아닌 응답(JSON·파일·리다이렉트 본문 없음)
 *   - 개발 대시보드 `/dev` — 디버그 + 최고관리자 전용이고 인라인 코드가 많다
 *   - 검색 봇 SEO HTML — 사람 브라우저에 가지 않고, 봇 렌더러가 분석 스크립트를 인라인으로 심는다
 *   - 이미 CSP 헤더를 단 응답 — 응답별 정책(첨부 SVG 등)을 덮지 않는다
 *
 * 플러그인 웹 라우트(결제·본인인증 브리지)도 `web` 그룹이라 함께 적용된다. 설치기와 미설치 첫 화면은
 * Laravel 밖이라 적용되지 않는다(운영자 웹서버 헤더만 영향).
 */
class ContentSecurityPolicy
{
    /**
     * 응답에 CSP 헤더를 붙입니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 헤더를 붙였거나 그대로인 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('dev') || $request->is('dev/*')) {
            return $response;
        }

        if ($request->attributes->get('g7.seo_bot_response') === true) {
            return $response;
        }

        if ($response->headers->has('Content-Security-Policy') || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (stripos($contentType, 'text/html') === false) {
            return $response;
        }

        $builder = app(CspPolicyBuilder::class);
        $header = CspPolicyBuilder::headerName($builder->mode());

        if ($header === null) {
            return $response;
        }

        $response->headers->set($header, $builder->build());

        return $response;
    }
}
