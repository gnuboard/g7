<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\CoreVersionChecker;
use App\Extension\IncompatibleExtensionDeactivator;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\CoreVersionDiskReader;
use Mockery;
use Tests\TestCase;

/**
 * 코어 비호환 자동 비활성화가 종류를 넘는 의존을 한 번의 부팅에서 풀어내는지 검증 (#153 C).
 *
 * 부팅은 모듈 → 플러그인 순으로 끄는데, 끄기는 의존하는 쪽부터여야 한다. 이커머스(모듈)와 결제 플러그인이 함께 비호환이면
 * 종전에는 이커머스가 켜진 결제 플러그인 때문에 막혀 다음 요청까지 켜져 있었고 알림도 부팅마다 쪼개졌다.
 * 의존 판정은 매니저(deactivateModule/Plugin)가 하므로, 매니저 대역은 「이 확장에 의존하는 활성 확장」을 상태로 들고 답한다.
 */
class IncompatibleExtensionCrossTypeRetryTest extends TestCase
{
    private CacheInterface $cache;

    /** @var array<string, bool> 노드 키 → 활성 여부 */
    private array $active = [];

    /** @var array<string, array<int, string>> 노드 키 → 그 노드에 의존하는 노드 키 */
    private array $dependents = [];

    /** @var array<int, string> 실제로 꺼진 순서 */
    private array $offOrder = [];

    /**
     * 판정·알림 캐시를 비우고 현재 코어 버전을 디스크 버전과 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = app(CacheInterface::class);
        config()->set('app.version', CoreVersionDiskReader::shippedVersion());
        $this->forgetCaches();
    }

    /**
     * 테스트가 남긴 판정·알림 캐시를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->forgetCaches();

        parent::tearDown();
    }

    /**
     * 판정 캐시와 알림 캐시를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function forgetCaches(): void
    {
        foreach (['modules', 'plugins', 'templates'] as $type) {
            $this->cache->forget(CoreVersionChecker::getCacheKey($type));
        }
        $this->cache->forget('ext.compatibility_alerts');
    }

    /**
     * 비호환 확장 대역 — 요구 코어 버전만 답한다.
     *
     * @return object 확장 대역
     */
    private function incompatible(): object
    {
        return new class
        {
            /**
             * 요구 코어 버전을 반환합니다(늘 비호환).
             *
             * @return string 버전 제약
             */
            public function getRequiredCoreVersion(): string
            {
                return '>=99.0.0';
            }
        };
    }

    /**
     * 노드 하나를 끈다 — 활성 의존자가 남아 있으면 매니저처럼 의존 확장 경고로 거절한다.
     *
     * @param  string  $key  노드 키 (`module:…`·`plugin:…`)
     * @return array<string, mixed> 매니저 응답 모양
     */
    private function deactivate(string $key): array
    {
        $activeDependents = array_values(array_filter($this->dependents[$key] ?? [], fn (string $d): bool => $this->active[$d] ?? false));
        if ($activeDependents !== []) {
            return [
                'success' => false,
                'warning' => true,
                'dependent_templates' => [],
                'dependent_modules' => array_map(fn (string $d): array => ['identifier' => substr($d, 7)], array_filter($activeDependents, fn ($d) => str_starts_with($d, 'module:'))),
                'dependent_plugins' => array_map(fn (string $d): array => ['identifier' => substr($d, 7)], array_filter($activeDependents, fn ($d) => str_starts_with($d, 'plugin:'))),
            ];
        }
        $this->active[$key] = false;
        $this->offOrder[] = $key;

        return ['success' => true, 'layouts_deleted' => 0];
    }

    /**
     * 부팅과 같은 순서(모듈 → 플러그인 → 재시도)로 자동 비활성화를 돌린다.
     *
     * @param  array<int, string>  $modules  비호환 활성 모듈
     * @param  array<int, string>  $plugins  비호환 활성 플러그인
     * @return array<string, mixed> 재시도 결과
     */
    private function boot(array $modules, array $plugins): array
    {
        foreach ([...array_map(fn ($m) => "module:{$m}", $modules), ...array_map(fn ($p) => "plugin:{$p}", $plugins)] as $key) {
            $this->active[$key] = true;
        }

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn(array_fill_keys($modules, $this->incompatible()));
        $moduleManager->shouldReceive('deactivateModule')->andReturnUsing(fn (string $id): array => $this->deactivate("module:{$id}"));
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn(array_fill_keys($plugins, $this->incompatible()));
        $pluginManager->shouldReceive('deactivatePlugin')->andReturnUsing(fn (string $id): array => $this->deactivate("plugin:{$id}"));

        $deactivator = new IncompatibleExtensionDeactivator($this->cache, app(TemplateRepositoryInterface::class));
        $deactivator->deactivateIncompatibleExtensions($moduleManager, 'modules');
        $deactivator->deactivateIncompatibleExtensions($pluginManager, 'plugins');

        return $deactivator->retryBlocked();
    }

