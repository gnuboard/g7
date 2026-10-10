<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 템플릿 활성화가 호스트 레이아웃을 먼저 등록하고 조각을 그 뒤에 등록하는지 (#712 W4 · F4).
 *
 * 조각 등록은 붙을 자리(호스트 레이아웃·확장점)가 이 템플릿에 실재할 때만 행을 만든다(C10 적용 필터). 그런데
 * 활성화가 조각을 먼저 등록하고 모듈·플러그인 레이아웃을 뒤에 등록해, 관리자 템플릿을 새로 켜면 모듈 화면에 붙는
 * 조각이 통째로 빠졌다(실측: 38 → 27행) — 다른 확장을 다시 켤 때까지 결제 조회·주문 상세 같은 조각이 보이지 않았다.
 *
 * 자기 레이아웃 갱신(`refreshTemplateLayouts`)은 이 순서와 무관하고 무거워(관리자 템플릿 전량 컴파일) 대역으로 둔다.
 */
class TemplateActivationFragmentOrderTest extends TestCase
{
    use DatabaseTransactions;

    private const MODULE = 'sirsoft-orderprobe';

    private const TEMPLATE = 'sirsoft-admin_basic';

    private string $tmp;

    private Template $template;

    /**
     * 임시 조각 디렉토리와 비활성 관리자 템플릿 행을 준비합니다 (호스트 레이아웃 없음).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/activation_order_'.uniqid());
        File::ensureDirectoryExists($this->tmp.'/module');
        File::put($this->tmp.'/module/admin-order-probe.g7.html',
            "<extension target-layout=\"orderprobe_host\" priority=\"100\">\n  <inject target-id=\"probe_anchor\" position=\"append_child\">\n    <div/>\n  </inject>\n</extension>\n");

        $existing = Template::query()->where('identifier', self::TEMPLATE)->first();
        $this->template = $existing ?? Template::factory()->create(['identifier' => self::TEMPLATE, 'type' => 'admin']);
        $this->template->update(['status' => ExtensionStatus::Inactive->value]);

        TemplateLayout::query()->where('template_id', $this->template->id)->where('name', 'orderprobe_host')->forceDelete();
        LayoutExtension::withTrashed()->where('source_identifier', self::MODULE)->forceDelete();
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 호스트 레이아웃을 모듈 레이아웃 등록이 만드는 템플릿을 켜면, 그 호스트에 붙는 조각도 같은 활성화에서 등록된다.
     *
     * @scenario case=template_activation_order
     *
     * @effects module_fragment_is_registered_on_fresh_template_activation
     *
     * @return void 반환값 없음
     */
    public function test_fragment_is_registered_when_host_arrives_in_the_same_activation(): void
    {
        $templateId = $this->template->id;

        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn(self::MODULE);
        $module->method('getExtensionsPath')->willReturn($this->tmp.'/module');
        $module->method('getLayoutExtensions')->willReturn([]);

        // 모듈 레이아웃 등록이 이 템플릿에 호스트 레이아웃을 만든다 (실제 registerModuleLayouts 와 같은 효과).
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn([self::MODULE => $module]);
        $moduleManager->method('registerLayoutsForAllActiveModules')->willReturnCallback(function () use ($templateId): int {
            TemplateLayout::factory()->create(['template_id' => $templateId, 'name' => 'orderprobe_host']);

            return 1;
        });
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $this->app->instance(PluginManager::class, $pluginManager);

        $manager = $this->templateManagerWithoutSelfRefresh();
        $manager->loadTemplates();

        $result = $manager->activateTemplate(self::TEMPLATE, true);

        $this->assertTrue($result['success']);
        $this->assertTrue(
            TemplateLayout::query()->where('template_id', $templateId)->where('name', 'orderprobe_host')->exists(),
            '전제: 모듈 레이아웃 등록이 호스트를 만들었다'
        );
        $this->assertSame(
            ['admin-order-probe'],
            LayoutExtension::query()->where('template_id', $templateId)->where('source_identifier', self::MODULE)->pluck('fragment_key')->all(),
            '같은 활성화에서 호스트가 생겼는데 조각이 빠졌다 — 조각 등록이 호스트 등록보다 먼저 돌았다'
        );
    }

    /**
     * 자기 레이아웃 갱신·캐시 굽기만 대역으로 바꾼 템플릿 매니저를 만듭니다.
     *
     * @return TemplateManager 부분 대역
     */
    private function templateManagerWithoutSelfRefresh(): TemplateManager
    {
        $constructor = (new \ReflectionClass(TemplateManager::class))->getConstructor();
        $args = array_map(
            fn (\ReflectionParameter $parameter) => $this->app->make($parameter->getType()->getName()),
            $constructor->getParameters()
        );

        /** @var TemplateManager&MockInterface $manager */
        $manager = Mockery::mock(TemplateManager::class, $args)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('refreshTemplateLayouts')->andReturn(['success' => true, 'layout_sync' => [], 'layout_warnings' => []]);
        $manager->shouldReceive('warmTemplateCache')->andReturnNull();

        return $manager;
    }
}
