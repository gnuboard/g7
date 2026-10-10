<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionMethod;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 확장 업데이트로 진입 파일이 바뀐 뒤 같은 프로세스에서 다시 읽으면 새 리스너 목록이 나오는지 검증
 *
 * 업데이트(관리자 화면 [업데이트]·artisan 공통)는 부팅 때 구버전 module.php·plugin.php 를 이미 읽어 둔 프로세스 안에서
 * 파일을 교체하고 끝에 훅 캐시를 다시 굽는다. 매니저가 목록을 다시 읽으며 이미 메모리에 있는 클래스를 `new` 하면
 * 구버전 `getHookListeners()` 가 나와 새 리스너가 `bootstrap/cache/hooks.php` 에서 빠졌다(2026-10-07 TOTP 업데이트 실측,
 * mem:hook-cache-misses-last-updated-extension). 실제 `bootstrap/cache` 는 건드리지 않는다 — 캐시 항목은 HookCacheManager 의
 * 수집 메서드를 직접 불러 확인한다.
 *
 * 시나리오 매니페스트: tests/scenarios/extension-update-hook-cache-fresh-listeners.yaml
 */
class ExtensionUpdateHookCacheFreshClassTest extends TestCase
{
    use DatabaseTransactions;
    use ProtectsExtensionDirectories;

    private const MODULE = 'testhk-freshmod';

    private const PLUGIN = 'testhk-freshplg';

