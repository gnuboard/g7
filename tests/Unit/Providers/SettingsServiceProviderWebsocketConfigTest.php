<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SettingsServiceProvider 웹소켓 설정 테스트
 *
 * applyWebsocketConfig()가 drivers.json의 websocket_enabled 값에 따라
 * 브로드캐스트 드라이버를 올바르게 제어하는지 검증합니다.
 *
 * 재현 시나리오 (이슈: 비밀번호 변경 시 Reverb 발송 시도):
 * - drivers.json: websocket_enabled=false (환경설정에서 OFF)
 * - .env: BROADCAST_CONNECTION=reverb, REVERB_HOST=localhost
 * - 기대 동작: broadcasting.default가 'null'로 강제되어 broadcast 경로가 차단되어야 함
 */
class SettingsServiceProviderWebsocketConfigTest extends TestCase
{
    /**
     * applyWebsocketConfig를 리플렉션으로 호출합니다.
     *
     * @param  array  $driverSettings  drivers 카테고리 설정 데이터
     */
    private function callApplyWebsocketConfig(array $driverSettings): void
    {
        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyWebsocketConfig');
        $method->invoke($provider, $driverSettings);
    }

    /**
     * 웹소켓 비활성화 시 broadcasting.default가 'null'로 강제되는지 테스트합니다.
     */
    public function test_broadcasting_default_forced_to_null_when_websocket_disabled(): void
    {
        // .env 초기 상태: BROADCAST_CONNECTION=reverb
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.options.host', 'localhost');

        // PO 설정: 웹소켓 OFF
        $this->callApplyWebsocketConfig([
            'websocket_enabled' => false,
            'websocket_host' => 'g7.dev',
            'websocket_port' => '443',
            'websocket_scheme' => 'https',
        ]);

        $this->assertSame(
            'null',
            config('broadcasting.default'),
            '웹소켓 비활성화 시 broadcasting.default는 "null"로 강제되어야 함 (HookManager::broadcast가 즉시 return)'
        );
    }

    /**
     * 웹소켓 활성화 시 broadcasting.default가 유지되는지 테스트합니다.
     */
    public function test_broadcasting_default_preserved_when_websocket_enabled(): void
    {
        Config::set('broadcasting.default', 'reverb');

        $this->callApplyWebsocketConfig([
            'websocket_enabled' => true,
            'websocket_app_id' => 'test-app',
            'websocket_app_key' => 'test-key',
            'websocket_app_secret' => 'test-secret',
            'websocket_host' => 'g7.dev',
            'websocket_port' => '443',
            'websocket_scheme' => 'https',
            'websocket_server_host' => '127.0.0.1',
            'websocket_server_port' => 8080,
            'websocket_server_scheme' => 'http',
        ]);

        $this->assertSame('reverb', config('broadcasting.default'));
    }

    /**
     * websocket_enabled 키 미존재 시 broadcasting.default가 'null'로 강제되는지 테스트합니다.
     */
    public function test_broadcasting_default_forced_to_null_when_websocket_key_missing(): void
    {
        Config::set('broadcasting.default', 'reverb');

        $this->callApplyWebsocketConfig([]);

        $this->assertSame('null', config('broadcasting.default'));
    }
}
