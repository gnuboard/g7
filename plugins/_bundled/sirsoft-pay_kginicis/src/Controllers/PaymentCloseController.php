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

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {}

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

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
