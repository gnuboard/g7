<?php

namespace Tests\Feature\Extension;

use App\Extension\HookCacheManager;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Concerns\FakesGithubReleases;
use Tests\TestCase;

/**
 * 확장 생명주기 정규 훅 발화 계약 (공개 #138)
 *
 * 잠그는 명제: **CLI 진입점(Manager 직접 호출)과 어드민 UI 진입점(Service 경유) 모두에서,
 * 각 정규 훅이 정확히 1회, 규정된 인자 개수·타입으로 발화한다.**
 *
 * 종전에는 정규 훅(`after_install`/`after_activate`/`after_update`/`after_uninstall`)을
 * Service 만 발화했다. CLI 커맨드는 Manager 를 직접 부르므로 그 훅을 구독하는 리스너
 * (큐 재시작·활동 로그·언어팩 동기화)가 CLI 에서 전부 누락됐다. 리스너 단위 호출만으로는
 * "Manager 가 아예 발화하지 않는" 결함을 잡을 수 없어 실제 설치·제거를 태운다 —
 * `if ($result)` 안쪽 배치, 파괴 단계 전 `$info` 캡처 같은 배치 정확성도 함께 잠긴다.
 *
 * 실제 설치 대상은 가벼운 샘플 확장(`gnuboard7-hello_*`)이다. 개발 환경에 설치돼 있지 않은
 * 확장만 쓰고, 테스트가 만든 활성 디렉토리는 tearDown 에서 치운다.
 *
 * ⚠️ DDL 격리: 설치가 마이그레이션(DDL)을 돌려 RefreshDatabase 트랜잭션을 깨므로,
 * 설치가 필요한 검증은 확장 타입당 `test_z_` 메서드 1개로 묶어 클래스 끝에 둔다
 * (ModuleArtisanCommandsTest 클래스 주석과 같은 규약).
 */
class ExtensionLifecycleHookContractTest extends TestCase
{
    use FakesGithubReleases;
    use RefreshDatabase;

    private const MODULE = 'gnuboard7-hello_module';

    private const PLUGIN = 'gnuboard7-hello_plugin';

    private const TEMPLATE = 'gnuboard7-hello_admin_template';

    /**
     * 정규 훅 12건 — 사건 → 훅 이름.
     */
    private const CANONICAL_HOOKS = [
        'core.modules.after_install',
        'core.modules.after_activate',
        'core.modules.after_update',
        'core.modules.after_uninstall',
        'core.plugins.after_install',
        'core.plugins.after_activate',
        'core.plugins.after_update',
        'core.plugins.after_uninstall',
        'core.templates.after_install',
        'core.templates.after_activate',
        'core.templates.after_version_update',
        'core.templates.after_uninstall',
    ];

    /**
     * 구훅 9건 — identifier 1개만 전달한다 (deprecated, 구독자 존재로 유지).
     */
    private const LEGACY_HOOKS = [
        'core.modules.installed',
        'core.modules.activated',
        'core.modules.updated',
        'core.plugins.installed',
        'core.plugins.activated',
        'core.plugins.updated',
        'core.templates.installed',
        'core.templates.activated',
        'core.templates.updated',
    ];

    /**
     * 완료 훅 → 짝이 되는 직전 훅. 직전 훅도 Manager 가 발화해 CLI·어드민 UI 공통으로 1회여야 한다
     * (예전에는 Service 에서만 발화해 CLI 로 설치하면 직전 훅 구독자가 불리지 않았다 — 공개 #138 후속 10번).
     */
    private const BEFORE_OF = [
        'core.modules.after_install' => 'core.modules.before_install',
        'core.modules.after_activate' => 'core.modules.before_activate',
        'core.modules.after_update' => 'core.modules.before_update',
        'core.modules.after_uninstall' => 'core.modules.before_uninstall',
        'core.plugins.after_install' => 'core.plugins.before_install',
        'core.plugins.after_activate' => 'core.plugins.before_activate',
        'core.plugins.after_update' => 'core.plugins.before_update',
        'core.plugins.after_uninstall' => 'core.plugins.before_uninstall',
        'core.templates.after_install' => 'core.templates.before_install',
        'core.templates.after_activate' => 'core.templates.before_activate',
        'core.templates.after_version_update' => 'core.templates.before_version_update',
        'core.templates.after_uninstall' => 'core.templates.before_uninstall',
    ];

    /**
     * 비활성화 직전 훅 — 완료 훅 단언이 없는 사건이라 따로 스파이한다.
     */
    private const BEFORE_DEACTIVATE_HOOKS = [
        'core.modules.before_deactivate',
        'core.plugins.before_deactivate',
        'core.templates.before_deactivate',
    ];

    /**
     * 비활성화 완료 훅 — 켜진 확장을 제거할 때 비활성화를 먼저 거치는지 본다(설계 확정 2026-11-08).
     */
    private const AFTER_DEACTIVATE_HOOKS = [
        'core.modules.after_deactivate',
        'core.plugins.after_deactivate',
        'core.templates.after_deactivate',
    ];

    /**
     * 발화 기록 — 훅 이름 → 발화마다의 인자 배열 목록.
     *
     * @var array<string, array<int, array<int, mixed>>>
     */
    private array $fired = [];

    /**
     * 발화 순서 기록 — 직전 훅과 필터 훅의 순서를 판정할 때 쓴다.
     *
     * @var array<int, string>
     */
    private array $sequence = [];

    private Filesystem $originalFilesystem;

    /**
     * 이 테스트 시작 시점에 활성 디렉토리가 없던 샘플 확장 경로 (tearDown 정리 대상).
     *
     * @var array<int, string>
     */
    private array $createdActiveDirs = [];

    /**
     * 이 테스트 시작 시점에 이미 있던 샘플 확장 활성 디렉토리 → 사본 경로 (tearDown 이 되돌린다).
     *
     * 시험 DB 와 달리 파일 시스템은 개발 환경과 같다. 개발 환경에 샘플 확장이 설치돼 있으면 이 시험의 제거 단계가 그
     * 활성 디렉토리를 지워 개발 DB 에는 활성 행만 남는다(2026-11-05 실측: gnuboard7-hello_module 고아 활성 행).
     *
     * @var array<string, string>
     */
    private array $preservedActiveDirs = [];

