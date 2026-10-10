<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\RefreshesLayoutExtensions;
use App\Extension\Traits\SynchronizesLayoutRows;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use App\Support\LayoutMarkup\ComponentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 조각 등록 경로의 세 규칙 (#476 C10·C13).
 *
 *  ⓐ 붙을 자리(호스트 레이아웃·확장점)가 없는 템플릿에는 등록하지 않는다 — 사용자 템플릿에 관리자 조각 0행.
 *  ⓑ 같은 확장이 같은 대상에 조각 둘을 보내면 두 행이 산다 (`fragment_key` 가 고유 키에 있다).
 *  ⓒ 조각은 붙을 템플릿의 `components.json` 으로 컴파일된다 — 관리자 전용 컴포넌트를 쓴 조각은 사용자 템플릿 컴파일에서 실패해 등록되지 않는다.
 *  ⓓ 호스트가 나중에 설치돼도 재등록(`registerAllActiveExtensionsToTemplate`)이 그 조각을 메운다. 갱신 경로는 소스에 없는 조각의 행(고아)을 정리한다.
 *
 * 종전에는 세 등록 루프가 모든 활성 템플릿에 무조건 등록했고(사용자 템플릿에 관리자 대상 13행), 5축 고유 키가
 * 같은 대상의 두 번째 조각을 덮었으며(ecommerce 통화 필드 소실), 경로로 표를 골라 사용자 조각도 관리자 표로 검사됐다.
 */
class FragmentRegistrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private string $tmp;

    private Template $admin;

    private Template $user;

    /**
     * 임시 조각 디렉토리와 관리자·사용자 템플릿, 각 템플릿의 호스트 레이아웃을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/fragment_policy_'.uniqid());
        File::ensureDirectoryExists($this->tmp.'/module');

        // 실물 템플릿 식별자를 쓴다 — 조각이 그 템플릿의 components.json 으로 컴파일되는지(ⓒ)를 보려면 표가 실재해야 한다.
        $this->admin = Template::factory()->create(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);
        $this->user = Template::factory()->create(['identifier' => 'sirsoft-basic', 'type' => 'user', 'status' => ExtensionStatus::Active->value]);

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

        parent::tearDown();
    }

    /**
     * ⓐ 관리자 화면을 겨냥한 조각은 사용자 템플릿에 등록되지 않는다 (사용자 화면 조각은 그 반대).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_is_registered_only_where_its_host_exists
     *
     * @return void 반환값 없음
     */
    public function test_fragment_is_registered_only_to_templates_that_have_its_host(): void
    {
        $this->writeOverlay('admin-user-currency-field', 'admin_user_form', 'user_form');
        $this->writeOverlay('register-currency-field', 'auth/register', 'register_form');
        $this->bindActiveModule();

        $service = app(LayoutExtensionService::class);
        $adminStats = $service->registerAllActiveExtensionsToTemplate($this->admin->id);
        $userStats = $service->registerAllActiveExtensionsToTemplate($this->user->id);

        $this->assertSame(1, $adminStats['modules']);
        $this->assertSame(1, $adminStats['skipped'], '사용자 화면 조각은 관리자 템플릿에서 건너뛴다');
        $this->assertSame(1, $userStats['modules']);
        $this->assertSame(1, $userStats['skipped'], '관리자 화면 조각은 사용자 템플릿에서 건너뛴다');

        $this->assertSame(['admin_user_form'], LayoutExtension::where('template_id', $this->admin->id)->pluck('target_name')->all());
        $this->assertSame(['auth/register'], LayoutExtension::where('template_id', $this->user->id)->pluck('target_name')->all());
    }

    /**
     * ⓑ 같은 확장·같은 대상의 조각 둘은 두 행이다 — `fragment_key` 가 가른다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects two_fragments_for_the_same_target_keep_two_rows
     *
     * @return void 반환값 없음
     */
    public function test_two_fragments_for_the_same_target_keep_two_rows(): void
    {
        $this->writeOverlay('admin-user-currency-field', 'admin_user_form', 'user_form');
        $this->writeOverlay('admin-user-shipping-country-field', 'admin_user_form', 'user_form');
        $this->bindActiveModule();

        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->admin->id);

        $rows = LayoutExtension::where('template_id', $this->admin->id)->where('target_name', 'admin_user_form')->orderBy('fragment_key')->get();

        $this->assertCount(2, $rows, '종전 5축 키는 뒤 조각이 앞 조각을 덮었다');
        $this->assertSame(['admin-user-currency-field', 'admin-user-shipping-country-field'], $rows->pluck('fragment_key')->all());
    }

    /**
     * ⓒ 조각 컴파일은 붙을 템플릿과 무관하다 — 어느 템플릿의 컴포넌트 목록에 있든 없든 같은 결과로 등록된다.
     *
     * 종전(C10)에는 붙을 템플릿의 컴포넌트 목록으로 컴파일해, 그 템플릿에 없는 컴포넌트를 쓴 조각을 등록하지
     * 않았다. 그 규칙이 템플릿 교체·요청마다 도는 리스너·업데이트 복원 경로를 템플릿에 묶었다(합의 없는
     * 중대 설계 결함). 2026-09-28 결정으로 HTML 컴파일은 JSON 과 같이 템플릿을 보지 않는다 — 컴포넌트가
     * 실제로 있는지는 런타임(활성 템플릿)이 판정한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_compile_does_not_depend_on_the_target_template
     *
     * @return void 반환값 없음
     */
    public function test_fragment_using_a_component_absent_from_the_target_template_still_registers(): void
    {
        $adminOnly = $this->adminOnlyComponentTag();

        File::put($this->tmp.'/module/register-admin-widget.g7.html',
            "<extension target-layout=\"auth/register\" priority=\"100\">\n  <inject target-id=\"register_form\" position=\"append_child\">\n    <{$adminOnly}/>\n  </inject>\n</extension>\n");
        $this->bindActiveModule();

        $stats = app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->user->id);

        $this->assertSame(1, $stats['modules'], "사용자 템플릿에 없는 <{$adminOnly}> 를 써도 컴파일은 템플릿과 무관하게 선다");
        $this->assertSame(1, LayoutExtension::where('template_id', $this->user->id)->count());
    }

    /**
     * ⓓ 호스트가 나중에 생겨도 재등록이 조각을 메우고, 갱신 경로는 소스에서 사라진 조각의 행을 정리한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects late_host_is_filled_by_reregistration_and_orphans_are_removed
     *
     * @return void 반환값 없음
     */
    public function test_late_host_is_filled_on_reregistration_and_orphan_rows_are_removed(): void
    {
        $this->writeOverlay('admin-user-mileage-tab', 'admin_user_detail', 'user_detail');
        $this->bindActiveModule();

        $service = app(LayoutExtensionService::class);

        // 호스트(admin_user_detail)가 아직 없다 → 건너뜀.
        $stats = $service->registerAllActiveExtensionsToTemplate($this->admin->id);
        $this->assertSame(0, $stats['modules']);
        $this->assertSame(1, $stats['skipped']);

        // 호스트가 나중에 설치된다 → 재등록이 메운다 (확장 활성화·업데이트가 이 호출을 전 템플릿에 반복한다).
        TemplateLayout::factory()->create(['template_id' => $this->admin->id, 'name' => 'admin_user_detail']);
        $stats = $service->registerAllActiveExtensionsToTemplate($this->admin->id);
        $this->assertSame(1, $stats['modules']);
        $this->assertSame('admin-user-mileage-tab', LayoutExtension::where('template_id', $this->admin->id)->firstOrFail()->fragment_key);

        // 소스에서 조각이 사라지면 갱신 경로가 그 행을 정리한다 (고아).
        File::delete($this->tmp.'/module/admin-user-mileage-tab.g7.html');
        $this->writeOverlay('admin-user-note-tab', 'admin_user_detail', 'user_detail');

        // 갱신 경로는 행 쓰기를 동기화기에 맡긴다(#727) — 관리자와 같이 생성자로 받는다.
        $harness = new class($service, app(LayoutRowSynchronizer::class))
        {
            use RefreshesLayoutExtensions;
            use SynchronizesLayoutRows;

            /**
             * 관리자와 같은 배선으로 확장 서비스와 행 동기화기를 받습니다.
             *
             * @param  LayoutExtensionService  $layoutExtensionService  레이아웃 확장 서비스
             * @param  LayoutRowSynchronizer  $layoutRowSynchronizer  레이아웃·확장 조각 행 동기화기
             */
            public function __construct(
                protected LayoutExtensionService $layoutExtensionService,
                protected LayoutRowSynchronizer $layoutRowSynchronizer,
            ) {}

            /**
             * 모듈의 레이아웃 확장을 갱신 경로로 다시 동기화합니다.
             *
             * @param  ModuleInterface  $module  대상 모듈
             * @param  IlluminateSupportCollection  $templates  관리자 템플릿 컬렉션
             * @return array{refreshed: int, created: int, updated: int, deleted: int, skipped: int} 갱신 통계
             */
            public function refresh(ModuleInterface $module, $templates): array
            {
                return $this->refreshExtensionLayoutExtensions($module, $templates, LayoutSourceType::Module);
            }
        };

        $refreshStats = $harness->refresh($this->module(), collect([$this->admin]));

        $this->assertSame(1, $refreshStats['created']);
        $this->assertSame(1, $refreshStats['deleted'], '소스에 없는 조각의 행은 soft delete 된다');
        $this->assertSame(['admin-user-note-tab'], LayoutExtension::where('template_id', $this->admin->id)->pluck('fragment_key')->all());
    }

    /**
     * 사용자 템플릿에는 없고 관리자 템플릿에만 있는 컴포넌트 태그를 고릅니다.
     *
     * @return string kebab 태그
     */
    private function adminOnlyComponentTag(): string
    {
        $admin = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
        $user = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-basic/components.json'));

        foreach ($admin->tags() as $tag) {
            if ($user->resolveTag($tag) === null) {
                return $tag;
            }
        }

        $this->fail('관리자 전용 컴포넌트 태그를 찾지 못했습니다 — 두 템플릿의 components.json 이 같아졌다면 이 테스트의 전제를 다시 봐야 합니다.');
    }

    /**
     * overlay 조각 소스를 씁니다.
     *
     * @param  string  $key  조각 식별자 (파일 기본 이름)
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
     * 활성 모듈 매니저를 임시 fragment 디렉토리로 바인딩합니다.
     *
     * @return void 반환값 없음
     */
    private function bindActiveModule(): void
    {
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn(['sirsoft-policymodule' => $this->module()]);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 임시 디렉토리를 fragment 경로로 갖는 모듈 목을 만듭니다.
     *
     * @return ModuleInterface 모듈 목
     */
    private function module(): ModuleInterface
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn('sirsoft-policymodule');
        $module->method('getExtensionsPath')->willReturn($this->tmp.'/module');
        $module->method('getLayoutExtensions')->willReturn([]);

        return $module;
    }
}
