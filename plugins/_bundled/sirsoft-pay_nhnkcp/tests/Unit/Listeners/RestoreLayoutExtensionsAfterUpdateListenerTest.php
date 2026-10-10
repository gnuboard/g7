<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use Plugins\Sirsoft\PayNhnkcp\Listeners\RestoreLayoutExtensionsAfterUpdateListener;
use Plugins\Sirsoft\PayNhnkcp\Plugin;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class RestoreLayoutExtensionsAfterUpdateListenerTest extends PluginTestCase
{
    /**
     * 복원은 **동기** 리스너여야 한다 — 큐로 미루면 업데이트 직후 화면이 조각 없는 상태로 먼저 뜬다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects restore_listener_subscribes_to_plugin_updated_as_sync_action
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_to_plugin_updated_as_sync_action(): void
    {
        $hooks = RestoreLayoutExtensionsAfterUpdateListener::getSubscribedHooks();

        $this->assertSame('action', $hooks['core.plugins.after_update']['type'] ?? null);
        $this->assertSame('restoreCurrentExtensionsAfterUpdate', $hooks['core.plugins.after_update']['method'] ?? null);
        $this->assertTrue($hooks['core.plugins.after_update']['sync'] ?? false);
    }

    /**
     * 플러그인이 리스너를 선언하지 않으면 이벤트가 발화해도 아무도 듣지 않아 조각이 조용히 사라진다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects plugin_declares_the_layout_extension_restore_listener
     *
     * @return void 반환값 없음
     */
    public function test_plugin_registers_layout_extension_restore_listener(): void
    {
        $this->assertContains(
            RestoreLayoutExtensionsAfterUpdateListener::class,
            (new Plugin)->getHookListeners()
        );
    }

    /**
     * 업데이트로 소프트 삭제된 자기 overlay 확장이 프리픽스 모듈 레이아웃에 다시 등록된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects overlay_extensions_are_restored_after_plugin_update
     *
     * @return void 반환값 없음
     */
    public function test_restores_soft_deleted_current_extension_for_prefixed_module_layout(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => 'active',
        ]);

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'sirsoft-ecommerce.admin_ecommerce_order_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'layout_name' => 'admin_ecommerce_order_detail',
                'components' => [],
                'data_sources' => [],
            ],
        ]);

        $extension = LayoutExtension::factory()
            ->overlay()
            ->fromPlugin('sirsoft-pay_nhnkcp')
            ->targeting('admin_ecommerce_order_detail')
            ->create([
                'template_id' => $template->id,
                // 조각 식별자는 **파일 이름**이다(#476 C13) — 코어 설치 경로가 그렇게 쓰고, 업데이트
                // 복원도 같은 키로 자기 행을 찾는다. 팩토리 기본값(무작위)으로 두면 복원이 같은 행을
                // 못 찾아 새 행을 만든다.
                'fragment_key' => 'admin_order_payment_query',
                'content' => [
                    'target_layout' => 'admin_ecommerce_order_detail',
                    'data_sources' => [],
                    'injections' => [],
                ],
                'is_active' => true,
            ]);
        $extension->delete();

        (new RestoreLayoutExtensionsAfterUpdateListener)
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nhnkcp');

        $restored = LayoutExtension::withTrashed()->findOrFail($extension->id);

        $this->assertFalse($restored->trashed());
        $this->assertTrue($restored->is_active);
        $this->assertSame(LayoutExtensionType::Overlay, $restored->extension_type);
        $this->assertSame(LayoutSourceType::Plugin, $restored->source_type);
        $this->assertSame('sirsoft-pay_nhnkcp', $restored->source_identifier);
        $this->assertSame('admin_ecommerce_order_detail', $restored->target_name);
        $this->assertSame('admin_order_payment_query', $restored->fragment_key,
            '복원은 파일에서 얻은 조각 키로 자기 행을 찾는다 — 키 없이 부르면 같은 대상에 행이 둘일 때 아무것도 하지 않는다');
        $this->assertSame(
            1,
            LayoutExtension::withTrashed()
                ->where('source_identifier', 'sirsoft-pay_nhnkcp')
                ->where('target_name', 'admin_ecommerce_order_detail')
                ->count(),
            '기존 행을 되살릴 뿐 새 행을 만들지 않는다',
        );

        $json = json_encode($restored->content, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringContainsString('kcp_status', $json);
    }

    /**
     * 행이 하나도 없는 상태에서 복원하면 **파일 이름**을 조각 식별자로 새 행을 만든다 (#476 C13).
     *
     * 키 없이 부르면 호환 경로가 대상 이름(`admin_ecommerce_order_detail`)을 키로 삼는데, 코어 설치
     * 경로는 파일 이름(`admin_order_payment_query`)을 쓴다. 둘이 갈리면 다음 재등록이 같은 대상에
     * **두 번째 행**을 만들고, 그 뒤로는 키 없는 호출이 영구히 모호해져 아무것도 하지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects restored_overlay_uses_the_file_derived_fragment_key
     *
     * @return void 반환값 없음
     */
    public function test_restore_creates_row_with_file_derived_fragment_key(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => 'active',
        ]);

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'sirsoft-ecommerce.admin_ecommerce_order_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'layout_name' => 'admin_ecommerce_order_detail',
                'components' => [],
                'data_sources' => [],
            ],
        ]);

        (new RestoreLayoutExtensionsAfterUpdateListener)
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nhnkcp');

        $created = LayoutExtension::withTrashed()
            ->where('source_identifier', 'sirsoft-pay_nhnkcp')
            ->where('target_name', 'admin_ecommerce_order_detail')
            ->get();

        $this->assertCount(1, $created, '대상당 행은 하나다');
        $this->assertSame('admin_order_payment_query', $created->first()->fragment_key,
            '조각 식별자는 대상 이름이 아니라 파일 이름이다 — 코어 설치 경로와 같은 키여야 다음 재등록이 행을 덧내지 않는다');
    }

    /**
     * 다른 플러그인의 업데이트에 반응하면 남의 조각을 자기 것으로 덮어쓴다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects restore_listener_ignores_other_plugins_updates
     *
     * @return void 반환값 없음
     */
    public function test_ignores_other_plugins(): void
    {
        $template = Template::factory()->create(['type' => 'admin']);

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'sirsoft-ecommerce.admin_ecommerce_order_detail',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => ['layout_name' => 'admin_ecommerce_order_detail'],
        ]);

        $extension = LayoutExtension::factory()
            ->overlay()
            ->fromPlugin('sirsoft-pay_nhnkcp')
            ->targeting('admin_ecommerce_order_detail')
            ->create(['template_id' => $template->id]);
        $extension->delete();

        (new RestoreLayoutExtensionsAfterUpdateListener)
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nicepayments');

        $this->assertTrue(LayoutExtension::withTrashed()->findOrFail($extension->id)->trashed());
    }

    /**
     * 업데이트할 것이 없던 호출(success=false)에는 복원하지 않는다 — 정규 완료 훅은 그때도 발화한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects restore_listener_skips_when_update_had_nothing_to_apply
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_update_had_nothing_to_apply(): void
    {
        $template = Template::factory()->create(['type' => 'admin']);

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'sirsoft-ecommerce.admin_ecommerce_order_list',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => ['layout_name' => 'admin_ecommerce_order_list'],
        ]);

        $extension = LayoutExtension::factory()
            ->overlay()
            ->fromPlugin('sirsoft-pay_nhnkcp')
            ->targeting('admin_ecommerce_order_list')
            ->create(['template_id' => $template->id]);
        $extension->delete();

        (new RestoreLayoutExtensionsAfterUpdateListener)
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nhnkcp', ['success' => false]);

        $this->assertTrue(LayoutExtension::withTrashed()->findOrFail($extension->id)->trashed());
    }

    /**
     * 확장점 조각도 업데이트 뒤 되살아난다 — 관리자 주문 목록 결제수단 칸의 테스트 결제 배지.
     *
     * 배지는 overlay 가 아니라 이커머스가 열어 둔 확장점(`admin_order_list_payment_method_badges`)에
     * 붙는다. 복원이 overlay 만 보면 업데이트가 비활성으로 돌려 놓은 배지 행이 그대로 남아 목록에서
     * 테스트 결제 표시가 사라진다.
     *
     * @scenario payment_plugin=nhnkcp
     *
     * @effects extension_point_fragment_is_restored_after_plugin_update
     *
     * @return void 없음
     */
    public function test_restores_soft_deleted_extension_point_fragment(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => 'active',
        ]);

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'sirsoft-ecommerce.admin_ecommerce_order_list',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'layout_name' => 'admin_ecommerce_order_list',
                'components' => [
                    ['type' => 'extension_point', 'name' => 'admin_order_list_payment_method_badges'],
                ],
                'data_sources' => [],
            ],
        ]);

        $extension = LayoutExtension::factory()
            ->extensionPoint()
            ->fromPlugin('sirsoft-pay_nhnkcp')
            ->targeting('admin_order_list_payment_method_badges')
            ->create([
                'template_id' => $template->id,
                'fragment_key' => 'admin_order_list_payment_method_badge',
                'content' => [
                    'extension_point' => 'admin_order_list_payment_method_badges',
                    'components' => [],
                ],
                'is_active' => true,
            ]);
        $extension->delete();

        (new RestoreLayoutExtensionsAfterUpdateListener)
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nhnkcp');

        $restored = LayoutExtension::withTrashed()->findOrFail($extension->id);

        $this->assertFalse($restored->trashed());
        $this->assertSame(LayoutExtensionType::ExtensionPoint, $restored->extension_type);
        $this->assertSame('admin_order_list_payment_method_badges', $restored->target_name);
        $this->assertSame('admin_order_list_payment_method_badge', $restored->fragment_key);

        $json = json_encode($restored->content, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringContainsString('nhnkcp_test_map.data?.[row.order_number] === true', $json,
            '복원된 조각은 파일의 현재 정의(자기 테스트 결제 맵만 읽는 배지)여야 한다');
    }
}