    /**
     * 샘플 확장 활성 디렉토리 존재 여부 기록, 다른 활성 디렉토리 보호, 훅 캐시 굽기 차단,
     * 정규·구훅 스파이 등록을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 설치·업데이트 확인 경로의 GitHub 실제 접속 차단(테스트마다 최대 10초 대기 방지)
        $this->fakeGithubReleases();

        foreach ([
            base_path('modules/'.self::MODULE),
            base_path('plugins/'.self::PLUGIN),
            base_path('templates/'.self::TEMPLATE),
        ] as $dir) {
            if (! File::isDirectory($dir)) {
                $this->createdActiveDirs[] = $dir;
            } else {
                $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-lifecycle-preserve-'.basename($dir).'-'.uniqid();
                File::copyDirectory($dir, $copy);
                $this->preservedActiveDirs[$dir] = $copy;
            }
        }

        $this->protectOtherActiveDirectories();

        // update 성공 경로는 훅 캐시를 다시 굽는다. 테스트 프로세스가 실제
        // bootstrap/cache/hooks.php 를 테스트 DB 기준 활성 목록으로 덮어쓰지 않도록 막는다.
        $hookCache = Mockery::mock(HookCacheManager::class)->makePartial();
        $hookCache->shouldReceive('generate')->andReturn([]);
        $this->app->instance(HookCacheManager::class, $hookCache);

        // 실제 리스너(큐 재시작·활동 로그·언어팩)를 떼고 스파이만 단다 — 발화 자체만 관찰한다.
        HookManager::resetAll();
        $this->fired = [];
        foreach ([...self::CANONICAL_HOOKS, ...self::LEGACY_HOOKS, ...array_values(self::BEFORE_OF), ...self::BEFORE_DEACTIVATE_HOOKS, ...self::AFTER_DEACTIVATE_HOOKS] as $hook) {
            HookManager::addAction($hook, function (...$args) use ($hook) {
                $this->fired[$hook][] = $args;
                $this->sequence[] = $hook;
            }, 1);
        }

        // 템플릿 활성화 데이터 필터 — 직전 훅 뒤에, CLI·어드민 UI 공통으로 한 번 돈다
        HookManager::addFilter('core.templates.filter_activate_data', function ($data, ...$rest) {
            $this->fired['core.templates.filter_activate_data'][] = [$data, ...$rest];
            $this->sequence[] = 'core.templates.filter_activate_data';

            return $data;
        }, 1);
    }

    /**
     * 훅·파일 파사드를 원복하고 이 테스트가 만든 샘플 확장 활성 디렉토리를 치웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        File::swap($this->originalFilesystem);

        foreach ($this->createdActiveDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        // 개발 환경에 있던 샘플 확장 활성 디렉토리는 시작 때 모습으로 되돌린다
        foreach ($this->preservedActiveDirs as $dir => $copy) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
            File::copyDirectory($copy, $dir);
            File::deleteDirectory($copy);
        }

        parent::tearDown();
    }

    // ========================================================================
    // Non-DDL
    // ========================================================================

    /**
     * 실패 경로(없는 식별자)에서는 정규 훅이 하나도 발화하지 않는다.
     *
     * @effects no_lifecycle_hook_fires_on_failure_path
     *
     * @return void 반환값 없음
     */
    public function test_hooks_do_not_fire_on_failure_path(): void
    {
        $modules = app(ModuleManager::class);
        $plugins = app(PluginManager::class);
        $templates = app(TemplateManager::class);

        $attempts = [
            fn () => $modules->installModule('nonexistent-module'),
            fn () => $modules->updateModule('nonexistent-module'),
            fn () => $plugins->installPlugin('nonexistent-plugin'),
            fn () => $plugins->updatePlugin('nonexistent-plugin'),
            fn () => $templates->installTemplate('nonexistent-template'),
            fn () => $templates->updateTemplate('nonexistent-template'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
                // 실패가 기대 동작 — 발화 여부만 본다.
            }
        }

        // 직전 훅은 "시도" 시점에 발화한다(예전 Service 와 같다) — 여기서는 완료 훅·구훅만 본다.
        $completed = array_intersect_key($this->fired, array_flip([...self::CANONICAL_HOOKS, ...self::LEGACY_HOOKS]));
        $this->assertSame([], $completed, '실패 경로에서 생명주기 완료 훅이 발화했다');
    }

    // ========================================================================
    // DDL — 확장 타입당 1개, 클래스 마지막
    // ========================================================================

