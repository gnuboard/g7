<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoRenderer;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 봇 화면 `_global.site_url` 의 폴백은 요청이 아니라 **설정된 사이트 주소**(`app.url`)를 따른다.
 *
 * 운영자가 `general.site_url` 을 비워 둔 사이트에서 종전 폴백은 `url('/')` — 요청 호스트를
 * 실었다. 같은 화면의 canonical·og:url 은 설정 오리진을 싣고 JSON-LD `WebSite.url` 만 요청
 * 호스트를 실으면, 앞단 프록시가 TLS 를 끝내는 배치에서 한 페이지 안의 두 절대 주소가 갈린다
 * (16차 G3 라운드 B-6).
 */
class SeoRendererSiteUrlFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects bot_global_site_url_fallback_follows_the_configured_site_url
     */
    public function test_site_url_fallback_uses_the_configured_site_url(): void
    {
        Config::set('app.url', 'https://example.com/g7');
        app()->instance('request', Request::create('http://localhost/products/1'));

        // 운영자가 `general.site_url` 을 비워 둔 사이트 — 키 자체를 걷어내야 폴백이 실행된다
        // (`null`/`''` 로 두면 그 값이 그대로 돌아와 폴백이 판정되지 않는다).
        $settings = Config::get('g7_settings', []);
        Arr::forget($settings, 'core.general.site_url');
        Config::set('g7_settings', $settings);
        $this->assertNull(g7_core_settings('general.site_url'), '픽스처가 site_url 을 비우지 못했다');

        $global = (new \ReflectionMethod(SeoRenderer::class, 'buildGlobalContext'))
            ->invoke(app(SeoRenderer::class));

        // 종전 `url('/')` 과 같은 형태(후행 슬래시 없음) — 오리진·접두사만 설정을 따른다.
        $this->assertSame('https://example.com/g7', $global['site_url']);
    }
}
