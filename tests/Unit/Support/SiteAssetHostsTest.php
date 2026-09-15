<?php

namespace Tests\Unit\Support;

use App\Support\SiteAssetHosts;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 사이트 자산 host 판정 단위 테스트
 *
 * 저장 게이트(NoExternalUrls)가 "서버가 스스로 발급하는 자산 주소" 를 외부로 오판하지
 * 않도록, 사이트 자기 host(app.url)와 운영자가 선언한 공개 자산 디스크의 host 를 한
 * 지점에서 해석한다. 요청 Host 헤더는 근거로 쓰지 않는다 — 위조 가능하다.
 */
class SiteAssetHostsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', 'https://shop.example.test');
        Config::set('core.storage.public_asset_disk', '');
    }

    /**
     * app.url 의 host 는 항상 포함되어야 합니다 (소문자 정규화).
     *
     * @scenario url_host=own_site_absolute
     *
     * @effects issued_asset_url_passes_storage_gate
     */
    public function test_hosts_include_app_url_host(): void
    {
        Config::set('app.url', 'https://Shop.Example.TEST:8443/sub');

        $this->assertContains('shop.example.test', SiteAssetHosts::hosts());
    }

    /**
     * 공개 자산 디스크가 선언되어 있으면 그 디스크의 기준 URL host 가 포함되어야 합니다.
     *
     * @scenario url_host=public_asset_disk
     *
     * @effects issued_asset_url_passes_storage_gate
     */
    public function test_hosts_include_declared_public_asset_disk_host(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        Config::set('filesystems.disks.public.url', 'https://cdn.example.test/assets');

        $this->assertContains('cdn.example.test', SiteAssetHosts::hosts());
    }

    /**
     * 미선언·'none'·고아 디스크는 host 를 보태지 않아야 합니다 — 판정은 PublicAssetDisk::resolve()
     * 를 경유하므로 디스크에 url 이 설정돼 있어도 선언 없이는 외부다.
     *
     * @scenario url_host=external_undeclared
     *
     * @effects external_host_still_rejected
     */
    public function test_undeclared_disk_host_is_not_included(): void
    {
        Config::set('filesystems.disks.public.url', 'https://cdn.example.test/assets');

        foreach (['', 'none', 'vanished_plugin_disk'] as $declared) {
            Config::set('core.storage.public_asset_disk', $declared);

            $this->assertNotContains('cdn.example.test', SiteAssetHosts::hosts(), "선언값 '{$declared}' 에서 host 가 새면 안 됩니다");
        }
    }

    /**
     * http/https 절대 URL 이고 host 가 목록에 있을 때만 참이어야 합니다.
     *
     * @scenario url_host=own_site_absolute
     *
     * @effects issued_asset_url_passes_storage_gate
     */
    public function test_is_site_asset_url_accepts_only_http_urls_on_listed_hosts(): void
    {
        $this->assertTrue(SiteAssetHosts::isSiteAssetUrl('https://shop.example.test/api/x'));
        $this->assertTrue(SiteAssetHosts::isSiteAssetUrl('http://shop.example.test/storage/x.png'));
        $this->assertTrue(SiteAssetHosts::isSiteAssetUrl("  https://shop.example.test/x\n"));

        // 경로만 있는 값은 host 가 없으므로 이 판정의 대상이 아니다 (호출측이 경로 규칙으로 다룬다)
        $this->assertFalse(SiteAssetHosts::isSiteAssetUrl('/api/x'));
        // protocol-relative 는 별도 판정 축 — 자기 host 라도 여기서는 참이 아니다
        $this->assertFalse(SiteAssetHosts::isSiteAssetUrl('//shop.example.test/x'));
        // 위험 스킴은 host 와 무관
        $this->assertFalse(SiteAssetHosts::isSiteAssetUrl('ftp://shop.example.test/x'));
        $this->assertFalse(SiteAssetHosts::isSiteAssetUrl('javascript://shop.example.test/%0aalert(1)'));
    }

    /**
     * 자기 host 를 흉내 낸 URL 은 거짓이어야 합니다 — 브라우저와 같은 정규화 뒤 host 를 비교한다.
     *
     * @scenario url_host=lookalike
     *
     * @effects external_host_still_rejected
     */
    public function test_is_site_asset_url_rejects_lookalikes(): void
    {
        foreach ([
            'https://shop.example.test.evil.com/x',
            'https://shop.example.test@evil.com/x',
            'https://evil.com\\@shop.example.test/x',
            'https://evil.com/shop.example.test/x',
            'https://evil.com#shop.example.test',
            "https://evil.com\t.shop.example.test/x",
        ] as $url) {
            $this->assertFalse(SiteAssetHosts::isSiteAssetUrl($url), "흉내 URL 은 거짓이어야 합니다: {$url}");
        }
    }

    /**
     * app.url 이 비어 있거나 host 를 못 읽는 값이면 빈 목록이어야 합니다 (예외 없음).
     *
     * @scenario url_host=external_undeclared
     *
     * @effects external_host_still_rejected
     */
    public function test_blank_app_url_yields_no_host(): void
    {
        foreach (['', 'not a url', '/relative'] as $value) {
            Config::set('app.url', $value);

            $this->assertSame([], SiteAssetHosts::hosts(), "app.url='{$value}' 에서 host 가 생기면 안 됩니다");
            $this->assertFalse(SiteAssetHosts::isSiteAssetUrl('https://shop.example.test/x'));
        }
    }
}
