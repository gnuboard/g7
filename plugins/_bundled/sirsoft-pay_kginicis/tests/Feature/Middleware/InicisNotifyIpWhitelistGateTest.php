<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Middleware;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use Plugins\Sirsoft\PayKginicis\Http\Middleware\InicisNotifyIpWhitelist;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * InicisNotifyIpWhitelist self-gate 타게팅 정밀도 회귀 테스트.
 *
 * IP 화이트리스트는 KG 이니시스 서버 발신 webhook 라우트(cvs-notify/vbank-notify/mobile.vbank-notify)
 * 에만 부착되고, 브라우저 POST 콜백(payment.callback)에는 부착되지 않아야 한다. self 로 뭉치면
 * 콜백까지 IP 차단돼 결제 실패. 미들웨어 자체가 testing 환경에서 IP 검사를 우회하므로(403 재현 불가),
 * 게이트 매칭 여부(registry 인덱스)로 타게팅 정밀도를 직접 관측한다.
 */
class InicisNotifyIpWhitelistGateTest extends PluginTestCase
{
    private function resolveWeb(string $routeName): array
    {
        ExtensionMiddlewareRegistry::flush();

        return $this->app->make(ExtensionMiddlewareRegistryInterface::class)
            ->resolveForRoute($routeName, 'x', 'web', 'after_core');
    }

    /**
     * @effects pay_ip_guard_matched_for_webhook_routes
     */
    public function test_ip_guard_matched_for_webhook_routes(): void
    {
        foreach ([
            'web.plugins.sirsoft-pay_kginicis.payment.cbt.cvs-notify',
            'web.plugins.sirsoft-pay_kginicis.payment.vbank-notify',
            'web.plugins.sirsoft-pay_kginicis.payment.mobile.vbank-notify',
        ] as $routeName) {
            $this->assertContains(
                InicisNotifyIpWhitelist::class,
                $this->resolveWeb($routeName),
                "IP 가드가 webhook 라우트({$routeName})에 매칭되어야 합니다.",
            );
        }
    }

    /**
     * @effects pay_ip_guard_not_matched_for_browser_callback
     */
    public function test_ip_guard_not_matched_for_browser_callback(): void
    {
        // 정밀도 회귀: 브라우저 콜백(사용자 IP)에는 IP 가드가 매칭되지 않아야 결제 회귀가 없다.
        $this->assertNotContains(
            InicisNotifyIpWhitelist::class,
            $this->resolveWeb('web.plugins.sirsoft-pay_kginicis.payment.callback'),
            'IP 가드가 브라우저 콜백에 잘못 매칭되면 결제 콜백이 차단됩니다 (정밀도 회귀).',
        );
    }
}
