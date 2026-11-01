<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\KcpFrameRequest;
use Plugins\Sirsoft\PayNhnkcp\Support\BridgeScript;

/**
 * PC 결제창 프레임 페이지 (#158)
 *
 * 주문 화면이 투명 iframe 으로 연다. KCP SDK 를 정적 `<script src>` 로 먼저 싣고(SDK 가 파싱 중
 * `document.write` 를 쓰므로 동기 로드여야 한다), 뒤이어 브리지(`assets/bridge/kcp-frame.js`)가 부모가 넘긴
 * 주문 값으로 `order_info` 폼을 채워 `KCP_Pay_Execute` 를 부른다.
 *
 * 종전에는 부모가 빈 iframe 에 `document.write` 로 SDK 와 인라인 `<script>` 를 써 넣었다. 사이트가
 * `script-src 'self'` 를 걸면 그 인라인 코드가 실행되지 않아 PC 결제가 15초 뒤 시간 초과로 끝났다.
 * 이 라우트는 web 그룹이라 코어 CSP 헤더도 함께 붙는다. HTML 파일을 자산으로 서빙하지 않고 라우트로
 * 내는 것은 플러그인 자산 서빙이 `.html` 을 허용하지 않기 때문이다.
 */
class KcpFrameController extends Controller
{
    /**
     * 결제창 프레임 HTML 을 돌려줍니다.
     *
     * @param  KcpFrameRequest  $request  허용 SDK 주소가 검증된 요청
     * @return Response 프레임 HTML (캐시 금지)
     */
    public function show(KcpFrameRequest $request): Response
    {
        $sdk = e((string) $request->validated('sdk'));
        $bridgeScript = BridgeScript::tag('kcp-frame.js');

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <script src="{$sdk}"></script>
        </head>
        <body style="margin:0;padding:0;background:transparent;">
            <form name="order_info"></form>
            {$bridgeScript}
        </body>
        </html>
        HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
