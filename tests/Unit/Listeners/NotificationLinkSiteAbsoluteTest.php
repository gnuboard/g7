<?php

namespace Tests\Unit\Listeners;

use App\Listeners\CoreNotificationDataListener;
use App\Listeners\SeoNotificationDataListener;
use App\Models\User;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 알림·메일의 링크는 `AssetUrl::siteAbsolute()` 가 만든다 — `config('app.url').'/path'` 조립 금지.
 *
 * 문자열 이어 붙이기는 `APP_URL` 의 후행 슬래시를 그대로 실어 `https://host/g7//login` 을 만들고,
 * 요청이 있는 문맥(관리자가 보낸 재생성 요청)과 없는 문맥(큐)이 다른 규칙을 따르게 된다. 증상은
 * 오류가 아니라 메일 안의 깨진 링크뿐이다 (16차 G3 라운드 B-4).
 */
class NotificationLinkSiteAbsoluteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 후행 슬래시 + 하위 폴더 — 이어 붙이기가 가장 쉽게 깨지는 형태.
        Config::set('app.url', 'https://example.com/g7/');
        app()->instance('request', Request::create('http://localhost/api/x'));
    }

    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    private function default(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }

    private function user(): User
    {
        $user = new User(['name' => '홍길동', 'email' => 'hong@example.com']);
        $user->id = 1;

        return $user;
    }

    /**
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects notification_links_are_built_by_the_site_absolute_helper
     */
    public function test_core_listener_links_follow_the_configured_site_url_without_double_slash(): void
    {
        $listener = new CoreNotificationDataListener;

        $welcome = $listener->extractData($this->default(), 'welcome', [$this->user()]);
        $changed = $listener->extractData($this->default(), 'password_changed', [$this->user()]);
        $reset = $listener->extractData($this->default(), 'reset_password', [$this->user(), []]);

        $this->assertSame('https://example.com/g7/login', $welcome['data']['action_url']);
        $this->assertSame('https://example.com/g7/login', $changed['data']['action_url']);
        $this->assertSame('https://example.com/g7/reset-password', $reset['data']['action_url']);

        // 「사이트로 가기」 값도 같은 규칙 — `APP_URL` 원문(후행 슬래시)이 새지 않는다.
        foreach ([$welcome, $changed, $reset] as $out) {
            $this->assertSame('https://example.com/g7', $out['data']['site_url']);
        }
    }

    /**
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects notification_links_are_built_by_the_site_absolute_helper
     */
    public function test_seo_listener_links_follow_the_configured_site_url_without_double_slash(): void
    {
        $listener = new SeoNotificationDataListener;

        $done = $listener->extractData($this->default(), 'sitemap_regenerated', [['data' => ['url_count' => 1]], 42]);
        $failed = $listener->extractData($this->default(), 'sitemap_regenerate_failed', [['message' => 'x'], 42]);

        $this->assertSame('https://example.com/g7/admin/settings?tab=seo', $done['data']['action_url']);
        $this->assertSame('https://example.com/g7/admin/settings?tab=seo', $failed['data']['action_url']);
    }
}
