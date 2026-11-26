<?php

namespace App\Http\Middleware;

use App\Seo\BotDetector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 봇 요청에 `seo.is_bot` 표식만 남기는 미들웨어.
 *
 * SEO 렌더(`seo`)를 거치지 않는 개발자 화면(custom 예약 자리)에서 방문자 통계가 봇을 사람 방문으로
 * 세지 않도록, SEO 미들웨어와 같은 판정({@see BotDetector::markBotRequest()})으로 같은 표식을 남긴다.
 * 응답은 바꾸지 않는다 — 개발자 HTML 이 곧 봇이 받는 HTML 이다.
 *
 * @since 7.2.0
 */
class MarkBotRequest
{
    /**
     * @param  BotDetector  $botDetector  봇 판별기
     */
    public function __construct(
        private readonly BotDetector $botDetector,
    ) {}

    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->botDetector->markBotRequest($request);

        return $next($request);
    }
}