    /**
     * 구버전 진입 파일을 가진 fixture 모듈·플러그인을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeModule([FreshClassListenerA::class]);
        $this->writePlugin([FreshClassListenerA::class]);

        $this->setUpExtensionProtection();
    }

    /**
     * fixture 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->tearDownExtensionProtection();

        foreach ([base_path('modules/'.self::MODULE), base_path('plugins/'.self::PLUGIN)] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * fixture 모듈의 module.json·module.php 를 쓴다.
     *
     * @param  array<int, class-string>  $listeners  getHookListeners() 가 돌려줄 리스너
     * @return void 반환값 없음
     */
    private function writeModule(array $listeners): void
    {
        $dir = base_path('modules/'.self::MODULE);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/module.json', json_encode([
            'identifier' => self::MODULE, 'vendor' => 'testhk', 'version' => '1.0.0',
            'name' => ['ko' => '훅 캐시 테스트 모듈', 'en' => 'Hook cache test module'],
            'description' => ['ko' => '', 'en' => ''], 'dependencies' => ['modules' => [], 'plugins' => []],
        ]));
        File::put($dir.'/module.php', $this->entrySource('Modules\\Testhk\\Freshmod', 'Module', 'App\\Extension\\AbstractModule', $listeners));
    }

    /**
     * fixture 플러그인의 plugin.json·plugin.php 를 쓴다.
     *
     * @param  array<int, class-string>  $listeners  getHookListeners() 가 돌려줄 리스너
     * @return void 반환값 없음
     */
    private function writePlugin(array $listeners): void
    {
        $dir = base_path('plugins/'.self::PLUGIN);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/plugin.json', json_encode([
            'identifier' => self::PLUGIN, 'vendor' => 'testhk', 'version' => '1.0.0',
            'name' => ['ko' => '훅 캐시 테스트 플러그인', 'en' => 'Hook cache test plugin'],
            'description' => ['ko' => '', 'en' => ''], 'dependencies' => ['modules' => [], 'plugins' => []],
        ]));
        File::put($dir.'/plugin.php', $this->entrySource('Plugins\\Testhk\\Freshplg', 'Plugin', 'App\\Extension\\AbstractPlugin', $listeners));
    }

    /**
     * 진입 클래스 소스를 만든다.
     *
     * @param  string  $namespace  네임스페이스
     * @param  string  $class  클래스명
     * @param  string  $parent  부모 클래스 FQCN
     * @param  array<int, class-string>  $listeners  리스너 목록
     * @return string PHP 소스
     */
    private function entrySource(string $namespace, string $class, string $parent, array $listeners): string
    {
        $list = implode(', ', array_map(fn ($l) => var_export($l, true), $listeners));

        return "<?php\n\nnamespace {$namespace};\n\nclass {$class} extends \\{$parent}\n{\n    public function getHookListeners(): array\n    {\n        return [{$list}];\n    }\n}\n";
    }

    /**
     * mock 저장소로 모듈 매니저를 만든다.
     *
     * @return ModuleManager 모듈 매니저
     */
    private function moduleManager(): ModuleManager
    {
        $repository = Mockery::mock(ModuleRepositoryInterface::class)->shouldIgnoreMissing();
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(collect());

        return new ModuleManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            moduleRepository: $repository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
    }

    /**
     * mock 저장소로 플러그인 매니저를 만든다.
     *
     * @return PluginManager 플러그인 매니저
     */
    private function pluginManager(): PluginManager
    {
        $repository = Mockery::mock(PluginRepositoryInterface::class)->shouldIgnoreMissing();
        $repository->shouldReceive('getAllKeyedByIdentifier')->andReturn(collect());

        return new PluginManager(
            extensionManager: Mockery::mock(ExtensionManager::class),
            pluginRepository: $repository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
            layoutRowSynchronizer: app(LayoutRowSynchronizer::class),
            layoutExtensionRepository: app(LayoutExtensionRepositoryInterface::class),
        );
    }

    /**
     * 훅 캐시 수집기가 이 확장 인스턴스에서 뽑는 리스너 FQCN 목록.
     *
     * @param  object  $extension  모듈·플러그인 인스턴스
     * @return array<int, string> 리스너 FQCN
     */
    private function cachedListeners(object $extension): array
    {
        $collector = new ReflectionMethod(HookCacheManager::class, 'collectExtensionListeners');
        $entries = $collector->invoke(new HookCacheManager, [$extension]);

        return array_column($entries[$extension->getIdentifier()] ?? [], 'listener');
    }

    /**
     * 모듈 진입 파일이 바뀐 뒤 다시 읽으면(다른 매니저 인스턴스여도) 새 리스너가 훅 캐시 항목에 들어간다.
     *
     * @scenario extension_type=module
     *
     * @effects updated_extension_new_listener_in_hook_cache
     *
     * @return void 반환값 없음
     */
    public function test_module_reload_after_file_change_yields_new_listeners(): void
    {
        $before = $this->moduleManager();
        $before->loadModules();
        $this->assertSame([FreshClassListenerA::class], $this->cachedListeners($before->getModule(self::MODULE)));

        $this->writeModule([FreshClassListenerA::class, FreshClassListenerB::class]);

        $after = $this->moduleManager();
        $after->loadModules();

        $this->assertSame([FreshClassListenerA::class, FreshClassListenerB::class], $this->cachedListeners($after->getModule(self::MODULE)));

        $before->loadModules();
        $this->assertContains(FreshClassListenerB::class, $this->cachedListeners($before->getModule(self::MODULE)), '같은 매니저가 다시 읽어도 새 정의');
    }

    /**
     * 플러그인 진입 파일이 바뀐 뒤 다시 읽으면 새 리스너가 훅 캐시 항목에 들어간다.
     *
     * @scenario extension_type=plugin
     *
     * @effects updated_extension_new_listener_in_hook_cache
     *
     * @return void 반환값 없음
     */
    public function test_plugin_reload_after_file_change_yields_new_listeners(): void
    {
        $before = $this->pluginManager();
        $before->loadPlugins();
        $this->assertSame([FreshClassListenerA::class], $this->cachedListeners($before->getPlugin(self::PLUGIN)));

        $this->writePlugin([FreshClassListenerA::class, FreshClassListenerB::class]);

        $after = $this->pluginManager();
        $after->loadPlugins();

        $this->assertSame([FreshClassListenerA::class, FreshClassListenerB::class], $this->cachedListeners($after->getPlugin(self::PLUGIN)));
    }

    /**
     * 파일이 그대로면 같은 클래스로 만든다(불필요한 eval 금지).
     *
     * @scenario extension_type=unchanged
     *
     * @effects updated_extension_new_listener_in_hook_cache
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_file_keeps_original_class(): void
    {
        $first = $this->moduleManager();
        $first->loadModules();
        $second = $this->moduleManager();
        $second->loadModules();

        $this->assertSame('Modules\\Testhk\\Freshmod\\Module', get_class($second->getModule(self::MODULE)));
    }
}

/**
 * 테스트용 리스너 A (구버전부터 있던 리스너).
 */
class FreshClassListenerA implements HookListenerInterface
{
    /**
     * 구독 훅.
     *
     * @return array<string, array<string, mixed>> 훅 맵
     */
    public static function getSubscribedHooks(): array
    {
        return ['testhk.fresh.a' => ['method' => 'handle']];
    }

    /**
     * 처리.
     *
     * @param  mixed  ...$args  인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}
}

/**
 * 테스트용 리스너 B (업데이트로 새로 생긴 리스너).
 */
class FreshClassListenerB implements HookListenerInterface
{
    /**
     * 구독 훅.
     *
     * @return array<string, array<string, mixed>> 훅 맵
     */
    public static function getSubscribedHooks(): array
    {
        return ['testhk.fresh.b' => ['method' => 'handle']];
    }

    /**
     * 처리.
     *
     * @param  mixed  ...$args  인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}
}