    // 이 메서드가 함께 검증하는 조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario extension_type=module, lifecycle_event=activate, entry_point=cli_manager
    // @scenario extension_type=module, lifecycle_event=update, entry_point=cli_manager
    // @scenario extension_type=module, lifecycle_event=update_noop, entry_point=cli_manager
    // @scenario extension_type=module, lifecycle_event=uninstall, entry_point=cli_manager
    // @scenario extension_type=module, lifecycle_event=install, entry_point=admin_service
    // @scenario extension_type=module, lifecycle_event=activate, entry_point=admin_service
    // @scenario extension_type=module, lifecycle_event=update, entry_point=admin_service
    // @scenario extension_type=module, lifecycle_event=update_noop, entry_point=admin_service
    // @scenario extension_type=module, lifecycle_event=uninstall, entry_point=admin_service
    /**
     * 모듈 4사건 — CLI(Manager 직접) 경로와 어드민 UI(Service) 경로 모두 1회씩.
     *
     * @scenario extension_type=module, lifecycle_event=install, entry_point=cli_manager
     *
     * @effects canonical_hook_fires_once_from_cli_manager_path, canonical_hook_fires_once_from_admin_service_path_without_duplicate, canonical_hook_arguments_match_listener_signature, update_noop_early_return_fires_canonical_hook_but_not_legacy, legacy_hook_still_fires_with_identifier_only, uninstall_info_captured_before_destructive_steps
     *
     * @return void 반환값 없음
     */
    public function test_z_module_lifecycle_fires_canonical_hooks_from_both_entry_points(): void
    {
        $manager = app(ModuleManager::class);
        $service = app(ModuleService::class);
        $id = self::MODULE;

        // ── CLI 진입점: Manager 직접 ──
        $manager->loadModules();

        $this->assertTrue($manager->installModule($id));
        $this->assertFiredOnce('core.modules.after_install', ['string', 'array'], $id);
        $this->assertLegacyFiredOnce('core.modules.installed', $id);

        $this->assertTrue($manager->activateModule($id)['success']);
        $this->assertFiredOnce('core.modules.after_activate', ['string', 'array'], $id);
        $this->assertLegacyFiredOnce('core.modules.activated', $id);

        // 업데이트할 것이 없음 — 조기 리턴에서도 정규 훅은 발화한다(구훅은 발화하지 않는다)
        $noop = $manager->updateModule($id);
        $this->assertFalse($noop['success']);
        $args = $this->assertFiredOnce('core.modules.after_update', ['string', 'array', 'array'], $id);
        $this->assertFalse($args[1]['success']);
        $this->assertArrayNotHasKey('core.modules.updated', $this->fired);

        // 강제 업데이트 — 성공 리턴 지점
        $this->resetFired();
        $this->assertTrue($manager->updateModule($id, true)['success']);
        $args = $this->assertFiredOnce('core.modules.after_update', ['string', 'array', 'array'], $id);
        $this->assertTrue($args[1]['success']);
        $this->assertSame($id, $args[2]['identifier'] ?? null);
        $this->assertLegacyFiredOnce('core.modules.updated', $id);

        $this->resetFired();
        $this->assertTrue($manager->deactivateModule($id)['success']);
        $this->assertBeforeFiredOnce('core.modules.before_deactivate', $id);
        $this->assertTrue($manager->uninstallModule($id, true));
        $args = $this->assertFiredOnce('core.modules.after_uninstall', ['string', 'array', 'bool'], $id);
        $this->assertSame($id, $args[1]['identifier'] ?? null, '제거 전 캡처한 정보여야 한다');
        $this->assertTrue($args[2]);

        // ── 어드민 UI 진입점: Service 경유 — 중복 발화 없이 정확히 1회 ──
        $this->resetFired();
        $service->installModule($id);
        $this->assertFiredOnce('core.modules.after_install', ['string', 'array'], $id);

        $this->assertTrue($service->activateModule($id)['success']);
        $this->assertFiredOnce('core.modules.after_activate', ['string', 'array'], $id);

        $service->updateModule($id);
        $this->assertFiredOnce('core.modules.after_update', ['string', 'array', 'array'], $id);

        $this->resetFired();
        $service->updateModule($id, VendorMode::Auto, 'overwrite', true);
        $this->assertFiredOnce('core.modules.after_update', ['string', 'array', 'array'], $id);

        $this->resetFired();
        $service->deactivateModule($id);
        $this->assertBeforeFiredOnce('core.modules.before_deactivate', $id);
        $this->assertTrue($service->uninstallModule($id, false));
        $args = $this->assertFiredOnce('core.modules.after_uninstall', ['string', 'array', 'bool'], $id);
        $this->assertFalse($args[2]);
    }

    // 이 메서드가 함께 검증하는 조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario extension_type=plugin, lifecycle_event=activate, entry_point=cli_manager
    // @scenario extension_type=plugin, lifecycle_event=update, entry_point=cli_manager
    // @scenario extension_type=plugin, lifecycle_event=update_noop, entry_point=cli_manager
    // @scenario extension_type=plugin, lifecycle_event=uninstall, entry_point=cli_manager
    // @scenario extension_type=plugin, lifecycle_event=install, entry_point=admin_service
    // @scenario extension_type=plugin, lifecycle_event=activate, entry_point=admin_service
    // @scenario extension_type=plugin, lifecycle_event=update, entry_point=admin_service
    // @scenario extension_type=plugin, lifecycle_event=update_noop, entry_point=admin_service
    // @scenario extension_type=plugin, lifecycle_event=uninstall, entry_point=admin_service
    /**
     * 플러그인 4사건 — P4 만 인자 순서가 (id, deleteData, result) 다.
     *
     * @scenario extension_type=plugin, lifecycle_event=install, entry_point=cli_manager
     *
     * @effects canonical_hook_fires_once_from_cli_manager_path, canonical_hook_fires_once_from_admin_service_path_without_duplicate, canonical_hook_arguments_match_listener_signature, update_noop_early_return_fires_canonical_hook_but_not_legacy, legacy_hook_still_fires_with_identifier_only, plugin_uninstall_arguments_keep_id_deletedata_result_order
     *
     * @return void 반환값 없음
     */
    public function test_z_plugin_lifecycle_fires_canonical_hooks_from_both_entry_points(): void
    {
        $modules = app(ModuleManager::class);
        $manager = app(PluginManager::class);
        $service = app(PluginService::class);
        $id = self::PLUGIN;

        // 샘플 플러그인은 샘플 모듈에 의존한다
        $modules->loadModules();
        $modules->installModule(self::MODULE);
        $modules->activateModule(self::MODULE);
        $manager->loadPlugins();

        // ── CLI 진입점 ──
        $this->resetFired();
        $this->assertTrue($manager->installPlugin($id));
        $this->assertFiredOnce('core.plugins.after_install', ['string', 'array'], $id);
        $this->assertLegacyFiredOnce('core.plugins.installed', $id);

        $this->assertTrue($manager->activatePlugin($id)['success']);
        $this->assertFiredOnce('core.plugins.after_activate', ['string', 'array'], $id);
        $this->assertLegacyFiredOnce('core.plugins.activated', $id);

        $noop = $manager->updatePlugin($id);
        $this->assertFalse($noop['success']);
        $args = $this->assertFiredOnce('core.plugins.after_update', ['string', 'array', 'array'], $id);
        $this->assertFalse($args[1]['success']);
        $this->assertArrayNotHasKey('core.plugins.updated', $this->fired);

        $this->resetFired();
        $this->assertTrue($manager->updatePlugin($id, true)['success']);
        $args = $this->assertFiredOnce('core.plugins.after_update', ['string', 'array', 'array'], $id);
        $this->assertTrue($args[1]['success']);
        $this->assertLegacyFiredOnce('core.plugins.updated', $id);

        $this->resetFired();
        $manager->deactivatePlugin($id);
        $this->assertBeforeFiredOnce('core.plugins.before_deactivate', $id);
        $this->assertTrue($manager->uninstallPlugin($id, true));
        $args = $this->assertFiredOnce('core.plugins.after_uninstall', ['string', 'bool', 'bool'], $id);
        $this->assertTrue($args[1], '2번째 인자는 deleteData');
        $this->assertTrue($args[2], '3번째 인자는 result');

        // ── 어드민 UI 진입점 ──
        $this->resetFired();
        $service->installPlugin($id);
        $this->assertFiredOnce('core.plugins.after_install', ['string', 'array'], $id);

        $this->assertTrue($service->activatePlugin($id)['success']);
        $this->assertFiredOnce('core.plugins.after_activate', ['string', 'array'], $id);

        $service->updatePlugin($id);
        $this->assertFiredOnce('core.plugins.after_update', ['string', 'array', 'array'], $id);

        $this->resetFired();
        $service->updatePlugin($id, VendorMode::Auto, 'overwrite', true);
        $this->assertFiredOnce('core.plugins.after_update', ['string', 'array', 'array'], $id);

        $this->resetFired();
        $service->deactivatePlugin($id);
        $this->assertBeforeFiredOnce('core.plugins.before_deactivate', $id);
        $this->assertTrue($service->uninstallPlugin($id, false));
        $args = $this->assertFiredOnce('core.plugins.after_uninstall', ['string', 'bool', 'bool'], $id);
        $this->assertFalse($args[1]);
        $this->assertTrue($args[2]);

        // 정리 — 의존 모듈 제거
        $modules->deactivateModule(self::MODULE);
        $modules->uninstallModule(self::MODULE, true);
    }

