<?php

namespace Tests\Feature\Api;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as RouteFacade;
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

    /**
     * Sitemap 진행상황 채널이 등록되어 있고, core.settings.read 권한 보유 시 인증을 허용한다.
     *
     * broadcasting/auth HTTP 엔드포인트는 이 하네스에서 서명 응답을 낼 수 없어(권한 유무와 무관하게 403)
     * 인증 여부를 구분하지 못하므로, 등록된 채널 콜백을 직접 호출해 인가 로직을 검증한다.
     */
    public function test_sitemap_channel_authorizes_user_with_settings_read(): void
    {
        $callback = $this->channelCallback('core.admin.seo.sitemap');

        $user = $this->userWithPermission('core.settings.read');

        $this->assertTrue((bool) $callback($user));
    }

    /**
     * Sitemap 진행상황 채널: core.settings.read 권한 미보유 시 거부.
     */
    public function test_sitemap_channel_rejects_user_without_permission(): void
    {
        $callback = $this->channelCallback('core.admin.seo.sitemap');

        $user = User::factory()->create();

        $this->assertFalse((bool) $callback($user));
    }

    /**
     * `broadcasting/auth` 로 끝나는 라우트는 게이트된 `api/broadcasting/auth` 하나뿐이어야 한다.
     *
     * 배경 (이슈 #128 F4):
     *   `bootstrap/app.php` 의 `withRouting(channels: ...)` 인자는 ApplicationBuilder 가
     *   `withBroadcasting()` 을 자동 호출하게 만들고, 그 결과 `booted` 에서 `Broadcast::routes()`
     *   가 **게이트 없는** `GET|POST /broadcasting/auth` 를 등록했다. 프론트는 이 경로를 쓰지
     *   않으므로(`WebSocketManager` 는 `/api/broadcasting/auth` 만 호출) 死라우트인 동시에,
     *   웹소켓 킬스위치(`broadcasting.default === 'null'`, 공개#50)를 통째로 우회하는 경로였다.
     *   production 에서 미인증 POST 가 200 을 받았다.
     *
     *   채널 정의(`routes/channels.php`)는 `App\Providers\BroadcastServiceProvider` 가
     *   `require` 로 계속 로드하므로 인가 콜백은 그대로 살아 있다 — 아래 채널 테스트가 그 증거다.
     */
    public function test_only_the_gated_api_broadcasting_auth_route_is_registered(): void
    {
        $found = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri === 'broadcasting/auth' || str_ends_with($uri, '/broadcasting/auth')) {
                $found[$uri] = $route->gatherMiddleware();
            }
        }

        // 모집단 가드 — 라우트가 하나도 안 잡히면 이 단언은 공허참이 된다.
        $this->assertArrayHasKey(
            'api/broadcasting/auth',
            $found,
            '게이트된 api/broadcasting/auth 라우트가 등록되어 있지 않습니다.'
        );

        $this->assertSame(
            ['api/broadcasting/auth'],
            array_keys($found),
            '게이트 없는 broadcasting/auth 라우트가 등록되어 있습니다 (킬스위치 우회로): '
            .implode(', ', array_keys($found))
        );

        $this->assertContains(
            'auth:sanctum',
            $found['api/broadcasting/auth'],
            'api/broadcasting/auth 에 auth:sanctum 이 붙어 있지 않습니다.'
        );
    }

    /**
     * 채널 정의(`routes/channels.php`)가 여전히 로드되어야 한다.
     *
     * `withRouting(channels: ...)` 인자를 제거했으므로 채널 등록은 전적으로
     * `BroadcastServiceProvider::boot()` 의 `require` 에 달려 있다. 이 로드가 사라지면
     * 예외 없이 **모든 private 채널 구독이 403** 이 된다 — 콜백이 없는 채널은 거부되기 때문.
     */
    public function test_channel_definitions_are_loaded_by_broadcast_service_provider(): void
    {
        foreach (['App.Models.User.{id}', 'core.admin.dashboard', 'core.admin.seo.sitemap'] as $channel) {
            $this->assertInstanceOf(
                \Closure::class,
                $this->channelCallback($channel),
                "채널 정의가 로드되지 않았습니다: {$channel}"
            );
        }
    }

    /**
     * 등록된 브로드캐스트 채널의 인가 콜백을 반환합니다.
     *
     * @param  string  $channel  채널명 (private- 접두 없이)
     * @return \Closure 인가 콜백 (첫 인자 = 사용자)
     */
    private function channelCallback(string $channel): \Closure
    {
        $broadcaster = app(Factory::class)->connection();

        $ref = new \ReflectionClass($broadcaster);
        while ($ref !== false && ! $ref->hasProperty('channels')) {
            $ref = $ref->getParentClass();
        }
        $this->assertNotFalse($ref, 'broadcaster 에 channels 프로퍼티가 없음');

        $prop = $ref->getProperty('channels');
        $prop->setAccessible(true);
        $channels = $prop->getValue($broadcaster);

        $this->assertArrayHasKey($channel, $channels, "채널이 등록되지 않음: {$channel}");

        return $channels[$channel];
    }

    /**
     * 지정한 관리자 권한을 가진 사용자를 생성합니다.
     *
     * @param  string  $permission  권한 식별자
     * @return User 권한이 부여된 사용자
     */
    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();

        $perm = Permission::firstOrCreate(
            ['identifier' => $permission],
            [
                'name' => json_encode(['ko' => $permission, 'en' => $permission]),
                'description' => json_encode(['ko' => $permission, 'en' => $permission]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

        $role = Role::create([
            'identifier' => 'sitemap_channel_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$perm->id]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }
}
