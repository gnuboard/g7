<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use Plugins\Sirsoft\Tosspayments\Listeners\RestoreLayoutExtensionsAfterUpdateListener;
use Plugins\Sirsoft\Tosspayments\Plugin;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 플러그인 업데이트 뒤 레이아웃 확장(오버레이) 복원 리스너 — 구독 계약과 no-op 건너뛰기.
 */
class RestoreLayoutExtensionsAfterUpdateListenerTest extends PluginTestCase
{
    /**
     * 복원은 정규 완료 훅(core.plugins.after_update)을 동기로 구독한다 — 옛 core.plugins.updated 는 deprecated.
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

        $this->assertSame('restoreCurrentExtensionsAfterUpdate', $hooks['core.plugins.after_update']['method'] ?? null);
        $this->assertTrue($hooks['core.plugins.after_update']['sync'] ?? false);
        $this->assertArrayNotHasKey('core.plugins.updated', $hooks);
    }

    /**
     * 플러그인이 리스너를 선언한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects plugin_declares_the_layout_extension_restore_listener
     *
     * @return void 반환값 없음
     */
    public function test_plugin_registers_layout_extension_restore_listener(): void
    {
        $this->assertContains(RestoreLayoutExtensionsAfterUpdateListener::class, (new Plugin)->getHookListeners());
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
            ->fromPlugin('sirsoft-tosspayments')
            ->targeting('admin_ecommerce_order_list')
            ->create(['template_id' => $template->id]);
        $extension->delete();

        (new RestoreLayoutExtensionsAfterUpdateListener)
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-tosspayments', ['success' => false]);

        $this->assertTrue(LayoutExtension::withTrashed()->findOrFail($extension->id)->trashed());
    }
}