    // 이 메서드가 함께 검증하는 조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario extension_type=template, lifecycle_event=activate, entry_point=cli_manager
    // @scenario extension_type=template, lifecycle_event=update, entry_point=cli_manager
    // @scenario extension_type=template, lifecycle_event=update_noop, entry_point=cli_manager
    // @scenario extension_type=template, lifecycle_event=uninstall, entry_point=cli_manager
    // @scenario extension_type=template, lifecycle_event=install, entry_point=admin_service
    // @scenario extension_type=template, lifecycle_event=activate, entry_point=admin_service
    // @scenario extension_type=template, lifecycle_event=update, entry_point=admin_service
    // @scenario extension_type=template, lifecycle_event=update_noop, entry_point=admin_service
    // @scenario extension_type=template, lifecycle_event=uninstall, entry_point=admin_service
    /**
     * 템플릿 4사건 — T2(after_activate)만 식별자 없이 정보 배열 1개를 넘긴다.
     *
     * @scenario extension_type=template, lifecycle_event=install, entry_point=cli_manager
     *
     * @effects canonical_hook_fires_once_from_cli_manager_path, canonical_hook_fires_once_from_admin_service_path_without_duplicate, canonical_hook_arguments_match_listener_signature, update_noop_early_return_fires_canonical_hook_but_not_legacy, legacy_hook_still_fires_with_identifier_only, uninstall_info_captured_before_destructive_steps, template_activate_passes_info_array_only, template_uninstall_delete_data_forwarded_from_service, template_activate_filter_runs_once_after_before_hook
     *
     * @return void 반환값 없음
     */
    public function test_z_template_lifecycle_fires_canonical_hooks_from_both_entry_points(): void
    {
        $manager = app(TemplateManager::class);
        $service = app(TemplateService::class);
        $id = self::TEMPLATE;

        $manager->loadTemplates();

        // ── CLI 진입점 ──
        $this->assertTrue($manager->installTemplate($id));
        $this->assertFiredOnce('core.templates.after_install', ['string', 'array'], $id);
        $this->assertLegacyFiredOnce('core.templates.installed', $id);

        $this->resetFired();
        $this->assertTrue($manager->activateTemplate($id)['success']);
        $args = $this->assertFiredOnce('core.templates.after_activate', ['array']);
        $this->assertSame($id, $args[0]['identifier'] ?? null);
        $this->assertActivateFilterRanOnceAfterBeforeHook($id);
        $this->assertLegacyFiredOnce('core.templates.activated', $id);

        $noop = $manager->updateTemplate($id);
        $this->assertFalse($noop['success']);
        $args = $this->assertFiredOnce('core.templates.after_version_update', ['string', 'array', 'array'], $id);
        $this->assertFalse($args[1]['success']);
        $this->assertArrayNotHasKey('core.templates.updated', $this->fired);

        $this->resetFired();
        $this->assertTrue($manager->updateTemplate($id, true)['success']);
        $args = $this->assertFiredOnce('core.templates.after_version_update', ['string', 'array', 'array'], $id);
        $this->assertTrue($args[1]['success']);
        $this->assertLegacyFiredOnce('core.templates.updated', $id);

        $this->resetFired();
        $this->assertTrue($manager->uninstallTemplate($id, null, $backups, true));
        $args = $this->assertFiredOnce('core.templates.after_uninstall', ['string', 'array', 'bool'], $id);
        $this->assertSame($id, $args[1]['identifier'] ?? null, '제거 전 캡처한 정보여야 한다');
        $this->assertTrue($args[2]);

        // ── 어드민 UI 진입점 ──
        $this->resetFired();
        $service->installTemplate($id);
        $this->assertFiredOnce('core.templates.after_install', ['string', 'array'], $id);

        $this->resetFired();
        $this->assertTrue($service->activateTemplate($id)['success']);
        $this->assertFiredOnce('core.templates.after_activate', ['array']);
        $this->assertActivateFilterRanOnceAfterBeforeHook($id);

        // 어드민 UI 는 유형의 마지막 활성 템플릿을 끄거나 지우지 못한다 — 같은 유형에 다른 활성 템플릿이
        // 남을 때만 받는다. 그 전제를 레코드 대역으로 만들어 직전 훅 계약을 본다(파일 없는 대역 — 이 경로만 읽는다).
        $standIn = Template::factory()->create(['type' => 'admin', 'status' => 'active']);

        // 비활성화 직전 훅도 어드민 UI 경로에서 1회 — 확인 뒤 다시 켜 이후 단계를 잇는다
        $this->resetFired();
        $this->assertNotNull($service->deactivateTemplate($id));
        $this->assertBeforeFiredOnce('core.templates.before_deactivate', $id);
        $this->assertTrue($manager->activateTemplate($id)['success']);
        $this->resetFired();

        $service->performVersionUpdate($id);
        $this->assertFiredOnce('core.templates.after_version_update', ['string', 'array', 'array'], $id);

        $this->resetFired();
        $service->performVersionUpdate($id, 'overwrite', true);
        $this->assertFiredOnce('core.templates.after_version_update', ['string', 'array', 'array'], $id);

        // 어드민이 "데이터도 삭제" 를 고르면 훅 인자에도 true 가 실려야 한다
        // (다시 켠 활성화가 같은 유형의 대역을 꺼 두었다 — 마지막 활성 템플릿 삭제 거절을 피하려고 대역을 다시 켠다.
        //  메모리의 모델은 여전히 active 라 $standIn->update() 는 바뀐 값이 없다며 쿼리를 내지 않으므로 쿼리로 바꾼다)
        Template::whereKey($standIn->getKey())->update(['status' => 'active']);
        $this->resetFired();
        $this->assertNotNull($service->uninstallTemplate($id, true));
        $args = $this->assertFiredOnce('core.templates.after_uninstall', ['string', 'array', 'bool'], $id);
        $this->assertTrue($args[2]);
    }

