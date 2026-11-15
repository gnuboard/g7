<?php

namespace Tests\Unit\Support;

use App\Support\CacheSelfHeal;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 설정·라우트 캐시 파일이 없을 때의 자기 복구 조건 (7.1.6).
 *
 * 운영 중인 사이트의 웹 요청에서만, 응답이 나간 뒤에 다시 만든다. 콘솔(일부러 지운 것과 싸우지 않게)·설치 전·
 * 개발 환경(캐시 없이 돌리는 것이 의도)·스위치 끔·root 프로세스에서는 하지 않는다. 재생성은 일회용 앱을 부팅해
 * DB 연결을 쓰므로 한 번 시도하면 5분은 다시 하지 않는다.
 *
 * 실제 캐시는 굽지 않는다 — 재생성 함수를 호출만 세는 대역으로 바꾼다(시험 중 캐시 재굽기 금지).
 *
 * @scenario-manifest tests/scenarios/cache-missing-self-heal.yaml
 */
class ConfigRouteCacheSelfHealTest extends TestCase
{
    private string $base;

    /** @var array<string, int> */
    private array $rebuilt = ['config' => 0, 'route' => 0];

    /** @var list<callable> */
    private array $terminating = [];

    /**
     * 임시 앱 루트와 호출만 세는 재생성 대역을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/config-route-heal-'.uniqid());
        File::ensureDirectoryExists($this->base.'/bootstrap/cache');
        File::ensureDirectoryExists($this->base.'/storage/app');
        file_put_contents($this->base.'/.env', "INSTALLER_COMPLETED=true\n");

        CacheSelfHeal::resetForTesting();
        CacheSelfHeal::useMarkerDirectoryForTesting($this->base.'/bootstrap/cache');
        CacheSelfHeal::fakeRootProcessForTesting(false);
        CacheSelfHeal::useRebuildersForTesting([
            'config' => function (): void {
                $this->rebuilt['config']++;
            },
            'route' => function (): void {
                $this->rebuilt['route']++;
            },
        ]);
        config(['app.cache_self_heal' => true, 'app.debug' => false]);
    }

    /**
     * 내부 상태와 임시 디렉토리를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        CacheSelfHeal::resetForTesting();
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    /**
     * 운영 웹 요청에서 두 캐시가 모두 없으면 응답 뒤에 각각 한 번 다시 만든다.
     *
     * @scenario case=config_route_missing
     *
     * @effects missing_config_and_route_caches_rebuilt_after_response
     *
     * @return void 반환값 없음
     */
    public function test_production_web_request_rebuilds_both_after_response(): void
    {
        $scheduled = CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app());

        $this->assertSame(['config', 'route'], $scheduled);
        // 응답 전에는 아무것도 하지 않는다
        $this->assertSame(['config' => 0, 'route' => 0], $this->rebuilt);

