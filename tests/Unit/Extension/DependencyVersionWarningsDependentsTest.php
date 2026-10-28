<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 역방향 의존 안내 — 의존 대상의 판이 내려가거나 꺼지면 깨지는 의존자를 알린다 (#152 P5·P12·D6)
 *
 * 회귀: 강제 업데이트·`install --force`·멈춘 업데이트 정리·강제 비활성화가 이 확장을 요구하던 활성 템플릿·모듈·플러그인을
 * 다시 보지 않아, 의존자가 조용히 깨졌다(템플릿이면 화면 전체 503). 작업은 막지 않고 안내(notice)만 남긴다 — 종료 코드 불변.
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class DependencyVersionWarningsDependentsTest extends TestCase
{
    use DatabaseTransactions;

    private const TARGET = 'test-dep152-target';

    private const TEMPLATE = 'test-dep152-dependent';

    /**
     * 의존 대상 모듈(1.0.0 활성)과, 그 모듈 >=1.0.0 을 요구하는 활성 사용자 템플릿을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 10:00:00');
        Cache::lock(ExtensionOperationLock::key('module', self::TARGET))->forceRelease();
        StuckUpdateRecovery::takeRecovered();
        LifecycleWarnings::current()->clear();

        Module::create([
            'identifier' => self::TARGET, 'vendor' => 'test', 'name' => ['ko' => '대상 모듈', 'en' => 'Target'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Template::factory()->create([
            'identifier' => self::TEMPLATE, 'vendor' => 'test', 'type' => 'user', 'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value, 'name' => ['ko' => '의존 템플릿', 'en' => 'Dependent Template'],
        ]);

        $manager = app(TemplateManagerInterface::class);
        $manager->ensureLoaded();
        $templates = new ReflectionProperty(TemplateManager::class, 'templates');
        $loaded = $templates->getValue($manager);
        $loaded[self::TEMPLATE] = [
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'name' => ['ko' => '의존 템플릿', 'en' => 'Dependent Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'dependencies' => ['modules' => [self::TARGET => '>=1.0.0']],
        ];
        $templates->setValue($manager, $loaded);
        TemplateManager::invalidateTemplateStatusCache();
    }

    /**
     * 시각 고정·잠금·안내·진행 기록을 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ExtensionUpdateJournal::discard('module', self::TARGET);
        Cache::lock(ExtensionOperationLock::key('module', self::TARGET))->forceRelease();
        LifecycleWarnings::current()->clear();
        TemplateManager::invalidateTemplateStatusCache();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 대상 판이 요구 아래로 내려가면 의존 템플릿을 사유·503 문장과 함께 돌려준다.
     *
     * @scenario surface=dependents_template
     *
     * @effects dependents_reported_when_target_drops
     *
     * @return void 반환값 없음
     */
    public function test_template_dependent_reported_when_target_version_drops(): void
    {
        app()->setLocale('ko');
        Module::where('identifier', self::TARGET)->update(['version' => '0.9.0']);

        $dependents = DependencyVersionWarnings::forDependents('module', self::TARGET);

        $this->assertCount(1, $dependents);
        $this->assertSame('dependent', $dependents[0]['direction']);
        $this->assertSame('template', $dependents[0]['dependent_type']);
        $this->assertSame(self::TEMPLATE, $dependents[0]['dependent_identifier']);
        $this->assertSame('version_mismatch', $dependents[0]['status']);
        $this->assertStringContainsString('버전 미달 0.9.0 → >=1.0.0', $dependents[0]['message']);
        $this->assertStringContainsString(__('extensions.warnings.dependent_template_503'), $dependents[0]['message']);
    }

    /**
     * 꺼진 대상은 꺼짐으로, 충족하면 빈 목록이다.
     *
     * @scenario surface=dependents_inactive_met
     *
     * @effects dependents_reported_when_target_drops
     *
     * @return void 반환값 없음
     */
    public function test_inactive_target_reported_and_met_is_empty(): void
    {
        $this->assertSame([], DependencyVersionWarnings::forDependents('module', self::TARGET), '충족하면 없음');

        Module::where('identifier', self::TARGET)->update(['status' => ExtensionStatus::Inactive->value]);
        $this->assertSame('inactive', DependencyVersionWarnings::forDependents('module', self::TARGET)[0]['status'] ?? null);
        $this->assertSame([], DependencyVersionWarnings::forDependents('module', self::TARGET, versionOnly: true), '판만 보면 꺼짐은 알리지 않는다');
    }

    /**
     * 활성 모듈 의존자도 찾는다.
     *
     * @scenario surface=dependents_module
     *
     * @effects dependents_reported_when_target_drops
     *
     * @return void 반환값 없음
     */
    public function test_module_dependent_reported(): void
    {
        Module::create([
            'identifier' => 'test-dep152-host', 'vendor' => 'test', 'name' => ['ko' => '호스트', 'en' => 'Host'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Module::where('identifier', self::TARGET)->update(['version' => '0.9.0']);

        $host = \Mockery::mock(ModuleInterface::class);
        $host->shouldReceive('getDependencies')->andReturn(['modules' => [self::TARGET => '^1.0']]);
        $manager = \Mockery::mock(ModuleManagerInterface::class);
        $manager->shouldReceive('getModule')->andReturnUsing(fn (string $id) => $id === 'test-dep152-host' ? $host : null);
        $this->app->instance(ModuleManagerInterface::class, $manager);

        $dependents = DependencyVersionWarnings::forDependents('module', self::TARGET);

        $this->assertSame(['template', 'module'], array_column($dependents, 'dependent_type'));
        $this->assertSame('test-dep152-host', $dependents[1]['dependent_identifier']);
        $this->assertStringNotContainsString(__('extensions.warnings.dependent_template_503'), $dependents[1]['message'], '모듈 의존자에는 503 문장이 없다');
    }

    /**
     * 플러그인이 내려가면 그 플러그인을 요구하는 활성 템플릿·플러그인 의존자를 찾는다.
     *
     * @scenario surface=dependents_plugin
     *
     * @effects dependents_reported_when_target_drops
     *
     * @return void 반환값 없음
     */
    public function test_plugin_target_reports_template_and_plugin_dependents(): void
    {
        app()->setLocale('ko');
        Plugin::create([
            'identifier' => 'test-dep152-ptarget', 'vendor' => 'test', 'name' => ['ko' => '대상 플러그인', 'en' => 'Target Plugin'],
            'version' => '0.9.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Plugin::create([
            'identifier' => 'test-dep152-phost', 'vendor' => 'test', 'name' => ['ko' => '호스트 플러그인', 'en' => 'Host Plugin'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);

        // 템플릿 의존자: 대상 플러그인 >=1.0.0 을 요구
        $manager = app(TemplateManagerInterface::class);
        $templates = new ReflectionProperty(TemplateManager::class, 'templates');
        $loaded = $templates->getValue($manager);
        $loaded[self::TEMPLATE]['dependencies']['plugins'] = ['test-dep152-ptarget' => '>=1.0.0'];
        $templates->setValue($manager, $loaded);

        // 플러그인 의존자: 대상 플러그인 ^1.0 을 요구
        $host = \Mockery::mock(PluginInterface::class);
        $host->shouldReceive('getDependencies')->andReturn(['plugins' => ['test-dep152-ptarget' => '^1.0']]);
        $plugins = \Mockery::mock(PluginManagerInterface::class);
        $plugins->shouldReceive('getPlugin')->andReturnUsing(fn (string $id) => $id === 'test-dep152-phost' ? $host : null);
        $this->app->instance(PluginManagerInterface::class, $plugins);

        $dependents = DependencyVersionWarnings::forDependents('plugin', 'test-dep152-ptarget');

        $this->assertSame(['template', 'plugin'], array_column($dependents, 'dependent_type'));
        $this->assertSame([self::TEMPLATE, 'test-dep152-phost'], array_column($dependents, 'dependent_identifier'));
        $this->assertSame(['plugin', 'plugin'], array_column($dependents, 'type'));
        $this->assertSame(['version_mismatch', 'version_mismatch'], array_column($dependents, 'status'));
        $this->assertStringContainsString('버전 미달 0.9.0 → ^1.0', $dependents[1]['message']);
        $this->assertStringContainsString(__('extensions.warnings.dependent_template_503'), $dependents[0]['message']);
        $this->assertStringNotContainsString(__('extensions.warnings.dependent_template_503'), $dependents[1]['message'], '플러그인 의존자에는 503 문장이 없다');
    }

    /**
     * 수집 중 예외는 삼키고 빈 목록 — 안내가 본 작업을 깨지 않는다.
     *
     * @scenario surface=dependents_exception
     *
     * @effects dependents_collection_never_throws
     *
     * @return void 반환값 없음
     */
    public function test_collection_exception_is_swallowed(): void
    {
        $repository = \Mockery::mock(TemplateRepositoryInterface::class);
        $repository->shouldReceive('findActiveByModuleDependency')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(TemplateRepositoryInterface::class, $repository);

        $this->assertSame([], DependencyVersionWarnings::forDependents('module', self::TARGET));
    }

    /**
     * 안내는 notice 수준으로 남아 실패로 세지 않는다 (CLI 종료 코드 0).
     *
     * @scenario surface=dependents_notice
     *
     * @effects dependents_notice_not_failure
     *
     * @return void 반환값 없음
     */
    public function test_notice_dependents_records_notice_level(): void
    {
        Module::where('identifier', self::TARGET)->update(['status' => ExtensionStatus::Inactive->value]);

        DependencyVersionWarnings::noticeDependents('module', self::TARGET);

        $items = LifecycleWarnings::current()->all();
        $this->assertCount(1, $items);
        $this->assertSame('dependents', $items[0]['step']);
        $this->assertSame(LifecycleWarnings::LEVEL_NOTICE, $items[0]['level']);
        $this->assertFalse(LifecycleWarnings::hasFailures($items));
    }

    /**
     * 멈춘 업데이트 정리가 판을 되돌리면 의존자를 안내하고, 판 전환이 끝났던 정리(꺼짐만)는 안내하지 않는다.
     *
     * @scenario surface=dependents_stuck_recovery
     *
     * @effects stuck_recovery_reverting_version_notices_dependents
     *
     * @return void 반환값 없음
     */
    public function test_stuck_update_recovery_reverting_version_notices_dependents(): void
    {
        $update = fn (array $attributes) => Module::where('identifier', self::TARGET)->update($attributes);
        $stale = fn () => (object) ['status' => ExtensionStatus::Updating->value, 'updated_at' => Carbon::now()->subSeconds(9999), 'version' => '1.0.0'];

        // 진행 기록 없음 — 꺼짐만, 안내 없음
        StuckUpdateRecovery::recover('module', self::TARGET, $stale(), $update);
        $this->assertSame([], LifecycleWarnings::current()->all());

        // 0.9.0 → 1.0.0 업데이트가 파일을 건드린 채 멈춤 — 판을 0.9.0 으로 되돌리므로 의존 템플릿(>=1.0.0)이 깨진다
        Module::where('identifier', self::TARGET)->update(['status' => ExtensionStatus::Updating->value]);
        ExtensionUpdateJournal::begin('module', self::TARGET, '0.9.0', '1.0.0', 'bundled')->markFilesTouched();
        StuckUpdateRecovery::recover('module', self::TARGET, $stale(), $update);

        $this->assertSame('0.9.0', Module::where('identifier', self::TARGET)->value('version'));
        $items = LifecycleWarnings::current()->all();
        $this->assertCount(1, $items);
        $this->assertSame(self::TEMPLATE, $items[0]['target']);
        $this->assertSame(LifecycleWarnings::LEVEL_NOTICE, $items[0]['level']);
    }

    /**
     * 완료 문구는 자기 의존 경고와 역방향 안내를 다른 문장으로 잇는다.
     *
     * @scenario surface=dependents_message
     *
     * @effects update_message_separates_dependents
     *
     * @return void 반환값 없음
     */
    public function test_append_to_message_separates_directions(): void
    {
        $message = DependencyVersionWarnings::appendToMessage('완료', [
            ['message' => 'A 부족'],
            ['message' => 'B 깨짐', 'direction' => 'dependent'],
        ]);

        $this->assertSame('완료 '.__('extensions.warnings.dependency_summary', ['list' => 'A 부족']).' B 깨짐', $message);
    }
}