    /**
     * 켜진 확장이 의존하는 모듈은 제거되지 않는다 — 강제 비활성화 뒤라도 제거는 거부한다.
     *
     * 실측(공개 #138 ①): 의존 플러그인이 켜진 채로 모듈을 강제로 끈 뒤 제거하면 막히지 않아, 플러그인이 필요한 모듈
     * 없이 켜진 채로 남았다. 비활성화는 경고 뒤 강제 진행이 가능하지만, 제거는 되돌릴 수 없으므로 막는다.
     *
     * @scenario extension_type=module, lifecycle_event=uninstall, entry_point=cli_manager
     *
     * @effects uninstall_blocked_while_active_dependents_exist
     *
     * @return void 반환값 없음
     */
    public function test_z_module_uninstall_is_blocked_while_a_dependent_plugin_is_active(): void
    {
        $modules = app(ModuleManager::class);
        $plugins = app(PluginManager::class);
        $modules->loadModules();
        $plugins->loadPlugins();

        $this->assertTrue($modules->installModule(self::MODULE));
        $this->assertTrue($modules->activateModule(self::MODULE)['success']);
        $this->assertTrue($plugins->installPlugin(self::PLUGIN));
        $this->assertTrue($plugins->activatePlugin(self::PLUGIN)['success']);

        // 의존 플러그인이 켜진 채 모듈을 강제로 끈다 (화면의 「그래도 비활성화」)
        $this->assertTrue($modules->deactivateModule(self::MODULE, true)['success']);

        $blocked = false;
        try {
            $blocked = ! $modules->uninstallModule(self::MODULE, true);
        } catch (\Throwable $e) {
            $blocked = true;
            $this->assertStringContainsString(self::PLUGIN, $e->getMessage(), '어떤 확장 때문에 막혔는지 알려야 한다');
        }

        $this->assertTrue($blocked, '켜진 의존 플러그인이 있는데 모듈이 제거됐다');
        $this->assertNotNull(Module::where('identifier', self::MODULE)->first(), '모듈 레코드가 남아 있어야 한다');

        // 정리 — 의존 플러그인을 먼저 치우면 제거된다
        $plugins->deactivatePlugin(self::PLUGIN);
        $this->assertTrue($plugins->uninstallPlugin(self::PLUGIN, true));
        $this->assertTrue($modules->uninstallModule(self::MODULE, true));
    }

    /**
     * 켜진 모듈 제거 — 의존 확장·진행 중 상태 가드에 막히면 끄지 않고, 지나면 비활성화(훅 포함)를 먼저 거친 뒤 제거한다.
     * 제거 트랜잭션이 롤백되면 모듈 행은 남고 꺼진 상태다(비활성화는 트랜잭션 밖에서 끝난다).
     *
     * 종전에는 켜진 모듈을 바로 제거해 `core.modules.after_deactivate` 가 돌지 않았다(설계 확정 2026-11-08).
     *
     * @scenario extension_type=module, lifecycle_event=uninstall, entry_point=cli_manager
     *
     * @effects active_uninstall_deactivates_first_with_hooks, uninstall_guards_run_before_deactivation, uninstall_rollback_keeps_extension_deactivated
     *
     * @return void 반환값 없음
     */
    public function test_z_active_module_uninstall_deactivates_first_after_guards(): void
    {
        $modules = app(ModuleManager::class);
        $plugins = app(PluginManager::class);
        $modules->loadModules();
        $plugins->loadPlugins();
        $this->assertTrue($modules->installModule(self::MODULE));
        $this->assertTrue($modules->activateModule(self::MODULE)['success']);
        $this->assertTrue($plugins->installPlugin(self::PLUGIN));
        $this->assertTrue($plugins->activatePlugin(self::PLUGIN)['success']);

        // ① 의존 확장 검사가 비활성화보다 먼저 — 켜진 플러그인이 의존하면 모듈은 켜진 채 남는다
        $this->resetFired();
        $this->assertThrows(fn () => $modules->uninstallModule(self::MODULE));
        $this->assertSame('active', Module::where('identifier', self::MODULE)->value('status'));
        $this->assertNotDeactivated('modules');

        // 켜진 플러그인 제거도 비활성화를 먼저 거친다 (의존 확장이 사라져 모듈 제거가 가능해진다)
        $this->resetFired();
        $this->assertTrue($plugins->uninstallPlugin(self::PLUGIN));
        $this->assertDeactivatedBeforeUninstall('plugins', self::PLUGIN);

        // ② 진행 중 상태 가드가 비활성화보다 먼저
        $this->assertInProgressGuardRunsBeforeDeactivation(Module::class, self::MODULE, 'modules', fn () => $modules->uninstallModule(self::MODULE));

        // ③ 롤백 — 행 삭제 쿼리에서 실패시키면 제거는 롤백되고 비활성화는 남는다
        $this->resetFired();
        $this->failOnRowDelete('modules', fn () => $modules->uninstallModule(self::MODULE));
        $this->assertCount(1, $this->fired['core.modules.after_deactivate'] ?? [], '롤백 전에 비활성화가 끝나 있어야 한다');
        $this->assertArrayNotHasKey('core.modules.after_uninstall', $this->fired);
        $this->assertSame('inactive', Module::where('identifier', self::MODULE)->value('status'), '롤백 뒤 모듈은 꺼진 채 남는다');

        // ④ 다시 켠 뒤 제거 — 비활성화 훅이 제거 직전·완료 훅 사이에 한 번
        $this->assertTrue($modules->activateModule(self::MODULE)['success']);
        $this->resetFired();
        $this->assertTrue($modules->uninstallModule(self::MODULE, true));
        $this->assertDeactivatedBeforeUninstall('modules', self::MODULE);
        $this->assertNull(Module::where('identifier', self::MODULE)->first());
    }

