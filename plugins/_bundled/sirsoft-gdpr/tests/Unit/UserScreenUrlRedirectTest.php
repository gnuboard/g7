<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit;

use App\Extension\HookManager;
use App\Support\ScreenUrl;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use Plugins\Sirsoft\Gdpr\Support\PrivacyPolicyUrl;

/**
 * GDPR — 처리방침 주소
 *
 * 메일용 처리방침 절대 주소와 옛 슬러그 경로가 화면 주소 헬퍼를 따르는지 잠근다.
 */
class UserScreenUrlRedirectTest extends PluginTestCase
{
    /**
     * 사이트 주소와 「사용자 화면 URL」을 다르게 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://site.example.test', 'g7_settings.core.general.user_screen_url' => 'https://front.example.test']);
    }

    /**
     * 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);

        parent::tearDown();
    }

    /**
     * 메일용 처리방침 절대 주소는 사용자 화면 URL 이 베이스이고, 옛 슬러그 경로는 화면 주소 헬퍼(page.show)를 거칩니다.
     *
     * @scenario case=privacy_url
     *
     * @effects privacy_url_uses_user_screen_url, legacy_slug_uses_page_helper
     *
     * @return void 반환값 없음
     */
    public function test_privacy_policy_urls(): void
    {
        $this->assertSame('https://front.example.test/page/privacy', PrivacyPolicyUrl::toAbsolute('/page/privacy'));
        $this->assertSame('/page/privacy', PrivacyPolicyUrl::fromLegacySlug('privacy'));

        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key, array $params) => $key === 'page.show' ? '/legal/'.$params['slug'] : $path);
        $this->assertSame('/legal/privacy', PrivacyPolicyUrl::fromLegacySlug('privacy'));
    }
}