        $this->terminate();
        $this->assertSame(['config' => 1, 'route' => 1], $this->rebuilt);
    }

    /**
     * 있는 캐시는 건드리지 않고 없는 것만 만든다.
     *
     * @scenario case=config_route_present
     *
     * @effects present_config_or_route_cache_is_left_alone
     *
     * @return void 반환값 없음
     */
    public function test_only_the_missing_cache_is_rebuilt(): void
    {
        file_put_contents($this->base.'/bootstrap/cache/config.php', '<?php return [];');

        $scheduled = CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app());
        $this->terminate();

        $this->assertSame(['route'], $scheduled);
        $this->assertSame(['config' => 0, 'route' => 1], $this->rebuilt);
    }

    /**
     * 간격(5분) 안의 다음 요청은 다시 예약하지 않고, 간격이 지나면 다시 한다.
     *
     * @scenario case=config_route_regeneration_keeps_failing
     *
     * @effects self_heal_backs_off_after_failed_attempt
     *
     * @return void 반환값 없음
     */
    public function test_next_request_within_backoff_does_nothing(): void
    {
        CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app());
        $this->terminate();

        // 다음 요청 (요청 단위 기억은 새 프로세스에서 비어 있다)
        $this->nextRequest();
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app()));
        $this->terminate();
        $this->assertSame(['config' => 1, 'route' => 1], $this->rebuilt);

        // 간격이 지나면 다시 시도한다
        foreach (['config-cache', 'route-cache'] as $name) {
            touch(CacheSelfHeal::markerPath($name), time() - CacheSelfHeal::CONFIG_ROUTE_BACKOFF_SECONDS - 5);
        }
        $this->nextRequest();
        $this->assertSame(['config', 'route'], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app()));
    }

    /**
     * 조건이 하나라도 어긋나면 예약하지 않는다.
     *
     * @scenario case=config_route_not_production_web
     *
     * @effects config_route_self_heal_only_in_production_web
     *
     * @return void 반환값 없음
     */
    public function test_skips_outside_production_web_request(): void
    {
        // 콘솔
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app(console: true)));
        // 개발 환경
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app(production: false)));
        // 디버그 켬
        config(['app.debug' => true]);
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app()));
        config(['app.debug' => false]);
        // 설치 전
        file_put_contents($this->base.'/.env', "INSTALLER_COMPLETED=false\n");
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app()));

        $this->terminate();
        $this->assertSame(['config' => 0, 'route' => 0], $this->rebuilt);
        $this->assertSame([], glob($this->base.'/bootstrap/cache/*.retry') ?: []);
    }

    /**
     * 스위치를 끄거나 root 프로세스면 예약하지 않는다.
     *
     * @scenario case=config_route_switched_off
     *
     * @effects self_heal_skipped_for_root_and_when_switched_off
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_switched_off_or_root(): void
    {
        config(['app.cache_self_heal' => false]);
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app()));

        config(['app.cache_self_heal' => true]);
        CacheSelfHeal::fakeRootProcessForTesting(true);
        $this->assertSame([], CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app()));

        $this->terminate();
        $this->assertSame(['config' => 0, 'route' => 0], $this->rebuilt);
    }

    /**
     * 재생성이 실패해도 요청을 깨뜨리지 않는다(응답은 이미 나갔다).
     *
     * @scenario case=config_route_rebuild_throws
     *
     * @effects rebuild_failure_does_not_break_request
     *
     * @return void 반환값 없음
     */
    public function test_rebuild_failure_is_swallowed(): void
    {
        CacheSelfHeal::useRebuildersForTesting([
            'config' => static function (): void {
                throw new \RuntimeException('too many connections');
            },
            'route' => function (): void {
                $this->rebuilt['route']++;
            },
        ]);

        CacheSelfHeal::scheduleMissingConfigAndRouteCaches($this->app());
        $this->terminate();

        $this->assertSame(1, $this->rebuilt['route']);
    }

    /**
     * 설정 키와 `.env` 예시에 끄는 스위치가 있다(기본 켬).
     *
     * @scenario case=config_route_switch_declared
     *
     * @effects self_heal_switch_is_declared
     *
     * @return void 반환값 없음
     */
    public function test_switch_is_declared_with_default_on(): void
    {
        $config = (string) file_get_contents(base_path('config/app.php'));
        $this->assertStringContainsString("'cache_self_heal' => (bool) env('G7_CACHE_SELF_HEAL', true)", $config);
        $this->assertStringContainsString('# G7_CACHE_SELF_HEAL=false', (string) file_get_contents(base_path('.env.example')));
    }

    /**
     * 임시 앱 루트를 가리키는 앱 대역을 만듭니다.
     *
     * @param  bool  $console  콘솔 실행으로 볼지
     * @param  bool  $production  운영 환경으로 볼지
     * @return Application 앱 대역
     */
    private function app(bool $console = false, bool $production = true): Application
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn($console);
        $app->shouldReceive('environment')->with('production')->andReturn($production);
        $app->shouldReceive('basePath')->andReturn($this->base);
        $app->shouldReceive('getCachedConfigPath')->andReturn($this->base.'/bootstrap/cache/config.php');
        $app->shouldReceive('getCachedRoutesPath')->andReturn($this->base.'/bootstrap/cache/routes-v7.php');
        $app->shouldReceive('terminating')->andReturnUsing(function (callable $callback) use ($app) {
            $this->terminating[] = $callback;

            return $app;
        });

        return $app;
    }

    /**
     * 응답 뒤 콜백을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function terminate(): void
    {
        foreach ($this->terminating as $callback) {
            $callback();
        }
        $this->terminating = [];
    }

    /**
     * 다음 요청으로 넘어갑니다 — 요청 단위 기억만 비운다(표식 파일과 대역은 그대로).
     *
     * @return void 반환값 없음
     */
    private function nextRequest(): void
    {
        $property = new \ReflectionProperty(CacheSelfHeal::class, 'scheduled');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
