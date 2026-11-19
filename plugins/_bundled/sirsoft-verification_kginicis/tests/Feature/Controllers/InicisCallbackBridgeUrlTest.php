<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Controllers;

use App\Support\AssetUrl;
use Illuminate\Support\Facades\Config;
use Mockery;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackOutcome;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackResolver;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 콜백 → 브리지 복귀 주소는 요청이 아니라 **설정된 사이트 주소**를 따른다.
 *
 * 인증기관의 콜백은 사이트 바깥에서 도착한다 — 앞단 프록시가 접두사를 벗기거나 내부 호스트명으로
 * 들어오는 배치에서 `url()` 은 그 요청의 호스트를 실어, 인증을 마친 사용자가 사이트 밖으로 튕긴다.
 */
class InicisCallbackBridgeUrlTest extends PluginTestCase
{
    private const CALLBACK_URL = '/plugins/sirsoft-verification_kginicis/plugin/inicis/callback';

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
     * 인증 성공 콜백이 localhost 로 도착해도 팝업 브리지로 보내는 Location 이 설정된 사이트 주소로 시작하고 인증 토큰을 싣는지 확인한다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects pg_return_and_notify_addresses_follow_the_configured_site_url
     *
     * @return void 반환값 없음
     */
    public function test_bridge_redirect_follows_the_configured_site_url(): void
    {
        Config::set('app.url', 'https://example.com/g7');

        $resolver = Mockery::mock(InicisCallbackResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(InicisCallbackOutcome::success('challenge-1', 'token-1'));
        $this->app->instance(InicisCallbackResolver::class, $resolver);

        $response = $this->post('http://localhost'.self::CALLBACK_URL, ['resultCode' => '0000']);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(
            'https://example.com/g7/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge?',
            $location,
        );
        $this->assertStringContainsString('verification_token=token-1', $location);
    }
}
