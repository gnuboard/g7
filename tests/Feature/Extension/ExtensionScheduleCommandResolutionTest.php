<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\ScheduleCommandCacheManager;
use App\Providers\CoreServiceProvider;
use Illuminate\Console\Application as ArtisanApplication;
use Laravel\Sanctum\Console\Commands\PruneExpired;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Fixtures\ScheduleCommandExtension\FixtureExtension;
use Tests\Fixtures\ScheduleCommandExtension\src\Console\Commands\DeclaredCommand;
use Tests\Fixtures\ScheduleCommandExtension\src\Console\Commands\UndeclaredCommand;
use Tests\TestCase;

/**
 * 확장이 선언한 예약 커맨드가 **실행 방식과 무관하게** 해석되는지 잠근다.
 *
 * 무CLI 호스팅의 웹 크론은 웹 요청 안에서 `Artisan::call()` 로 예약 작업을 실행하는데,
 * 확장은 Laravel 관례대로 `runningInConsole()` 안에서만 커맨드를 등록한다. 그 조건이 거짓이라
 * 커맨드가 등록되지 않아 예약 작업 14개가 매번 「그런 커맨드가 없다」로 실패했다
 * (G8 실측 2026-09-02 · 계획서 §9.11.3 F4 · D42).
 *
 * 이 검사가 잠그는 성질은 둘이다.
 *   ① 확장이 **선언한 것만** 등록된다 — 소스에 있다는 이유로 등록하지 않는다.
 *   ② 선언한 것은 콘솔이 뜨는 순간 **반드시** 해석된다.
 *
 * 커버 축: extension_schedule_command_registration
 */
class ExtensionScheduleCommandResolutionTest extends TestCase
{
    /**
     * 케이스가 만든 Mockery 목을 닫아 기대 검증을 마친 뒤 부모 정리를 돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 선언 목록으로 매니저를 꾸며 매핑을 해석한다.
     *
     * @param  array<int, array<string, mixed>>  $schedules  확장이 선언할 예약 목록
     * @return array{commands: array<string, class-string>, unresolved: array<string, string>} 해석 결과
     */
    private function resolveWith(array $schedules): array
    {
        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')
            ->andReturn(['fixture-schedule_ext' => new FixtureExtension($schedules)]);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        return (new ScheduleCommandCacheManager)->resolveLive($moduleManager, $pluginManager);
    }

