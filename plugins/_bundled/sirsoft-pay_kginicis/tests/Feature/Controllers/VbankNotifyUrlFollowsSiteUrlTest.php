<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Support\AssetUrl;
use Illuminate\Support\Facades\Config;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 관리자 화면에 표시하는 입금통보 주소는 요청이 아니라 **설정된 사이트 주소**를 따른다.
 *
 * `url()` 은 요청의 호스트·접두사를 싣는다 — 앞단 프록시가 접두사를 벗기거나 내부 호스트명으로
 * 도착하는 배치에서 운영자가 PG 에 등록하는 통보 주소가 사이트 밖이 되고, 입금 통보는 오류 없이
 * 도착하지 않는다.
 */
class VbankNotifyUrlFollowsSiteUrlTest extends PluginTestCase
{
    /**
     * 테스트가 강제한 자산 주소 접두사를 비워 다음 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 요청이 localhost 로 와도 관리자 API 가 돌려주는 PC 와 모바일 입금통보 주소가 설정된 사이트 주소(하위 경로 포함)로 시작하는지 확인한다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects pg_return_and_notify_addresses_follow_the_configured_site_url
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_urls_follow_the_configured_site_url(): void
    {
        Config::set('app.url', 'https://example.com/g7');
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($admin)
            ->getJson('http://localhost/api/plugins/sirsoft-pay_kginicis/admin/vbank-notify-url');

        $response->assertOk();
        $this->assertSame(
            'https://example.com/g7/plugins/sirsoft-pay_kginicis/payment/vbank-notify',
            $response->json('data.url'),
        );
        $this->assertSame(
            'https://example.com/g7/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify',
            $response->json('data.mobile_url'),
        );
    }
}
