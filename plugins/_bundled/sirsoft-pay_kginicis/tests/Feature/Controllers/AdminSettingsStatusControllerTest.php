<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminSettingsStatusControllerTest extends PluginTestCase
{
    public function test_test_mode_status_returns_current_setting(): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->expects($this->once())
            ->method('get')
            ->with('sirsoft-pay_kginicis', 'is_test_mode', true)
            ->willReturn(true);
        $this->app->instance(PluginSettingsService::class, $settings);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($admin)
            ->getJson('/api/plugins/sirsoft-pay_kginicis/admin/settings/test-mode-status');

        $response->assertOk()
            ->assertJsonPath('data.is_test_mode', true);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('test_mid', $data);
        $this->assertArrayNotHasKey('live_mid', $data);
    }
}
