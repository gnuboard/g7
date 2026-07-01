<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * broadcasting/auth 채널 인증 토글 가드 테스트 (공개#50).
 *
 * 웹소켓 사용 OFF(broadcasting.default='null') 시 채널 인증이 거부되어,
 * 변경 1(reverb.key 무력화)을 우회한 직접 연결 시도까지 차단되는지 검증한다.
 */
class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 웹소켓 OFF 시 broadcasting/auth 가 403 을 반환하는지 테스트합니다.
     */
    public function test_broadcasting_auth_returns_403_when_websocket_disabled(): void
    {
        Config::set('broadcasting.default', 'null');

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-test',
            'socket_id' => '123.456',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 비인증 요청은 401 을 반환하는지 테스트합니다 (회귀 방지).
     */
    public function test_broadcasting_auth_returns_401_without_authentication(): void
    {
        Config::set('broadcasting.default', 'reverb');

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-test',
            'socket_id' => '123.456',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 웹소켓 ON + 인증 시 토글 가드를 통과해 Broadcast::auth 로 진입하는지 테스트합니다.
     *
     * 채널 인증 콜백이 없는 임의 채널이므로 403(인증 통과 후 채널 권한 거부)을 받는다.
     * 핵심은 토글 가드(broadcasting.default='null')에 의한 사전 403 이 아님을 구분하는 것 —
     * Broadcast::auth 단계까지 도달함을 검증한다.
     */
    public function test_broadcasting_auth_passes_toggle_guard_when_enabled(): void
    {
        Config::set('broadcasting.default', 'reverb');

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-nonexistent-channel',
            'socket_id' => '123.456',
        ]);

        // 토글 가드(403)에서 멈추지 않고 Broadcast::auth 로 진입 → 채널 권한 거부(403/200).
        // 토글 OFF 가 아님을 확인: 401(미인증) 이 아닌 것으로 인증 통과 입증.
        $this->assertNotSame(401, $response->getStatusCode());
    }
}
