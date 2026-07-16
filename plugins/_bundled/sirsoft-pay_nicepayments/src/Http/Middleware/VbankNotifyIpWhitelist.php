<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 가상계좌 입금통보 IP 화이트리스트 미들웨어
 *
 * NicePay 공식 발송 IP 만 허용. 로컬/테스트 환경(127.0.0.1, ::1)은 예외.
 * 위변조/재처리 방어의 1차 게이트로, FormRequest::authorize() 대신 라우트
 * 미들웨어 계층에서 처리해 권한 책임을 명확히 분리한다.
 */
class VbankNotifyIpWhitelist
{
    /** 나이스페이먼츠 공식 입금통보 발송 IP — 매뉴얼 INBOUND 섹션 */
    private const ALLOWED_IPS = [
        '121.133.126.10',
        '121.133.126.11',
        '211.33.136.39',
    ];

    /**
     * 요청 IP 가 화이트리스트에 있는지 확인하고 통과 여부 결정
     *
     * @param  Request  $request  들어온 HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 다음 미들웨어 응답 또는 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing', 'local')) {
            return $next($request);
        }

        if (! in_array($request->ip(), self::ALLOWED_IPS, true)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
