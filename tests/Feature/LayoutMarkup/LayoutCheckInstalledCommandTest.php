<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `layout:check-installed` — 설치본(DB 행) 전량의 역변환→프로덕션 컴파일→왕복 대조와 조각 등록 정합성 게이트.
 *
 * 다섯 판정을 각 한 케이스로 잠근다: 정상(exit 0) · 컴파일 실패 · 왕복 불일치 · 부적합 등록 · 소실(+고아).
 * `--json` 은 기계 판독용 출력이다.
 */
class LayoutCheckInstalledCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private string $tmp;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 표(`components.json`)가 실재하는 식별자를 쓴다 — 역변환·컴파일이 그 표로 돈다.
        $this->template = Template::factory()->create(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);
        $this->tmp = base_path('storage/framework/testing/check_installed_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
        $this->bindActiveExtensions([]);
    }

    /**
     * 테스트 정리 — 만든 임시 자원을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 왕복이 맞는 설치본 행은 통과하고 JSON 출력도 ok 를 낸다.
     *
     * @scenario compile_unit=command,source_health=valid
     *
     * @effects installed_rows_that_round_trip_pass_with_exit_zero
     *
     * @return void 반환값 없음
     */
    public function test_healthy_rows_pass_and_json_reports_ok(): void
    {
        $this->createLayout('admin_probe', $this->validContent('admin_probe'));

        $this->artisan('layout:check-installed', ['--template' => 'sirsoft-admin_basic'])->assertExitCode(0);

        $this->artisan('layout:check-installed', ['--template' => 'sirsoft-admin_basic', '--json' => true])
            ->expectsOutputToContain('"ok":true')
            ->assertExitCode(0);
    }

    /**
     * 엄격한 검증 실패로 「JSON 조각 참조 누락」 점검을 건너뛴 출처는 0건으로 위장하지 않고 보고한다.
     *
     * 예전에는 `catch (\Throwable)` 이 출력 없이 넘겨, 점검하지 못한 출처가 「누락 0」 으로 읽혔다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects include_check_reports_skipped_sources
     *
     * @return void 반환값 없음
     */
    public function test_sources_skipped_by_strict_validation_are_reported(): void
    {
        Template::factory()->create(['identifier' => 'probe-check-skip', 'type' => 'user', 'status' => ExtensionStatus::Active->value]);
        $dir = base_path('templates/probe-check-skip/layouts');
        File::ensureDirectoryExists($dir);
        // 템플릿 레이아웃에 layout_name 이 없다 — 설치가 거부되는 엄격한 실패.
        File::put($dir.'/broken.json', json_encode(['version' => '1.0.0', 'components' => []]));

        try {
            $this->artisan('layout:check-installed', ['--template' => 'probe-check-skip', '--json' => true])
                ->expectsOutputToContain('"include_skipped":[{"source":"template","identifier":"probe-check-skip"');

            $this->artisan('layout:check-installed', ['--template' => 'probe-check-skip'])
                ->expectsOutputToContain('조각 참조 점검 건너뜀 — template probe-check-skip');
        } finally {
            File::deleteDirectory(base_path('templates/probe-check-skip'));
        }
    }

    /**
     * 옵션 없이 돌리면 번들 여부와 무관하게 설치된 템플릿 전부를 점검한다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects default_target_is_every_installed_template_not_only_bundled
     *
     * @return void 반환값 없음
     */
    public function test_default_target_includes_non_bundled_templates(): void
    {
        // 배포 원본(`_bundled`)에 없는 제3자 템플릿 — 종전 기본 대상에서는 조용히 빠졌다.
        $thirdParty = Template::factory()->create(['identifier' => 'probe-third-party-admin', 'type' => 'admin', 'status' => ExtensionStatus::Inactive->value]);
        TemplateLayout::factory()->create([
            'template_id' => $thirdParty->id,
            'name' => 'probe_bad',
            'content' => $this->validContent('probe_bad') + ['data_sources' => [['id' => 'd', 'endpoint' => '/nope/x']]],
        ]);

        $code = Artisan::call('layout:check-installed');
        $output = Artisan::output();

        $this->assertSame(1, $code, '제3자 템플릿의 거부되는 행이 기본 점검에서 잡혀야 합니다.');
        $this->assertStringContainsString('probe-third-party-admin', $output);
    }

    /**
     * 프로덕션 컴파일이 거부하는 행을 실패로 보고한다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects row_rejected_by_production_compile_fails_the_gate
     *
     * @return void 반환값 없음
     */
    public function test_row_that_fails_production_compile_is_reported(): void
    {
        // 역변환은 되지만 강검증(허용 목록 밖 엔드포인트)이 거부한다 — 편집기에서 "열리는데 저장은 안 되는" 행.
        $this->createLayout('admin_bad', $this->validContent('admin_bad') + ['data_sources' => [['id' => 'd', 'endpoint' => '/nope/x']]]);

        $this->artisan('layout:check-installed', ['--template' => 'sirsoft-admin_basic'])
            ->expectsOutputToContain('fail')
            ->assertExitCode(1);
    }

    /**
     * 역변환 왕복이 원본과 다르면 실패로 보고한다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects round_trip_mismatch_fails_the_gate
     *
     * @return void 반환값 없음
     */
    public function test_round_trip_mismatch_is_reported(): void
    {
        $this->createLayout('admin_drift', $this->validContent('admin_drift'));

        // 되컴파일이 원본과 다른 JSON 을 내는 상황을 변환 서비스 대역으로 만든다 — 역변환기·컴파일러의 어느 층이
        // 어긋나든 커맨드는 정규화 후 deep-equal 로만 판정한다.
        $real = app(LayoutMarkupService::class);
        $stub = $this->getMockBuilder(LayoutMarkupService::class)->onlyMethods(['toJson'])->getMock();
        $stub->method('toJson')->willReturnCallback(function (string $markup, string $templateId, ?string $kind, ?string $id, string $label) use ($real): CompileResult {
            $result = $real->toJson($markup, $templateId, $kind, $id, $label);

            return new CompileResult($result->json + ['modals' => [['type' => 'composite', 'name' => 'Modal', 'props' => ['title' => 'drift']]]], $result->warnings);
        });
        $this->app->instance(LayoutMarkupService::class, $stub);

        $this->artisan('layout:check-installed', ['--template' => 'sirsoft-admin_basic'])
            ->expectsOutputToContain('mismatch')
            ->assertExitCode(1);
    }

    /**
     * 붙을 자리가 없는 확장 행을 보고한다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects inapplicable_registration_fails_the_gate
     *
     * @return void 반환값 없음
     */
    public function test_inapplicable_extension_row_is_reported(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_missing_host',
            'fragment_key' => 'ghost',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-ghost',
            'content' => ['target_layout' => 'admin_missing_host', 'priority' => 100, 'injections' => []],
            'priority' => 100,
            'is_active' => true,
        ]);

        $this->artisan('layout:check-installed', ['--template' => 'sirsoft-admin_basic'])
            ->expectsOutputToContain('inapplicable')
            ->assertExitCode(1);
    }

    /**
     * 빠진 조각과 고아 조각을 보고한다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects missing_and_orphan_fragments_fail_the_gate
     *
     * @return void 반환값 없음
     */
    public function test_missing_and_orphan_fragments_are_reported(): void
    {
        $this->createLayout('admin_user_form', $this->validContent('admin_user_form'));

        // 소스에는 있고 붙을 자리도 있는데 DB 행이 없다 → 소실. DB 행의 키가 소스에 없다 → 고아.
        File::put($this->tmp.'/admin-user-currency-field.g7.html',
            "<extension target-layout=\"admin_user_form\" priority=\"100\">\n  <inject target-id=\"user_form\" position=\"append_child\">\n    <div/>\n  </inject>\n</extension>\n");
        $this->bindActiveExtensions([$this->module('sirsoft-checkmodule', $this->tmp)]);

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_form',
            'fragment_key' => 'admin_user_form',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-checkmodule',
            'content' => ['target_layout' => 'admin_user_form', 'priority' => 100, 'injections' => []],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 한 줄 JSON 이라 `expectsOutputToContain` 을 둘 걸면 두 번째가 별도 출력을 기다린다 — 출력을 받아 직접 본다.
        $code = Artisan::call('layout:check-installed', ['--template' => 'sirsoft-admin_basic', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('"kind":"missing"', $output);
        $this->assertStringContainsString('"fragment_key":"admin-user-currency-field"', $output);
        $this->assertStringContainsString('"kind":"orphan"', $output);
        $this->assertStringContainsString('"fragment_key":"admin_user_form"', $output);
    }

    /**
     * 프로덕션 컴파일을 통과하는 최소 레이아웃 JSON.
     *
     * @param  string  $name  레이아웃 이름
     * @return array<string, 반환값
     */
    private function validContent(string $name): array
    {
        return [
            'layout_name' => $name,
            'version' => '1.0.0',
            'named_actions' => ['probe' => ['comment' => '한 번만 찍히는 주석', 'handler' => 'setState', 'params' => ['target' => 'local', 'a' => 1]]],
            'components' => [['id' => 'user_form', 'type' => 'basic', 'name' => 'Div']],
        ];
    }

    /**
     * 템플릿에 레이아웃 행을 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  array<string, mixed>  $content  레이아웃 JSON
     * @return void 반환값 없음
     */
    private function createLayout(string $name, array $content): void
    {
        TemplateLayout::factory()->create(['template_id' => $this->template->id, 'name' => $name, 'content' => $content]);
    }

    /**
     * 활성 모듈·플러그인 매니저를 대역으로 바인딩합니다.
     *
     * @param  array<int, ModuleInterface>  $modules  활성 모듈 목
     * @return void 반환값 없음
     */
    private function bindActiveExtensions(array $modules): void
    {
        $keyed = [];
        foreach ($modules as $module) {
            $keyed[$module->getIdentifier()] = $module;
        }

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn($keyed);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 임시 디렉토리를 fragment 경로로 갖는 모듈 목을 만듭니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $path  fragment 디렉토리
     * @return ModuleInterface 반환값
     */
    private function module(string $identifier, string $path): ModuleInterface
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn($identifier);
        $module->method('getExtensionsPath')->willReturn($path);
        $module->method('getLayoutExtensions')->willReturn([]);

        return $module;
    }
}