    /**
     * 이커머스(모듈) ← 결제 플러그인(의존)이 함께 비호환이면 한 번의 부팅에서 둘 다 꺼지고 알림 묶음은 하나다.
     *
     * @scenario surface=auto_deactivate, edge=module_to_plugin
     *
     * @effects auto_deactivate_turns_off_module_after_dependent_plugin_in_one_boot
     *
     * @return void 반환값 없음
     */
    public function test_module_is_deactivated_after_dependent_plugin_in_one_boot(): void
    {
        $this->dependents = ['module:shop' => ['plugin:pay']];

        $retry = $this->boot(['shop'], ['pay']);

        $this->assertSame(['plugin:pay', 'module:shop'], $this->offOrder);
        $this->assertSame([], $retry['blocked']);
        $alerts = $this->cache->get('ext.compatibility_alerts');
        $this->assertSame(['shop'], array_column($alerts['modules']['deactivated'], 'identifier'));
        $this->assertSame(['pay'], array_column($alerts['plugins']['deactivated'], 'identifier'));
        $this->assertTrue($this->cache->has(CoreVersionChecker::getCacheKey('modules')), '다 껐으니 다음 요청은 다시 판정하지 않는다');
    }

    /**
     * 3단 사슬(플러그인 koreaexim → 모듈 ecommerce → 플러그인 marketing)도 한 번의 부팅에서 의존하는 쪽부터 모두 꺼진다.
     *
     * @scenario surface=auto_deactivate, edge=chain
     *
     * @effects auto_deactivate_resolves_cross_type_chain_in_one_boot
     *
     * @return void 반환값 없음
     */
    public function test_cross_type_chain_is_resolved_in_one_boot(): void
    {
        $this->dependents = [
            'module:ecommerce' => ['plugin:koreaexim'],
            'plugin:marketing' => ['module:ecommerce'],
        ];

        $retry = $this->boot(['ecommerce'], ['koreaexim', 'marketing']);

        $this->assertSame(['plugin:koreaexim', 'module:ecommerce', 'plugin:marketing'], $this->offOrder);
        $this->assertSame([], $retry['blocked']);
        $this->assertSame(['module:ecommerce' => false, 'plugin:koreaexim' => false, 'plugin:marketing' => false], $this->active);
    }

    /**
     * 부팅은 템플릿 판정 뒤에 막힌 후보를 한 번 다시 끈다 — 업데이트 진행 중에는 판정과 같이 건너뛴다.
     *
     * CoreServiceProvider::boot 는 앱 부팅 자체라 소스 배선을 대조한다.
     *
     * @scenario surface=auto_deactivate, edge=module_to_plugin
     *
     * @effects boot_retries_blocked_incompatible_extensions_after_templates
     *
     * @return void 반환값 없음
     */
    public function test_boot_retries_blocked_candidates_after_type_passes(): void
    {
        $source = (string) file_get_contents(app_path('Providers/CoreServiceProvider.php'));

        $templates = strpos($source, '$this->validateAndDeactivateIncompatibleTemplates($templateManager);');
        $retry = strpos($source, '$this->retryBlockedIncompatibleExtensions();');
        $plugins = strpos($source, "\$this->validateAndDeactivateIncompatibleExtensions(\$pluginManager, 'plugins');");
        $this->assertNotFalse($retry);
        $this->assertGreaterThan($plugins, $retry);
        $this->assertGreaterThan($templates, $retry);

        $method = substr($source, (int) strpos($source, 'protected function retryBlockedIncompatibleExtensions(): void'), 400);
        $this->assertStringContainsString('if (self::isCoreUpdateInProgress()) {', $method);
        $this->assertStringContainsString('->retryBlocked();', $method);
    }
}
