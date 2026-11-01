<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PluginSettingsService;
use Illuminate\Http\Response;
use Plugins\Sirsoft\PayKginicis\Support\BridgeScript;

class PaymentCloseController extends Controller
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const CLOSE_JS_URL_TEST = 'https://stgstdpay.inicis.com/stdjs/INIStdPay_close.js';

    private const CLOSE_JS_URL_LIVE = 'https://stdpay.inicis.com/stdjs/INIStdPay_close.js';

    /**
     * 결제창 닫기 컨트롤러를 생성합니다.
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 조회 (테스트 모드 판정)
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {}

    /**
     * PC 표준결제창 닫기 페이지(closeUrl)를 돌려줍니다.
     *
     * 주문 화면 알림 브리지(`assets/bridge/payment-close.js`)를 이니시스 닫기 스크립트보다 먼저 싣는다.
     *
     * @return Response 닫기 페이지 HTML (캐시 금지)
     */
    public function show(): Response
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $closeJsUrl = ($settings['is_test_mode'] ?? true)
            ? self::CLOSE_JS_URL_TEST
            : self::CLOSE_JS_URL_LIVE;

        // 주문 화면에 닫힘을 알리는 동작은 정적 브리지 파일이 한다 — 인라인 <script> 는 strict CSP 에서
        // 실행되지 않아 주문 화면이 '결제 중' 에 머문다(#158). 알림이 이니시스 닫기 스크립트보다 먼저다.
        $bridgeScript = BridgeScript::tag('payment-close.js');

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="ko">
        <head>
            <meta charset="UTF-8">
            <title>KG 이니시스 결제창 닫기</title>
            {$bridgeScript}
            <script src="{$closeJsUrl}" charset="UTF-8"></script>
        </head>
        <body></body>
        </html>
        HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
