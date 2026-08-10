<?php

namespace Tests\Feature\DevTools;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Support\DevTools\DebugGate;
use Mockery;
use Tests\TestCase;

/**
 * DevTools 엔드포인트의 디버그 모드 게이트 회귀 테스트.
 *
 * 배경:
 *   게이트는 원래 `config('app.debug') || \App\Models\Setting::getValue('debug.mode', false)`
 *   였는데 `App\Models\Setting` 은 저장소에 존재하지 않는 유물이었다. `||` 단축평가 덕에
 *   `APP_DEBUG=true` 인 동안에는 두 번째 항이 평가되지 않아 드러나지 않았을 뿐이고,
 *   `APP_DEBUG=false` + settings JSON 의 `debug` 카테고리 미동기화가 겹치면
 *   **403 이어야 할 응답이 `Class "App\Models\Setting" not found` 500 이 됐다.**
 *
 * 검증 축:
 *   1. app.debug=false + debug.mode=true  → 200 (두 번째 항이 실제로 평가되어야 한다)
 *   2. app.debug=false + debug.mode=false → 403 (500 이 아니어야 한다)
 *   3. app.debug=true                     → 200 (환경설정 조회 없이 통과)
 */
class DevtoolsDebugGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * `debug.mode` 환경설정만 켜져 있어도 게이트를 통과해야 한다.
     *
     * 수정 전: HTTP 500 `Class "App\Models\Setting" not found`
     */
    public function test_dump_state_passes_gate_via_settings_debug_mode(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(true);

        $response = $this->postJson('/_boost/g7-debug/dump-state', ['test' => true]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    /**
     * 양쪽 모두 꺼져 있으면 403 이어야 한다 (500 이 아니라).
     */
    public function test_dump_state_returns_403_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        $response = $this->postJson('/_boost/g7-debug/dump-state', ['test' => true]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);

        // 번역 키가 해석되지 않으면 Laravel 은 키 문자열을 그대로 돌려준다 — 오류 없이
        // 사용자에게 `devtools.debug_disabled` 가 노출되므로 해석 여부를 단언한다.
        $this->assertNotSame(
            'devtools.debug_disabled',
            $response->json('message'),
            '번역 키가 해석되지 않았습니다 (lang/{ko,en}/devtools.php 누락).'
        );
    }

    /**
     * `browser-logs` 도 동일 게이트를 사용한다.
     */
    public function test_browser_logs_returns_403_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        $response = $this->postJson('/_boost/browser-logs', ['logs' => []]);

        $response->assertStatus(403);
    }

    /**
     * `.env` 의 `APP_DEBUG=true` 만으로도 통과한다 (환경설정 조회 불필요).
     */
    public function test_gate_passes_on_app_debug_without_settings_lookup(): void
    {
        config(['app.debug' => true]);

        $repository = Mockery::mock(ConfigRepositoryInterface::class);
        $repository->shouldNotReceive('get');
        $this->app->instance(ConfigRepositoryInterface::class, $repository);

        $this->assertTrue(DebugGate::isEnabled());
    }

    /**
     * `ConfigRepositoryInterface` 를 `debug.mode` 고정값으로 대체한다.
     *
     * @param  bool  $enabled  debug.mode 값
     * @return void
     */
    private function mockConfigRepositoryDebugMode(bool $enabled): void
    {
        $repository = Mockery::mock(ConfigRepositoryInterface::class);
        $repository->shouldReceive('get')
            ->with('debug.mode', false)
            ->andReturn($enabled);
        $repository->shouldReceive('get')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $default
        );

        $this->app->instance(ConfigRepositoryInterface::class, $repository);
    }
}
