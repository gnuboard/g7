<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Http\Response;

/**
 * KCP 공통통보 webhook 표준 응답 헬퍼 (그누보드5 settle_kcp_common.php 동일 패턴)
 *
 * KCP 는 응답 body 의 <input name="result"> 값을 파싱한다.
 *   - result="0000" → 통보 성공 인정 (재시도 차단)
 *   - 그 외        → 재통보 (최대 10회, 0/5/5/10/20/40/80/160/320/640분)
 *
 * vbank-notify / escrow-common-notify 등 KCP 서버 발신 webhook 모두 동일 형식 사용.
 */
trait SendsKcpNotifyResponse
{
    /**
     * KCP 표준 통보 응답 (HTML form 으로 result 코드 전달)
     *
     * @param  string  $resultCode  통보 처리 결과 ("0000" 성공 / 그 외 재통보 유도)
     * @return Response 200 + KCP 표준 HTML body
     */
    protected function kcpNotifyResponse(string $resultCode = '0000'): Response
    {
        $safeCode = htmlspecialchars($resultCode, ENT_QUOTES, 'UTF-8');
        $html = '<html><body><form><input type="hidden" name="result" value="'.$safeCode.'"></form></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 재통보 유도 응답 (DB 일시적 오류 등 — KCP 가 재시도)
     *
     * @return Response 200 + result="9999" HTML
     */
    protected function kcpNotifyRetry(): Response
    {
        return $this->kcpNotifyResponse('9999');
    }
}
