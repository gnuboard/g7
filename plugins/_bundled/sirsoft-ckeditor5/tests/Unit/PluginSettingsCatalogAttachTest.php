<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use App\Http\Controllers\Api\Admin\PluginSettingsController;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 플러그인 설정 응답 공개 자산 카탈로그 부착 테스트 (공개#100)
 *
 * 설정 스키마가 public_asset_disk 를 선언한 플러그인(ckeditor5)의 설정 GET 응답에
 * available_public_asset_disks 카탈로그가 서버 부착되는지 검증합니다. 화면이 코어
 * 설정 API(core.settings.read)를 교차 조회하면 화면 권한(core.plugins.read)과
 * 표면이 갈려 커스텀 역할에서 카탈로그만 조용히 비므로, 단일 표면 계약을 고정합니다.
 *
 * @effects settings_catalog_includes_plugin_registered_disks
 */
class PluginSettingsCatalogAttachTest extends PluginTestCase
{
    #[Test]
    public function settings_response_attaches_public_asset_catalog(): void
    {
        $response = app(PluginSettingsController::class)->show('sirsoft-ckeditor5');

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true)['data'] ?? [];
        $this->assertArrayHasKey('available_public_asset_disks', $data);

        $ids = array_column($data['available_public_asset_disks'], 'id');
        $this->assertContains('none', $ids);
        $this->assertContains('public', $ids);
        $this->assertContains('s3', $ids);
    }

    #[Test]
    public function catalog_is_not_attached_when_schema_lacks_public_asset_disk(): void
    {
        // 스키마에 public_asset_disk 가 없는 플러그인 시뮬레이션 — 게이트가 닫혀야 한다
        $plugin = $this->createMock(AbstractPlugin::class);
        $plugin->method('getSettingsSchema')->willReturn([
            'some_other_key' => ['type' => 'string'],
        ]);

        $manager = $this->createMock(PluginManager::class);
        $manager->method('getPlugin')->willReturn($plugin);
        $this->app->instance(PluginManager::class, $manager);

        $response = app(PluginSettingsController::class)->show('sirsoft-ckeditor5');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey(
            'available_public_asset_disks',
            $response->getData(true)['data'] ?? []
        );
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(PluginManager::class);

        parent::tearDown();
    }
}