    /**
     * 확장이 `getSchedules()` 로 선언한 커맨드만 매핑되고, 같은 확장 `src/` 에 있지만 선언하지 않은 커맨드는 매핑에 실리지 않는지 확인한다.
     *
     * @scenario schedule_declaration=declared
     *
     * @effects only_declared_schedule_commands_are_registered
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_only_declared_commands_are_mapped(): void
    {
        $map = $this->resolveWith([
            ['command' => 'fixture-ext:declared --scheduled', 'schedule' => 'daily'],
        ]);

        $this->assertSame(
            ['fixture-ext:declared' => DeclaredCommand::class],
            $map['commands'],
            '확장이 선언한 커맨드만 매핑되어야 한다.'
        );

        $this->assertNotContains(
            UndeclaredCommand::class,
            $map['commands'],
            '같은 확장 소스에 있더라도 선언하지 않은 커맨드는 등록 대상이 아니다 — '
            .'등록 범위가 「소스에 있는 것」이 되면 확장이 노출하겠다고 하지 않은 커맨드가 열린다.'
        );

        $this->assertSame([], $map['unresolved']);
    }

    /**
     * 예약을 하나도 선언하지 않은 확장은 소스에 커맨드가 있어도 매핑과 미해석 원장이 모두 빈 배열인지 확인한다.
     *
     * @scenario schedule_declaration=empty
     *
     * @effects only_declared_schedule_commands_are_registered
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_nothing_is_mapped_when_the_extension_declares_nothing(): void
    {
        $map = $this->resolveWith([]);

        $this->assertSame([], $map['commands']);
        $this->assertSame([], $map['unresolved']);
    }

    /**
     * 선언에 `command_class` 를 명시하면 이름 조회로 찾을 수 없는 커맨드 이름이어도 그 클래스로 바로 매핑되는지 확인한다.
     *
     * @scenario schedule_declaration=explicit_class
     *
     * @effects declared_command_class_skips_the_lookup
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_declared_command_class_skips_the_lookup(): void
    {
        // 이름은 조회로 찾을 수 없는 것을 쓴다 — 그래도 명시한 클래스로 매핑돼야 한다.
        $map = $this->resolveWith([
            [
                'command' => 'fixture-ext:renamed',
                'schedule' => 'daily',
                'command_class' => DeclaredCommand::class,
            ],
        ]);

        $this->assertSame(
            ['fixture-ext:renamed' => DeclaredCommand::class],
            $map['commands']
        );
    }

    /**
     * 소스에서 찾지 못한 선언이 매핑에서 빠지는 대신 미해석 원장에 소유 확장과 함께 기록되는지 확인한다.
     *
     * @scenario schedule_declaration=unresolvable
     *
     * @effects unresolvable_schedule_declaration_is_recorded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_unresolvable_declaration_is_recorded_not_swallowed(): void
    {
        $map = $this->resolveWith([
            ['command' => 'fixture-ext:missing', 'schedule' => 'daily'],
        ]);

        $this->assertSame([], $map['commands']);
        $this->assertSame(
            ['fixture-ext:missing' => 'module:fixture-schedule_ext'],
            $map['unresolved'],
            '찾지 못한 선언은 조용히 사라지지 않고 원장에 남아야 한다 — '
            .'지금 고치는 결함이 「조용히 안 도는 것」이다.'
        );
    }

    /**
     * 캐시 매핑에 실린 커맨드가 콘솔 애플리케이션 생성 시점(`Artisan::starting`)에 등록되어 커맨드 목록에서 보이는지 확인한다.
     *
     * @scenario schedule_declaration=declared, entry_point=web
     *
     * @effects declared_schedule_command_is_resolvable_in_web_context
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_mapped_commands_are_resolvable_once_artisan_boots(): void
    {
        $manager = Mockery::mock(ScheduleCommandCacheManager::class);
        $manager->shouldReceive('read')->andReturn([
            'commands' => ['fixture-ext:declared' => DeclaredCommand::class],
            'unresolved' => [],
        ]);

        $this->app->instance(ScheduleCommandCacheManager::class, $manager);

        // 콘솔 애플리케이션 생성이 곧 `Artisan::starting` 콜백 실행 지점이다.
        // 커널과 같은 방식으로 지연 로더를 붙인다 — `#[AsCommand]` 를 단 커맨드는
        // 즉시 등록이 아니라 지연 맵으로 들어가므로 로더 없이는 목록에 보이지 않는다.
        $artisan = (new ArtisanApplication($this->app, $this->app['events'], 'test'))
            ->setContainerCommandLoader();

        $this->assertArrayHasKey(
            'fixture-ext:declared',
            $artisan->all(),
            '매핑된 커맨드는 콘솔이 뜨는 순간 해석돼야 한다 — 웹 크론이 이 경로로 실행한다.'
        );
    }

    /**
     * 코어가 예약하는 벤더 커맨드(`sanctum:prune-expired`)를 코어 서비스 프로바이더가 등록 목록에 직접 싣는지 확인한다.
     *
     * @scenario schedule_declaration=core_vendor
     *
     * @effects core_registers_the_vendor_command_it_schedules
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_core_registers_the_vendor_command_it_schedules(): void
    {
        // `routes/console.php` 가 `sanctum:prune-expired` 를 예약하는데 그 커맨드는 벤더
        // 패키지가 `runningInConsole()` 안에서만 등록한다. 벤더 소스는 고칠 수 없으므로
        // 코어가 등록을 진다 — 이 축이 빠지면 무CLI 에서 그 예약만 계속 실패한다.
        //
        // 이 단언은 **코어가 목록에 싣는가**를 직접 잰다. 테스트 환경은 CLI 라
        // Sanctum 자신의 게이트가 열려 있어, 등록 결과(`$artisan->all()`)만 보면 코어 코드를
        // 지워도 통과한다 — 그 형태는 이 축을 재지 않는 공허 통과다.
        $manager = Mockery::mock(ScheduleCommandCacheManager::class);
        $manager->shouldReceive('read')->andReturn(['commands' => [], 'unresolved' => []]);

        $this->app->instance(ScheduleCommandCacheManager::class, $manager);

        $provider = new CoreServiceProvider($this->app);
        $method = (new ReflectionClass($provider))->getMethod('resolveExtensionScheduleCommandClasses');
        $method->setAccessible(true);

        $this->assertContains(
            PruneExpired::class,
            $method->invoke($provider),
            '코어가 예약한 벤더 커맨드는 코어가 등록 목록에 실어야 한다.'
        );
    }

    /**
     * 정적 확장 캐시를 굽는 지점은 하나여야 한다.
     *
     * 오토로드 파일을 다시 쓰는 경로는 확장 수명주기와 같은 자리라 정적 캐시도 함께 구워야
     * 하는데, 그 목록을 각자 들고 있으면 새 캐시가 추가될 때 한쪽만 갱신된다 — 실제로
     * 예약 커맨드 매핑을 도입할 때 그 상태가 됐고, 그 경로로 돈 사이트에서만 매핑이 비었다.
     *
     * 모집단은 소스에서 도출한다(목록을 손으로 들지 않는다).
     *
     * @scenario schedule_declaration=regeneration_path
     *
     * @effects only_declared_schedule_commands_are_registered
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_autoload_regeneration_paths_go_through_the_single_cache_owner(): void
    {
        $paths = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Console/Commands'), \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'generateAutoloadFile(')) {
                $paths[$file->getPathname()] = $source;
            }
        }

        $this->assertNotEmpty(
            $paths,
            '오토로드 재생성 경로를 하나도 찾지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다.'
        );

        foreach ($paths as $path => $source) {
            $this->assertStringContainsString(
                'regenerateExtensionStaticCaches(',
                $source,
                basename($path).' 가 정적 확장 캐시를 직접 열거하고 있다 — '
                .'목록을 아는 지점은 ExtensionManager::regenerateExtensionStaticCaches() 하나여야 한다.'
            );
        }
    }
}
