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
    public function test_subscribes_to_plugin_updated_as_sync_action(): void
    {
        $hooks = RestoreLayoutExtensionsAfterUpdateListener::getSubscribedHooks();

        $this->assertSame('action', $hooks['core.plugins.updated']['type'] ?? null);
        $this->assertSame('restoreCurrentExtensionsAfterUpdate', $hooks['core.plugins.updated']['method'] ?? null);
        $this->assertTrue($hooks['core.plugins.updated']['sync'] ?? false);
    }

    public function test_plugin_registers_layout_extension_restore_listener(): void
    {
        $this->assertContains(
            RestoreLayoutExtensionsAfterUpdateListener::class,
            (new Plugin())->getHookListeners()
        );
    }

    public function test_restores_soft_deleted_current_extension_for_prefixed_module_layout(): void
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
                'components' => [],
                'data_sources' => [],
            ],
        ]);

        $extension = LayoutExtension::factory()
            ->overlay()
            ->fromPlugin('sirsoft-pay_nhnkcp')
            ->targeting('admin_ecommerce_order_list')
            ->create([
                'template_id' => $template->id,
                'content' => [
                    'target_layout' => 'admin_ecommerce_order_list',
                    'data_sources' => [],
                    'injections' => [],
                ],
                'is_active' => true,
            ]);
        $extension->delete();

        (new RestoreLayoutExtensionsAfterUpdateListener())
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nhnkcp');

        $restored = LayoutExtension::withTrashed()->findOrFail($extension->id);

        $this->assertFalse($restored->trashed());
        $this->assertTrue($restored->is_active);
        $this->assertSame(LayoutExtensionType::Overlay, $restored->extension_type);
        $this->assertSame(LayoutSourceType::Plugin, $restored->source_type);
        $this->assertSame('sirsoft-pay_nhnkcp', $restored->source_identifier);
        $this->assertSame('admin_ecommerce_order_list', $restored->target_name);

        $json = json_encode($restored->content, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringContainsString('nhnkcp_test_map', $json);
    }

    public function test_ignores_other_plugins(): void
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

        (new RestoreLayoutExtensionsAfterUpdateListener())
            ->restoreCurrentExtensionsAfterUpdate('sirsoft-pay_nicepayments');

        $this->assertTrue(LayoutExtension::withTrashed()->findOrFail($extension->id)->trashed());
    }
}