    /**
     * 켜진 플러그인 제거 — 진행 중 상태 가드에 막히면 끄지 않고, 지나면 비활성화를 먼저 거친다. 롤백되면 꺼진 채 남는다.
     *
     * @scenario extension_type=plugin, lifecycle_event=uninstall, entry_point=admin_service
     *
     * @effects active_uninstall_deactivates_first_with_hooks, uninstall_guards_run_before_deactivation, uninstall_rollback_keeps_extension_deactivated
     *
     * @return void 반환값 없음
     */
    public function test_z_active_plugin_uninstall_deactivates_first_after_guards(): void
    {
        $modules = app(ModuleManager::class);
        $plugins = app(PluginManager::class);
        $modules->loadModules();
        $plugins->loadPlugins();
        $this->assertTrue($modules->installModule(self::MODULE));
        $this->assertTrue($modules->activateModule(self::MODULE)['success']);
        $this->assertTrue($plugins->installPlugin(self::PLUGIN));
        $this->assertTrue($plugins->activatePlugin(self::PLUGIN)['success']);

        $this->assertInProgressGuardRunsBeforeDeactivation(Plugin::class, self::PLUGIN, 'plugins', fn () => $plugins->uninstallPlugin(self::PLUGIN));

        $this->resetFired();
        $this->failOnRowDelete('plugins', fn () => $plugins->uninstallPlugin(self::PLUGIN));
        $this->assertCount(1, $this->fired['core.plugins.after_deactivate'] ?? [], '롤백 전에 비활성화가 끝나 있어야 한다');
        $this->assertArrayNotHasKey('core.plugins.after_uninstall', $this->fired);
        $this->assertSame('inactive', Plugin::where('identifier', self::PLUGIN)->value('status'), '롤백 뒤 플러그인은 꺼진 채 남는다');

        // 어드민 UI 진입점도 같은 순서
        $this->assertTrue($plugins->activatePlugin(self::PLUGIN)['success']);
        $this->resetFired();
        $this->assertTrue(app(PluginService::class)->uninstallPlugin(self::PLUGIN, true));
        $this->assertDeactivatedBeforeUninstall('plugins', self::PLUGIN);
        $this->assertNull(Plugin::where('identifier', self::PLUGIN)->first());

        $modules->deactivateModule(self::MODULE);
        $modules->uninstallModule(self::MODULE, true);
    }

    /**
     * 켜진 템플릿 제거 — 서비스의 「유형의 마지막 활성 템플릿」 거절과 진행 중 상태 가드에 막히면 끄지 않고,
     * 지나면 비활성화를 먼저 거친다. 롤백되면 꺼진 채 남는다.
     *
     * @scenario extension_type=template, lifecycle_event=uninstall, entry_point=admin_service
     *
     * @effects active_uninstall_deactivates_first_with_hooks, uninstall_guards_run_before_deactivation, uninstall_rollback_keeps_extension_deactivated
     *
     * @return void 반환값 없음
     */
    public function test_z_active_template_uninstall_deactivates_first_after_guards(): void
    {
        $manager = app(TemplateManager::class);
        $service = app(TemplateService::class);
        $manager->loadTemplates();
        $this->assertTrue($manager->installTemplate(self::TEMPLATE));
        $this->assertTrue($manager->activateTemplate(self::TEMPLATE)['success']);
        $this->assertSame(
            [self::TEMPLATE],
            Template::query()->where('type', 'admin')->where('status', 'active')->pluck('identifier')->all(),
            '전제: 이 템플릿이 유일한 활성 관리자 템플릿이어야 한다'
        );

        // ① 유형의 마지막 활성 템플릿은 서비스가 거절한다 — 끄지 않는다
        $this->resetFired();
        $this->assertThrows(fn () => $service->uninstallTemplate(self::TEMPLATE), ValidationException::class);
        $this->assertSame('active', Template::where('identifier', self::TEMPLATE)->value('status'));
        $this->assertNotDeactivated('templates');

        // ② 진행 중 상태 가드가 비활성화보다 먼저
        $this->assertInProgressGuardRunsBeforeDeactivation(Template::class, self::TEMPLATE, 'templates', fn () => $manager->uninstallTemplate(self::TEMPLATE));

        // ③ 롤백
        $this->resetFired();
        $this->failOnRowDelete('templates', fn () => $manager->uninstallTemplate(self::TEMPLATE));
        $this->assertCount(1, $this->fired['core.templates.after_deactivate'] ?? [], '롤백 전에 비활성화가 끝나 있어야 한다');
        $this->assertArrayNotHasKey('core.templates.after_uninstall', $this->fired);
        $this->assertSame('inactive', Template::where('identifier', self::TEMPLATE)->value('status'), '롤백 뒤 템플릿은 꺼진 채 남는다');

        // ④ 다른 활성 관리자 템플릿(레코드 대역)이 있으면 서비스가 받고, 비활성화를 먼저 거쳐 제거한다
        $this->assertTrue($manager->activateTemplate(self::TEMPLATE)['success']);
        $standIn = Template::factory()->create(['type' => 'admin', 'status' => 'active']);
        $this->resetFired();
        $this->assertNotNull($service->uninstallTemplate(self::TEMPLATE, true));
        $this->assertDeactivatedBeforeUninstall('templates', self::TEMPLATE);
        $this->assertNull(Template::where('identifier', self::TEMPLATE)->first());
        $standIn->delete();
    }

