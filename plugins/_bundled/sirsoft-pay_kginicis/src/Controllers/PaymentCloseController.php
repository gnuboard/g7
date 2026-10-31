<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PluginSettingsService;
use Illuminate\Http\Response;

class PaymentCloseController extends Controller
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const CLOSE_JS_URL_TEST = 'https://stgstdpay.inicis.com/stdjs/INIStdPay_close.js';

    private const CLOSE_JS_URL_LIVE = 'https://stdpay.inicis.com/stdjs/INIStdPay_close.js';

    /**
     * 테스트/운영 모드 판정에 쓸 플러그인 설정 서비스를 주입받는다.
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {}

    /**
     * 결제창을 닫는 중계 문서를 돌려줍니다.
     *
     * PG 결제창은 자기 도메인에서 열리므로 창을 닫는 스크립트를 이쪽 오리진에서 한 번
     * 거쳐야 한다. 파일이 아니라 이 자리에서 만든 문서라 서빙 계약의 대상이 아니다.
     *
     * @return Response 결제창 닫기 HTML (no-store)
     */
    public function show(): Response
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $closeJsUrl = ($settings['is_test_mode'] ?? true)
            ? self::CLOSE_JS_URL_TEST
            : self::CLOSE_JS_URL_LIVE;

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="ko">
        <head>
            <meta charset="UTF-8">
            <title>KG 이니시스 결제창 닫기</title>
            <script>
                (function () {
                    var payload = {
                        source: 'sirsoft-pay_kginicis',
                        type: 'payment-window-closed',
                        reason: 'inicis-close-url'
                    };
                    var targetOrigin = window.location.origin;

                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage(payload, targetOrigin);
                        }

                        if (window.opener && !window.opener.closed) {
                            window.opener.postMessage(payload, targetOrigin);
                        }
                    } catch (error) {
                        // 결제창 닫기 페이지는 상태 알림 실패와 무관하게 KG close script를 실행한다.
                    }
                })();
            </script>
            <script src="{$closeJsUrl}" charset="UTF-8"></script>
        </head>
        <body></body>
        </html>
        HTML;

        // file-serving:allow 파일이 아니라 이 자리에서 만든 결제창 닫기 문서를 돌려준다 — 서빙 계약
        // (nosniff·ETag·304·압축 제외) 의 대상이 아니다. 표식이 없으면 소스 계약 검사가 이
        // 메서드를 파일 서빙으로 읽는다. 형제 인증 브리지 둘과 같은 처분이며, 이 4개는
        // `src/Controllers` 를 써서 계약 모집단 자체에 없었다 (23차 G3 라운드 B23-6).
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
