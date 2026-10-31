<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 결제 플러그인 업데이트 뒤 조각 재등록은 코어 절차 하나로 수렴한다 (#725 W6 · 결정 D3).
 *
 * 결제 플러그인 4종은 업데이트 완료 훅에 자기 조각을 다시 등록하는 리스너를 따로 두었다. 코어 업데이트 경로가 이미
 * 같은 일을 하는데(활성 템플릿 재등록), 그 리스너는 **꺼진 템플릿까지**(`getAll()`) 행을 만들었고 플러그인이 꺼진 채
 * 업데이트돼도 돌았다. 그 행은 꺼진 템플릿을 켤 때 섞여 나왔다.
 *
 *  ⓐ 업데이트 경로(코어 재등록 + 업데이트 완료 훅 구독자 전부)를 돌려도 꺼진 템플릿에는 행이 없고, 활성 템플릿에는
 *     붙을 자리가 있는 조각만 있다.
 *  ⓑ 옛 리스너가 꺼진 템플릿에 남긴 형태의 행(오등록·고아)은 그 템플릿을 켤 때 정리된다 — 업그레이드 스텝 없이
 *     수렴하는 근거(계획서 A7)를 잠근다.
 */
class PaymentPluginUpdateFragmentConvergenceTest extends TestCase
{
    use DatabaseTransactions;

    private const PLUGIN = 'sirsoft-pay_kginicis';

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-pay_kginicis',
    ];

    private PluginInterface $plugin;

    private Template $admin;

    private Template $user;

    private Template $inactiveUser;

    /**
     * 활성 관리자·사용자 템플릿과 꺼진 사용자 템플릿(각 호스트 화면 포함), 결제 플러그인 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $plugin = app(PluginManager::class)->getPlugin(self::PLUGIN);
        $this->assertInstanceOf(PluginInterface::class, $plugin, '전제: 결제 플러그인이 로드돼야 한다');
        $this->plugin = $plugin;

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        Template::query()->update(['status' => ExtensionStatus::Inactive->value]);
        $this->admin = Template::factory()->create(['identifier' => 'probe-payadmin-'.$suffix, 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);
        $this->user = Template::factory()->create(['identifier' => 'probe-payuser-'.$suffix, 'type' => 'user', 'status' => ExtensionStatus::Active->value]);
        $this->inactiveUser = Template::factory()->create(['identifier' => 'probe-payoff-'.$suffix, 'type' => 'user', 'status' => ExtensionStatus::Inactive->value]);

        TemplateLayout::factory()->create(['template_id' => $this->admin->id, 'name' => 'admin_ecommerce_order_detail']);
        // 주문 목록 결제수단 칸 확장점 — 목록 배지 조각(확장점 조각)이 업데이트 뒤에도 다시 등록되는지 본다.
        TemplateLayout::factory()->create(['template_id' => $this->admin->id, 'name' => 'admin_ecommerce_order_list', 'content' => [
            'layout_name' => 'admin_ecommerce_order_list',
            'components' => [['id' => 'badge_cell', 'type' => 'basic', 'name' => 'Div', 'children' => [['type' => 'extension_point', 'name' => 'admin_order_list_payment_method_badges']]]],
        ]]);
        TemplateLayout::factory()->create(['template_id' => $this->user->id, 'name' => 'shop/checkout']);
        TemplateLayout::factory()->create(['template_id' => $this->user->id, 'name' => 'shop/order_complete']);
        TemplateLayout::factory()->create(['template_id' => $this->inactiveUser->id, 'name' => 'shop/checkout']);

        LayoutExtension::withTrashed()->where('source_identifier', self::PLUGIN)
            ->whereIn('template_id', [$this->admin->id, $this->user->id, $this->inactiveUser->id])->forceDelete();

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn([]);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([self::PLUGIN => $this->plugin]);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * ⓐ 업데이트 경로 전체를 돌려도 꺼진 템플릿에는 행이 생기지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects payment_plugin_update_registers_only_to_active_hosts, extension_point_fragment_is_restored_after_plugin_update
     *
     * @return void 반환값 없음
     */
    public function test_update_path_registers_only_to_active_templates_with_host(): void
    {
        $this->runUpdatePath();

        $this->assertSame([], $this->liveKeys($this->inactiveUser), '업데이트가 꺼진 템플릿에 조각 행을 만들었다');
        $this->assertSame(['admin_order_list_payment_method_badge', 'admin_order_payment_query'], $this->liveKeys($this->admin), '확장점 조각(목록 배지)과 overlay 조각이 함께 다시 등록돼야 한다');
        $this->assertSame(['checkout_payment_error', 'user_order_complete_receipt'], $this->liveKeys($this->user));
    }

    /**
     * ⓑ 꺼진 템플릿에 남은 옛 행(오등록·고아)은 그 템플릿을 켤 때 정리되고 붙을 자리가 있는 조각은 등록된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects stale_rows_on_inactive_template_converge_on_activation
     *
     * @return void 반환값 없음
     */
    public function test_stale_rows_on_inactive_template_converge_on_activation(): void
    {
        $service = app(LayoutExtensionService::class);
        $service->registerAllActiveExtensionsToTemplate($this->admin->id);

        $misregistered = $this->staleRow('admin_order_payment_query', LayoutExtensionType::Overlay, 'admin_ecommerce_order_detail');
        $orphan = $this->staleRow('legacy_removed_fragment', LayoutExtensionType::Overlay, 'shop/checkout');

        $this->inactiveUser->update(['status' => ExtensionStatus::Active->value]);
        $service->registerAllActiveExtensionsToTemplate($this->inactiveUser->id);

        $this->assertSoftDeleted($misregistered, [], null, '붙을 자리 없는 옛 행이 켠 뒤에도 살아 있다');
        $this->assertSoftDeleted($orphan, [], null, '소스에 없는 옛 행이 켠 뒤에도 살아 있다');
        $this->assertSame(['checkout_payment_error'], $this->liveKeys($this->inactiveUser));
    }

    /**
     * 코어 업데이트 경로를 흉내 냅니다 — 활성 템플릿 전부 재등록 뒤 업데이트 완료 훅의 플러그인 구독자를 부릅니다.
     *
     * @return void 반환값 없음
     */
    private function runUpdatePath(): void
    {
        $service = app(LayoutExtensionService::class);
        foreach ([$this->admin, $this->user] as $template) {
            $service->registerAllActiveExtensionsToTemplate($template->id);
        }

        foreach ($this->plugin->getHookListeners() as $listenerClass) {
            $hook = $listenerClass::getSubscribedHooks()['core.plugins.after_update'] ?? null;

            if ($hook !== null) {
                app($listenerClass)->{$hook['method'] ?? 'handle'}(self::PLUGIN, ['success' => true]);
            }
        }
    }

    /**
     * 꺼진 사용자 템플릿에 옛 리스너가 남긴 형태의 행을 만듭니다.
     *
     * @param  string  $key  조각 식별자
     * @param  LayoutExtensionType  $type  확장 타입
     * @param  string  $target  대상 이름
     * @return LayoutExtension 만든 행
     */
    private function staleRow(string $key, LayoutExtensionType $type, string $target): LayoutExtension
    {
        return LayoutExtension::create([
            'template_id' => $this->inactiveUser->id,
            'extension_type' => $type,
            'target_name' => $target,
            'fragment_key' => $key,
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => self::PLUGIN,
            'content' => ['target_layout' => $target, 'priority' => 100, 'injections' => []],
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * 템플릿의 살아 있는 결제 플러그인 조각 키를 모읍니다.
     *
     * @param  Template  $template  템플릿
     * @return array<int, string> 조각 키 (정렬)
     */
    private function liveKeys(Template $template): array
    {
        return LayoutExtension::query()
            ->where('template_id', $template->id)
            ->where('source_identifier', self::PLUGIN)
            ->orderBy('fragment_key')
            ->pluck('fragment_key')
            ->all();
    }
}