    /**
     * DB 행은 있는데 활성 디렉토리만 지워진 확장(파일만 지워진 사이트)을 번들 소스 업데이트로 되살린다.
     *
     * 종전에는 그 상태를 되돌릴 길이 없었다 — 업데이트는 「모듈을 찾을 수 없습니다」, 설치는 「이미 설치되어 있습니다」로
     * 막혔다. 모듈·플러그인·템플릿 모두 `--source=bundled`(그리고 기본 소스의 강제 업데이트)가 활성 디렉토리가 없으면
     * `_pending`·`_bundled` 원본을 복사한 뒤 업데이트를 잇고, 활성 상태는 그대로다. 새 CLI 프로세스처럼 메모리에 확장이
     * 없도록 관리자를 새로 만든다.
     *
     * @scenario extension_type=module, lifecycle_event=update, entry_point=cli_manager
     *
     * @effects update_restores_missing_active_directory_from_bundled
     *
     * @return void 반환값 없음
     */
    public function test_z_update_restores_missing_active_directory_from_bundled(): void
    {
        $modules = app(ModuleManager::class);
        $plugins = app(PluginManager::class);
        $templates = app(TemplateManager::class);
        $modules->loadModules();
        $plugins->loadPlugins();
        $templates->loadTemplates();
        $this->assertTrue($modules->installModule(self::MODULE));
        $this->assertTrue($modules->activateModule(self::MODULE)['success']);
        $this->assertTrue($plugins->installPlugin(self::PLUGIN));
        $this->assertTrue($plugins->activatePlugin(self::PLUGIN)['success']);
        $this->assertTrue($templates->installTemplate(self::TEMPLATE));

        $cases = [
            [ModuleManager::class, 'modules', self::MODULE, 'module.php', Module::class, 'active', fn ($m) => $m->updateModule(self::MODULE, true, null, VendorMode::Auto, 'overwrite', null, 'bundled')],
            [PluginManager::class, 'plugins', self::PLUGIN, 'plugin.php', Plugin::class, 'active', fn ($m) => $m->updatePlugin(self::PLUGIN, true, null, VendorMode::Auto, 'overwrite', null, 'bundled')],
            [TemplateManager::class, 'templates', self::TEMPLATE, 'template.json', Template::class, 'inactive', fn ($m) => $m->updateTemplate(self::TEMPLATE, true, null, 'overwrite', 'bundled')],
        ];

        foreach ($cases as [$managerClass, $kind, $id, $entry, $model, $status, $update]) {
            File::deleteDirectory(base_path("{$kind}/{$id}"));
            $this->assertDirectoryDoesNotExist(base_path("{$kind}/{$id}"));

            // 새 프로세스 — 메모리에 그 확장이 없다
            $this->app->forgetInstance($managerClass);
            $manager = app($managerClass);
            match ($kind) {
                'modules' => $manager->loadModules(),
                'plugins' => $manager->loadPlugins(),
                'templates' => $manager->loadTemplates(),
            };

            $result = $update($manager);

            $this->assertTrue($result['success'] ?? false, "{$id} 활성 디렉토리 복구 업데이트 실패: ".($result['message'] ?? ''));
            $this->assertFileExists(base_path("{$kind}/{$id}/{$entry}"), "{$id} 활성 디렉토리가 되살아나지 않았다");
            $this->assertSame($status, $model::where('identifier', $id)->value('status'), "{$id} 상태가 업데이트 전과 다르다");
        }

        app(PluginManager::class)->deactivatePlugin(self::PLUGIN);
        app(PluginManager::class)->uninstallPlugin(self::PLUGIN, true);
        app(ModuleManager::class)->uninstallModule(self::MODULE, true);
        app(TemplateManager::class)->uninstallTemplate(self::TEMPLATE, null, $backups, true);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * 비활성화 훅이 제거 직전 훅 뒤, 제거 완료 훅 앞에서 각각 한 번 돌았는지 단언합니다.
     *
     * @param  string  $kind  modules, plugins 또는 templates
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    private function assertDeactivatedBeforeUninstall(string $kind, string $identifier): void
    {
        $order = [
            "core.{$kind}.before_uninstall",
            "core.{$kind}.before_deactivate",
            "core.{$kind}.after_deactivate",
            "core.{$kind}.after_uninstall",
        ];

        foreach ($order as $hook) {
            $this->assertCount(1, $this->fired[$hook] ?? [], "{$hook} 발화 횟수가 1이 아니다 — 켜진 확장 제거는 비활성화를 거쳐야 한다");
            $this->assertSame($identifier, $this->fired[$hook][0][0] ?? null, "{$hook} 첫 인자는 식별자");
        }

        $positions = array_map(fn (string $hook) => array_search($hook, $this->sequence, true), $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '훅 순서가 before_uninstall → before_deactivate → after_deactivate → after_uninstall 이 아니다');
    }

    /**
     * 비활성화 훅이 하나도 돌지 않았는지 단언합니다 (가드에 막힌 제거는 끄지 않는다).
     *
     * @param  string  $kind  modules, plugins 또는 templates
     * @return void 반환값 없음
     */
    private function assertNotDeactivated(string $kind): void
    {
        $this->assertArrayNotHasKey("core.{$kind}.before_deactivate", $this->fired, '가드에 막힌 제거가 비활성화를 시작했다');
        $this->assertArrayNotHasKey("core.{$kind}.after_deactivate", $this->fired, '가드에 막힌 제거가 확장을 껐다');
    }

    /**
     * 행 상태를 진행 중(updating)으로 두면 제거가 막히고 비활성화도 일어나지 않는지 확인한 뒤 상태를 되돌립니다.
     *
     * @param  class-string  $model  확장 모델
     * @param  string  $identifier  확장 식별자
     * @param  string  $kind  modules, plugins 또는 templates
     * @param  \Closure  $uninstall  제거 호출
     * @return void 반환값 없음
     */
    private function assertInProgressGuardRunsBeforeDeactivation(string $model, string $identifier, string $kind, \Closure $uninstall): void
    {
        $model::query()->where('identifier', $identifier)->update(['status' => 'updating']);
        $this->resetFired();

        $this->assertThrows($uninstall, \RuntimeException::class);
        $this->assertNotDeactivated($kind);
        $this->assertSame('updating', $model::query()->where('identifier', $identifier)->value('status'));

        $model::query()->where('identifier', $identifier)->update(['status' => 'active']);
    }

    /**
     * 확장 행 삭제 쿼리가 실행된 순간 예외를 던지게 해 제거 트랜잭션을 롤백시키고, 제거가 실패하는지 확인합니다.
     *
     * @param  string  $table  확장 테이블 (접두사 제외)
     * @param  \Closure  $uninstall  제거 호출
     * @return void 반환값 없음
     */
    private function failOnRowDelete(string $table, \Closure $uninstall): void
    {
        $armed = true;
        $needle = 'delete from `'.strtolower(DB::connection()->getTablePrefix()).$table.'`';
        DB::listen(function (QueryExecuted $query) use (&$armed, $needle) {
            if ($armed && str_starts_with(strtolower($query->sql), $needle)) {
                $armed = false;

                throw new \RuntimeException('시험: 제거 트랜잭션 실패 주입');
            }
        });

        try {
            $this->assertThrows($uninstall, \RuntimeException::class, '시험: 제거 트랜잭션 실패 주입');
        } finally {
            $armed = false;
        }
    }

    /**
     * 정규 훅이 정확히 1회, 규정된 인자 타입으로 발화했는지 단언합니다.
     *
     * @param  string  $hook  훅 이름
     * @param  array<int, string>  $types  인자별 기대 타입 (string|array|bool)
     * @param  string|null  $identifier  첫 인자로 기대하는 식별자 (없으면 검사 생략)
     * @return array<int, mixed> 그 1회 발화의 인자 배열
     */
    private function assertFiredOnce(string $hook, array $types, ?string $identifier = null): array
    {
        $calls = $this->fired[$hook] ?? [];
        $this->assertCount(1, $calls, "{$hook} 발화 횟수가 1이 아니다 (".count($calls).'회)');

        $args = $calls[0];
        $this->assertGreaterThanOrEqual(count($types), count($args), "{$hook} 인자가 부족하다");

        foreach ($types as $i => $type) {
            $this->assertSame($type, match (true) {
                is_string($args[$i]) => 'string',
                is_array($args[$i]) => 'array',
                is_bool($args[$i]) => 'bool',
                default => get_debug_type($args[$i]),
            }, "{$hook} 인자 #{$i} 타입");
        }

        if ($identifier !== null) {
            $this->assertSame($identifier, $args[0], "{$hook} 첫 인자는 식별자");
        }

        if (isset(self::BEFORE_OF[$hook])) {
            $this->assertBeforeFiredOnce(self::BEFORE_OF[$hook], $identifier);
        }

        return $args;
    }

    /**
     * 직전 훅이 정확히 1회, 식별자를 첫 인자로 발화했는지 단언합니다.
     *
     * @param  string  $hook  직전 훅 이름
     * @param  string|null  $identifier  첫 인자로 기대하는 식별자 (없으면 검사 생략)
     * @return void 반환값 없음
     */
    private function assertBeforeFiredOnce(string $hook, ?string $identifier): void
    {
        $calls = $this->fired[$hook] ?? [];
        $this->assertCount(1, $calls, "{$hook} 발화 횟수가 1이 아니다 (".count($calls).'회) — 직전 훅도 CLI·어드민 UI 공통 1회');

        if ($identifier !== null) {
            $this->assertSame($identifier, $calls[0][0] ?? null, "{$hook} 첫 인자는 식별자");
        }
    }

    /**
     * 구훅이 identifier 1개로 정확히 1회 발화했는지 단언합니다 (D4 — 사외 확장 호환).
     *
     * @param  string  $hook  구훅 이름
     * @param  string  $identifier  기대 식별자
     * @return void 반환값 없음
     */
    private function assertLegacyFiredOnce(string $hook, string $identifier): void
    {
        $this->assertSame([[$identifier]], $this->fired[$hook] ?? [], "{$hook} 구훅은 identifier 1개로 1회");
    }

    /**
     * 발화 기록을 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function resetFired(): void
    {
        $this->fired = [];
        $this->sequence = [];
    }

    /**
     * 템플릿 활성화 필터가 한 번, 직전 훅 뒤에 돌았는지 확인합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    private function assertActivateFilterRanOnceAfterBeforeHook(string $identifier): void
    {
        $this->assertCount(1, $this->fired['core.templates.filter_activate_data'] ?? [], '활성화 데이터 필터가 정확히 한 번 돌아야 한다');
        [$data, $template] = $this->fired['core.templates.filter_activate_data'][0] + [null, null];
        $this->assertIsArray($data);
        $this->assertSame($identifier, $template->identifier ?? null, '필터 둘째 인자는 템플릿 행이다');

        $before = array_search('core.templates.before_activate', $this->sequence, true);
        $filter = array_search('core.templates.filter_activate_data', $this->sequence, true);
        $this->assertNotFalse($before, '활성화 직전 훅이 돌지 않았다');
        $this->assertLessThan($filter, $before, '활성화 데이터 필터가 직전 훅보다 먼저 돌았다(before → filter 순서 위반)');
    }

    /**
     * 샘플 확장을 제외한 활성 확장 디렉토리의 삭제·이동을 차단합니다.
     *
     * ProtectsExtensionDirectories 와 같은 File 파사드 partial mock 패턴이되, 이 테스트가
     * 실제로 설치·제거하는 샘플 확장 3종의 활성 디렉토리만은 통과시킨다 — 그래야 update 의
     * 원자적 교체와 uninstall 의 파일 삭제가 실제로 일어나 배치 정확성을 검증할 수 있다.
     *
     * @return void 반환값 없음
     */
    private function protectOtherActiveDirectories(): void
    {
        $this->originalFilesystem = File::getFacadeRoot();
        $fixtures = [self::MODULE, self::PLUGIN, self::TEMPLATE];

        $isProtected = function (string $path) use ($fixtures): bool {
            $normalized = str_replace('\\', '/', $path);
            if (! preg_match('#/(modules|plugins|templates)/((?![\._])[^/]+)$#', $normalized, $m)) {
                return false;
            }

            return ! in_array($m[2], $fixtures, true);
        };

        $mock = Mockery::mock($this->originalFilesystem)->makePartial();
        $mock->shouldReceive('deleteDirectory')->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $dir, bool $preserve = false) => $isProtected($dir)
                ? true
                : $this->originalFilesystem->deleteDirectory($dir, $preserve));
        $mock->shouldReceive('moveDirectory')->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $from, string $to, bool $overwrite = false) => $isProtected($from)
                ? true
                : $this->originalFilesystem->moveDirectory($from, $to, $overwrite));

        File::swap($mock);
    }
}
