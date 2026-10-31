<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\Layout\FragmentReadCache;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\RefreshesLayoutExtensions;
use App\Extension\Traits\SynchronizesLayoutRows;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 깨진 확장 조각은 한 프로세스에서 파일마다 ERROR 1줄만 남긴다 (#725 W1·W5).
 *
 * 조각 컴파일은 템플릿과 무관한데(2026-09-28 결정) 등록 경로가 템플릿 루프마다 다시 읽고 실패를 캐시하지 않아,
 * 깨진 조각 1개가 확장 활성화 1회에 「활성 템플릿 수 × 경로 수」 줄을 남겼고 절반에는 붙지도 않는 템플릿 이름이
 * 찍혔다(실측: 마케팅 재활성화 1회 +4줄). 이 테스트는 그 잡음의 세 모양을 잠근다 —
 *
 *  ⓐ 한 파일의 실패는 경로·템플릿을 몇 번 돌아도 ERROR 1줄이고, 문맥에 `template` 대신 소유 확장이 실린다.
 *  ⓑ 파일마다 1줄이며, 파일을 고치면(mtime·크기가 바뀌면) 다시 컴파일돼 등록된다.
 *  ⓒ `--layout-source=json` 미이행 경고도 파일마다 1줄이다.
 *  ⓓ 「붙을 자리 없음」 건너뜀은 debug 다(확장별 요약 `skipped_no_host` 가 개수를 남긴다).
 *
 * 스키마를 새로 만들지 않는다(DatabaseTransactions) — 기존 템플릿을 모두 끄고 실행 고유 식별자를 쓴다.
 */
class FragmentFailureLogOnceTest extends TestCase
{
    use DatabaseTransactions;

    private string $tmp;

    private string $moduleId;

    private Template $admin;

    private Template $user;

    /**
     * 임시 조각 디렉토리와 관리자·사용자 템플릿(각 호스트 레이아웃 포함)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $this->moduleId = 'probe-lognoise-'.$suffix;
        $this->tmp = base_path('storage/framework/testing/fragment_log_once_'.$suffix);
        File::ensureDirectoryExists($this->tmp.'/module');

        // 등록 경로는 「활성 템플릿 전부」 를 돈다 — 이 테스트의 두 템플릿만 활성으로 둔다(트랜잭션이 되돌린다).
        Template::query()->update(['status' => ExtensionStatus::Inactive->value]);
        $this->admin = Template::factory()->create(['identifier' => 'probe-admin-'.$suffix, 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);
        $this->user = Template::factory()->create(['identifier' => 'probe-user-'.$suffix, 'type' => 'user', 'status' => ExtensionStatus::Active->value]);

        TemplateLayout::factory()->create(['template_id' => $this->admin->id, 'name' => 'admin_user_form']);
        TemplateLayout::factory()->create(['template_id' => $this->user->id, 'name' => 'auth/register']);
    }

    /**
     * 임시 조각 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        // 실패 기억은 프로세스 단위다 — 같은 프로세스의 다음 테스트로 새지 않게 비운다.
        FragmentReadCache::flush();

        parent::tearDown();
    }

    /**
     * ⓐ 깨진 조각 하나는 두 템플릿 × 두 경로(템플릿 활성화 재등록 · 갱신)를 돌아도 ERROR 1줄이다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects broken_fragment_is_logged_once_per_process_without_template_tag
     *
     * @return void 반환값 없음
     */
    public function test_broken_fragment_is_logged_once_without_template_tag(): void
    {
        $this->writeBroken('admin-broken-field', 'admin_user_form', 'user_form');
        $this->bindActiveModule();

        Log::spy();

        $this->runAllRegistrationPaths();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'admin-broken-field.g7.html'))
            ->once();

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'admin-broken-field.g7.html')
                    && ! array_key_exists('template', $context)
                    && (($context['source'] ?? $context['module'] ?? null) === $this->moduleId)
                    && preg_match('/\(\d+행 \d+열\)/u', (string) ($context['error'] ?? '')) === 1;
            })
            ->once();
    }

    /**
     * ⓑ 깨진 파일 둘은 각 1줄이고, 고친 파일은 다시 컴파일돼 등록된다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects each_broken_fragment_logs_once_and_fixed_file_recompiles
     *
     * @return void 반환값 없음
     */
    public function test_each_broken_fragment_logs_once_and_fixed_file_recompiles(): void
    {
        $this->writeBroken('admin-broken-one', 'admin_user_form', 'user_form');
        $this->writeBroken('admin-broken-two', 'admin_user_form', 'user_form');
        $this->bindActiveModule();

        Log::spy();

        $service = app(LayoutExtensionService::class);
        $service->registerAllActiveExtensionsToTemplate($this->admin->id);
        $service->registerAllActiveExtensionsToTemplate($this->user->id);

        foreach (['admin-broken-one', 'admin-broken-two'] as $key) {
            Log::shouldHaveReceived('error')
                ->withArgs(fn (string $message): bool => str_contains($message, "{$key}.g7.html"))
                ->once();
        }

        // 파일을 고치면 키(경로+mtime+크기)가 바뀌어 다시 컴파일된다 — 실패 기억이 고친 파일을 가리면 안 된다.
        $this->writeOverlay('admin-broken-one', 'admin_user_form', 'user_form');
        clearstatcache();
        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->admin->id);

        $this->assertSame(
            ['admin-broken-one'],
            LayoutExtension::query()->where('template_id', $this->admin->id)->where('source_identifier', $this->moduleId)->pluck('fragment_key')->all(),
            '고친 조각이 등록되지 않았다 — 실패 기억이 파일 변경을 무시했다'
        );
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'admin-broken-one.g7.html'))
            ->once();
    }

    /**
     * ⓒ `--layout-source=json` 을 이행하지 못한 마크업 전용 조각의 경고는 파일마다 1줄이다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_source_warning_once_per_pass
     *
     * @return void 반환값 없음
     */
    public function test_forced_json_warning_is_logged_once_per_file(): void
    {
        $this->writeOverlay('register-markup-only', 'auth/register', 'register_form');
        $this->bindActiveModule();
        config(['template.layout_markup.install_source' => 'json']);

        Log::spy();

        $this->runAllRegistrationPaths();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'JSON 강제')
                && str_contains((string) ($context['markup'] ?? ''), 'register-markup-only.g7.html'))
            ->once();
    }

    /**
     * ⓓ 붙을 자리가 없는 템플릿을 건너뛴 사실은 info 가 아니라 debug 로 남는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects inapplicable_skip_is_debug_level
     *
     * @return void 반환값 없음
     */
    public function test_inapplicable_skip_is_logged_at_debug_level(): void
    {
        $this->writeOverlay('admin-user-note', 'admin_user_form', 'user_form');
        $this->bindActiveModule();

        Log::spy();

        $stats = app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->user->id);

        $this->assertSame(1, $stats['skipped'], '전제: 관리자 화면 조각은 사용자 템플릿에서 건너뛴다');
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($message): bool => is_string($message) && str_contains($message, '대상 부재')), \Mockery::any()]);
        Log::shouldHaveReceived('debug')
            ->withArgs(fn (string $message): bool => str_contains($message, '대상 부재'))
            ->atLeast()->once();
    }

    /**
     * 조각을 읽는 등록 경로 둘을 두 템플릿에 모두 돌립니다 — 템플릿 활성화 재등록과 확장 갱신.
     *
     * @return void 반환값 없음
     */
    private function runAllRegistrationPaths(): void
    {
        $service = app(LayoutExtensionService::class);
        $service->registerAllActiveExtensionsToTemplate($this->admin->id);
        $service->registerAllActiveExtensionsToTemplate($this->user->id);

        $this->refreshHarness($service)->refresh($this->module(), collect([$this->admin, $this->user]));
    }

    /**
     * 확장 갱신 경로(RefreshesLayoutExtensions)를 관리자와 같은 배선으로 부르는 하네스를 만듭니다.
     *
     * @param  LayoutExtensionService  $service  레이아웃 확장 서비스
     * @return object 하네스
     */
    private function refreshHarness(LayoutExtensionService $service): object
    {
        return new class($service, app(LayoutRowSynchronizer::class))
        {
            use RefreshesLayoutExtensions;
            use SynchronizesLayoutRows;

            /**
             * 관리자와 같은 배선으로 확장 서비스와 행 동기화기를 받습니다.
             *
             * @param  LayoutExtensionService  $layoutExtensionService  레이아웃 확장 서비스
             * @param  LayoutRowSynchronizer  $layoutRowSynchronizer  행 동기화기
             */
            public function __construct(
                protected LayoutExtensionService $layoutExtensionService,
                protected LayoutRowSynchronizer $layoutRowSynchronizer,
            ) {}

            /**
             * 모듈 조각을 갱신 경로로 다시 동기화합니다.
             *
             * @param  ModuleInterface  $module  대상 모듈
             * @param  Collection  $templates  대상 템플릿
             * @return array<string, int> 갱신 통계
             */
            public function refresh(ModuleInterface $module, Collection $templates): array
            {
                return $this->refreshExtensionLayoutExtensions($module, $templates, LayoutSourceType::Module);
            }
        };
    }

    /**
     * 닫히지 않은 overlay 조각을 씁니다.
     *
     * @param  string  $key  조각 식별자
     * @param  string  $target  대상 레이아웃
     * @param  string  $targetId  주입 대상 노드 id
     * @return void 반환값 없음
     */
    private function writeBroken(string $key, string $target, string $targetId): void
    {
        File::put($this->tmp."/module/{$key}.g7.html",
            "<extension target-layout=\"{$target}\" priority=\"100\">\n  <inject target-id=\"{$targetId}\" position=\"append_child\">\n    <div/>\n  </inject>\n");
    }

    /**
     * 정상 overlay 조각을 씁니다.
     *
     * @param  string  $key  조각 식별자
     * @param  string  $target  대상 레이아웃
     * @param  string  $targetId  주입 대상 노드 id
     * @return void 반환값 없음
     */
    private function writeOverlay(string $key, string $target, string $targetId): void
    {
        File::put($this->tmp."/module/{$key}.g7.html",
            "<extension target-layout=\"{$target}\" priority=\"100\">\n  <inject target-id=\"{$targetId}\" position=\"append_child\">\n    <div/>\n  </inject>\n</extension>\n");
    }

    /**
     * 활성 모듈 매니저를 임시 조각 디렉토리로 바인딩합니다.
     *
     * @return void 반환값 없음
     */
    private function bindActiveModule(): void
    {
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn([$this->moduleId => $this->module()]);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 임시 디렉토리를 조각 경로로 갖는 모듈 목을 만듭니다.
     *
     * @return ModuleInterface 모듈 목
     */
    private function module(): ModuleInterface
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn($this->moduleId);
        $module->method('getExtensionsPath')->willReturn($this->tmp.'/module');
        $module->method('getLayoutExtensions')->willReturn([]);

        return $module;
    }
}
