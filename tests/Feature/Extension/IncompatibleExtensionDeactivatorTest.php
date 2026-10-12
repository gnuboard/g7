<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Extension\CoreVersionChecker;
use App\Extension\IncompatibleExtensionDeactivator;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Support\ConfigCacheHelper;
use App\Support\CoreVersionDiskReader;
use App\Support\RouteCacheHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 부팅 시 코어 버전 비호환 확장 자동 비활성화 서비스 테스트 (#145 W3·W4·W6).
 *
 * - 안전망(W3): 현재 코어 버전이 디스크 코드 버전보다 낮으면(옛 부모가 구운 config 캐시, 옛 env 를 문 상주 프로세스)
 *   아무것도 끄지 않고 config 캐시를 비운다. 다음 요청이 `.env` 로 부팅해 올바른 버전으로 다시 판정한다.
 * - 결과 처리(W4): 매니저가 실제로 끄지 못한 확장(의존 확장 경고)은 알림·「자동 해제」 로그에 넣지 않고, 그 회차는
 *   판정 캐시를 저장하지 않는다.
 * - 재생성 묶기(W6): 여러 건을 꺼도 config·라우트 캐시 재생성은 한 번이다.
 */
class IncompatibleExtensionDeactivatorTest extends TestCase
{
    private CacheInterface $cache;

    /**
     * 판정 캐시와 알림 캐시를 비우고 현재 코어 버전을 디스크 버전과 같게 맞춥니다.
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
     * 판정 캐시(현재 버전 키)와 알림 캐시를 지웁니다.
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
     * 요구 코어 버전만 답하는 확장 대역을 만듭니다.
     *
     * @param  string  $required  요구 코어 버전 제약
     * @return object 확장 대역
     */
    private function extension(string $required): object
    {
        return new class($required)
        {
            /**
             * 요구 코어 버전을 담은 확장 대역을 만듭니다.
             *
             * @param  string  $required  요구 코어 버전 제약
             */
            public function __construct(private string $required) {}

            /**
             * 요구 코어 버전을 반환합니다.
             *
             * @return string 버전 제약
             */
            public function getRequiredCoreVersion(): string
            {
                return $this->required;
            }
        };
    }

    /**
     * config 캐시가 있다고 답하는 서비스를 만듭니다 (실제 bootstrap/cache 파일을 건드리지 않기 위해).
     *
     * @return IncompatibleExtensionDeactivator 서비스
     */
    private function deactivatorWithCachedConfig(): IncompatibleExtensionDeactivator
    {
        return new class($this->cache, app(TemplateRepositoryInterface::class)) extends IncompatibleExtensionDeactivator
        {
            /**
             * config 캐시가 있다고 답합니다.
             *
             * @return bool 항상 true
             */
            protected function isConfigurationCached(): bool
            {
                return true;
            }
        };
    }

    /**
     * 현재 버전이 디스크 코드보다 낮으면 끄지 않고, config 캐시를 비우고, 경고를 남기고, 판정 캐시를 저장하지 않는다.
     *
     * @scenario case=stale_version
     *
     * @effects stale_version_skips_auto_deactivation_and_clears_config_cache
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_stale_current_version_skips_and_clears_config_cache(): void
    {
        config()->set('app.version', '7.0.0');
        Artisan::spy();
        Log::spy();

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getActiveModules')->andReturn(['sirsoft-board' => $this->extension('>=7.1.0')]);
        $manager->shouldNotReceive('deactivateModule');

        $result = $this->deactivatorWithCachedConfig()->deactivateIncompatibleExtensions($manager, 'modules');

        $this->assertTrue($result['skipped_stale']);
        $this->assertSame([], $result['deactivated']);
        Artisan::shouldHaveReceived('call')->with('config:clear')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === __('extensions.warnings.auto_deactivate_skipped_stale_version')
            && $context['current'] === '7.0.0'
            && $context['shipped'] === CoreVersionDiskReader::shippedVersion()
            && $context['config_cached'] === true)->once();
        $this->assertFalse($this->cache->has(CoreVersionChecker::getCacheKey('modules')), '다음 요청이 다시 판정해야 한다');
        $this->assertNull($this->cache->get('ext.compatibility_alerts'));
    }

    /**
     * 진짜 비호환은 안전망이 막지 않는다 — 끄고, 알림을 저장하고, 판정 캐시를 저장한다.
     *
     * @scenario case=truly_incompatible
     *
     * @effects truly_incompatible_extension_is_deactivated_with_alert
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_truly_incompatible_extension_is_deactivated(): void
    {
        Log::spy();

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('getActivePlugins')->andReturn([
            'vendor-new' => $this->extension('>=99.0.0'),
            'vendor-ok' => $this->extension('>=7.0.0'),
        ]);
        $manager->shouldReceive('deactivatePlugin')
            ->once()
            ->with('vendor-new', false, DeactivationReason::IncompatibleCore->value, '>=99.0.0')
            ->andReturn(['success' => true, 'layouts_deleted' => 0]);

        $result = app(IncompatibleExtensionDeactivator::class)->deactivateIncompatibleExtensions($manager, 'plugins');

        $this->assertFalse($result['skipped_stale']);
        $this->assertSame([['identifier' => 'vendor-new', 'required' => '>=99.0.0']], $result['deactivated']);
        $alerts = $this->cache->get('ext.compatibility_alerts');
        $this->assertSame('vendor-new', $alerts['plugins']['deactivated'][0]['identifier'] ?? null);
        $this->assertTrue($this->cache->has(CoreVersionChecker::getCacheKey('plugins')));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === __('extensions.warnings.auto_deactivated', ['type' => 'plugins', 'identifier' => 'vendor-new'])
            && ! str_contains($message, ':identifier'))->once();
    }

    /**
     * 의존 확장 경고로 꺼지지 않은 확장은 알림·자동 해제 로그에 넣지 않고 차단 경고만 남기며 판정 캐시를 저장하지 않는다.
     *
     * @scenario case=blocked_by_dependents
     *
     * @effects blocked_deactivation_is_not_reported_as_deactivated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_blocked_deactivation_is_not_reported(): void
    {
        Log::spy();

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getActiveModules')->andReturn(['sirsoft-page' => $this->extension('>=99.0.0')]);
        $manager->shouldReceive('deactivateModule')->once()->andReturn([
            'success' => false,
            'warning' => true,
            'dependent_templates' => [['identifier' => 'sirsoft-basic', 'name' => 'Basic', 'type' => 'user']],
            'dependent_modules' => [],
            'dependent_plugins' => [],
            'layouts_deleted' => 0,
        ]);

        $result = app(IncompatibleExtensionDeactivator::class)->deactivateIncompatibleExtensions($manager, 'modules');

        $this->assertSame([], $result['deactivated']);
        $this->assertSame('sirsoft-page', $result['blocked'][0]['identifier'] ?? null);
        $this->assertNull($this->cache->get('ext.compatibility_alerts'));
        $this->assertFalse($this->cache->has(CoreVersionChecker::getCacheKey('modules')));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === __('extensions.warnings.auto_deactivate_blocked')
            && $context['identifier'] === 'sirsoft-page'
            && $context['dependents'] === ['sirsoft-basic'])->once();
        Log::shouldNotHaveReceived('warning', [__('extensions.warnings.auto_deactivated', ['type' => 'modules', 'identifier' => 'sirsoft-page']), Mockery::any()]);
    }

    /**
     * 여러 건을 꺼도 config·라우트 캐시 재생성은 루프가 끝난 뒤 한 번만 일어난다.
     *
     * @scenario case=multiple_deactivations
     *
     * @effects cache_rebuild_runs_once_per_deactivation_batch
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_multiple_deactivations_rebuild_caches_once(): void
    {
        Artisan::spy();

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getActiveModules')->andReturn([
            'a' => $this->extension('>=99.0.0'),
            'b' => $this->extension('>=99.0.0'),
            'c' => $this->extension('>=99.0.0'),
        ]);
        // 실제 매니저처럼 1건마다 라우트 캐시를, 리스너처럼 config 캐시를 다시 만들려 한다.
        $manager->shouldReceive('deactivateModule')->times(3)->andReturnUsing(function () {
            RouteCacheHelper::rebuild();
            ConfigCacheHelper::rebuild();

            return ['success' => true, 'layouts_deleted' => 0];
        });

        $result = app(IncompatibleExtensionDeactivator::class)->deactivateIncompatibleExtensions($manager, 'modules');

        $this->assertCount(3, $result['deactivated']);
        Artisan::shouldHaveReceived('call')->with('config:clear')->once();
        Artisan::shouldHaveReceived('call')->with('route:clear')->once();
    }

    /**
     * 템플릿도 같은 규칙을 따른다 — 꺼지면 알림, 꺼지지 않으면 차단 경고와 판정 캐시 미저장.
     *
     * @scenario case=templates
     *
     * @effects truly_incompatible_extension_is_deactivated_with_alert, blocked_deactivation_is_not_reported_as_deactivated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_templates_follow_same_rules(): void
    {
        $repository = Mockery::mock(TemplateRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->andReturn((new Template)->forceFill(['status' => 'active']));

        $templateManager = Mockery::mock(TemplateManager::class);
        $templateManager->shouldReceive('getAllTemplates')->andReturn([
            'vendor-admin' => ['g7_version' => '>=99.0.0'],
            'vendor-user' => ['g7_version' => '>=99.0.0'],
        ]);
        $templateManager->shouldReceive('deactivateTemplate')
            ->with('vendor-admin', DeactivationReason::IncompatibleCore->value, '>=99.0.0', Mockery::any())
            ->andReturn(true);
        $templateManager->shouldReceive('deactivateTemplate')
            ->with('vendor-user', DeactivationReason::IncompatibleCore->value, '>=99.0.0', Mockery::any())
            ->andReturn(false);

        $result = (new IncompatibleExtensionDeactivator($this->cache, $repository))->deactivateIncompatibleTemplates($templateManager);

        $this->assertSame([['identifier' => 'vendor-admin', 'required' => '>=99.0.0']], $result['deactivated']);
        $this->assertSame('vendor-user', $result['blocked'][0]['identifier'] ?? null);
        $this->assertSame('vendor-admin', $this->cache->get('ext.compatibility_alerts')['templates']['deactivated'][0]['identifier'] ?? null);
        $this->assertFalse($this->cache->has(CoreVersionChecker::getCacheKey('templates')));
    }

    /**
     * 비호환이 없으면 디스크 버전을 읽지 않고 판정 캐시만 저장한다(정상 사이트 비용 0).
     *
     * @scenario case=all_compatible
     *
     * @effects compatible_site_only_stores_version_check_cache
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_all_compatible_stores_cache_only(): void
    {
        config()->set('app.version', '7.0.0'); // 디스크보다 낮아도 비호환이 없으면 안전망이 돌지 않는다
        Artisan::spy();

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getActiveModules')->andReturn(['vendor-ok' => $this->extension('>=7.0.0')]);
        $manager->shouldNotReceive('deactivateModule');

        $result = $this->deactivatorWithCachedConfig()->deactivateIncompatibleExtensions($manager, 'modules');

        $this->assertFalse($result['skipped_stale']);
        $this->assertTrue($this->cache->has(CoreVersionChecker::getCacheKey('modules')));
        Artisan::shouldNotHaveReceived('call', ['config:clear']);
    }
}
