<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Gdpr\Support\PrivacyPolicyUrl;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * 연례 통지에 동결되는 개인정보처리방침 주소가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 통지 스냅샷은 관리자가 직권 실행한 요청 안에서(동기 큐) 만들어질 수 있고, 그 값이 전 회원 통지
 * 링크로 그대로 나간다.
 */
class PrivacyPolicyUrlHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 사이트 내부 경로는 사이트 주소로, https 절대 주소는 그대로 둔다.
     *
     * @scenario host_header=forged, sink=notification_link
     *
     * @effects notification_link_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_relative_policy_url_uses_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/api/plugins/sirsoft-gdpr/admin/annual-notice');

        $this->assertSame(self::forgedHostSite().'/page/privacy', PrivacyPolicyUrl::toAbsolute('/page/privacy'));
        $this->assertSame('https://policy.example.org/privacy', PrivacyPolicyUrl::toAbsolute('https://policy.example.org/privacy'));
    }
}
