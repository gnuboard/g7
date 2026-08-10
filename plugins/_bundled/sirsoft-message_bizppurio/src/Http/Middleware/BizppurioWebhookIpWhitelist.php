<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 비즈뿌리오 webhook(URL PUSH) IP 화이트리스트 미들웨어.
 *
 * 비즈뿌리오 공식 발송 IP 만 허용한다. webhook 은 토큰/IDV 미들웨어를 제외(라우트
 * 레벨 withoutMiddleware)하고 인증을 이 IP 화이트리스트로 대체하므로, 이 게이트가
 * 위·변조 리포트에 대한 1차 방어선이다.
 *
 * 환경별 우회를 두지 않는다(계획서 결정 A). testing/local 에서도 동일하게 IP 를
 * 검증해, "차단 IP → 403" 이 테스트로 실증되고 프로덕션과 동일 경로를 검증한다.
 */
class BizppurioWebhookIpWhitelist
{
    /** 비즈뿌리오 공식 URL PUSH 발송 IP (매뉴얼 부록 C-4) */
    private const ALLOWED_IPS = [
        '115.71.53.78',
        '115.71.53.79',
        '115.71.53.94',
        '115.71.53.95',
    ];

    /**
     * 요청 IP 가 화이트리스트에 있는지 확인하고 통과 여부를 결정합니다.
     *
     * @param  Request  $request  들어온 HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 다음 미들웨어 응답 또는 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->ip(), self::ALLOWED_IPS, true)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
