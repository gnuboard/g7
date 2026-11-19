<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 로그인·가입 버튼용 공개 제공자 목록 — 켜진 제공자만 표시 순서대로, 자격증명 없이.
 *
 * @since 1.0.0
 */
class PublicProvidersTest extends PluginTestCase
{
    /**
     * 켜진 제공자만 정렬 순서대로 나오고, 자격증명은 싣지 않으며 시작 주소는 이 사이트 경로다.
     *
     * @scenario providers=enabled|disabled, order=sort_order
     *
     * @effects only_enabled_in_order, no_credentials_in_list, start_url_is_site_path
     *
     * @return void 반환값 없음
     */
    public function test_lists_enabled_providers_in_order_without_credentials(): void
    {
        $this->enableKakao();
        SocialLoginProvider::query()->where('key', 'naver')->first()->forceFill([
            'enabled' => true, 'client_id' => 'naver-client', 'client_secret' => 'naver-secret', 'sort_order' => 0,
        ])->save();
        SocialLoginProvider::query()->where('key', 'kakao')->update(['sort_order' => 5]);
        app(ProviderRegistry::class)->flush();

        $response = $this->getJson('/api/plugins/sirsoft-social_login/providers')->assertOk();

        $this->assertSame(['naver', 'kakao'], array_column($response->json('data'), 'key'));
        $this->assertSame('kakao', $response->json('data.1.brand'));
        $this->assertSame('/plugins/sirsoft-social_login/oauth/kakao/start', $response->json('data.1.start_url'));
        $body = (string) $response->getContent();
        foreach (['kakao-client', 'kakao-secret', 'naver-client', 'naver-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * 켜진 제공자가 없으면 빈 목록이고, 모의 제공자는 게이트가 켜졌을 때만 원 제공자 모양으로 덧붙는다.
     *
     * @scenario providers=none|mock
     *
     * @effects empty_when_none_enabled, mock_listed_only_when_gate_on
     *
     * @return void 반환값 없음
     */
    public function test_empty_list_and_mock_providers(): void
    {
        (new ProviderSeeder)->run();
        app(ProviderRegistry::class)->flush();
        $this->getJson('/api/plugins/sirsoft-social_login/providers')->assertOk()->assertJsonPath('data', []);

        app(MockGate::class)->enable(5);
        app(ProviderRegistry::class)->flush();
        $mock = $this->getJson('/api/plugins/sirsoft-social_login/providers')->assertOk();
        app(MockGate::class)->disable();

        $this->assertSame(['mock_kakao', 'mock_naver', 'mock_google', 'mock_facebook', 'mock_oidc'], array_column($mock->json('data'), 'key'));
        $this->assertSame(['kakao', 'naver', 'google', 'facebook', 'custom'], array_column($mock->json('data'), 'brand'));
    }

    /**
     * 요청 제한은 라우트마다 따로 센다 — 화면마다 부르는 제공자 목록이 가입 인증번호 요청의 한도(분당 10)를 깎으면 안 된다.
     *
     * @scenario route=providers|signup_email, throttle=separate_buckets
     *
     * @effects throttle_buckets_not_shared
     *
     * @return void 반환값 없음
     */
    public function test_throttle_buckets_are_not_shared_between_routes(): void
    {
        $this->enableKakao();

        for ($i = 0; $i < 11; $i++) {
            $this->getJson('/api/plugins/sirsoft-social_login/providers')->assertOk();
        }

        $this->postJson('/api/plugins/sirsoft-social_login/signup/email/request', [])->assertStatus(422);
    }

    /**
     * 목록 라우트에 요청 제한이 걸려 있다.
     *
     * @scenario route=providers
     *
     * @effects providers_route_throttled
     *
     * @return void 반환값 없음
     */
    public function test_route_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('api.plugins.sirsoft-social_login.providers.index');

        $this->assertNotNull($route);
        $this->assertContains('throttle:120,1,sirsoft-social_login.providers', $route->gatherMiddleware());
    }
}
