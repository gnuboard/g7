<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * Ckeditor5ImageUpload download_url(직접 URL/폴백) 단위 테스트
 *
 * file_path 의 첫 세그먼트=카테고리 일반형 분해와 행 storage_disk 기준
 * 직접 URL/스트리밍 폴백을 검증합니다.
 */
class Ckeditor5ImageUploadUrlTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        Ckeditor5ImageUpload::flushStorageCache();
    }

    protected function tearDown(): void
    {
        Ckeditor5ImageUpload::flushStorageCache();
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * @scenario consumer=editor, disk_setting=public, e2e=ecommerce_settings_field, hook=unregistered, override=follow_core, row_state=legacy_local_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
     */
    #[Test]
    public function it_falls_back_to_api_url_for_plugins_disk_row(): void
    {
        $image = new Ckeditor5ImageUpload([
            'hash' => 'aaaabbbbcccc',
            'file_path' => 'images/2026/01/01/a.jpg',
            'storage_disk' => 'plugins',
        ]);

        $this->assertSame(
            '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc',
            $image->download_url
        );
    }

    #[Test]
    public function it_returns_direct_url_for_cdn_disk_row(): void
    {
        $image = new Ckeditor5ImageUpload([
            'hash' => 'aaaabbbbcccc',
            'file_path' => 'images/2026/01/01/a.jpg',
            'storage_disk' => 'fake_cdn',
        ]);

        $this->assertSame(
            'https://cdn.test/assets/sirsoft-ckeditor5/images/2026/01/01/a.jpg',
            $image->download_url
        );
    }

    #[Test]
    public function it_decomposes_non_images_prefix_generically(): void
    {
        // ApiDocSampleService 등 외부 생성 행 — 첫 세그먼트=카테고리 일반형
        $image = new Ckeditor5ImageUpload([
            'hash' => 'aaaabbbbcccc',
            'file_path' => 'ckeditor5/apidoc-sample.png',
            'storage_disk' => 'fake_cdn',
        ]);

        $this->assertSame(
            'https://cdn.test/assets/sirsoft-ckeditor5/ckeditor5/apidoc-sample.png',
            $image->download_url
        );
    }

    #[Test]
    public function it_falls_back_when_file_path_has_no_prefix(): void
    {
        $image = new Ckeditor5ImageUpload([
            'hash' => 'aaaabbbbcccc',
            'file_path' => 'orphan.png',
            'storage_disk' => 'fake_cdn',
        ]);

        $this->assertSame(
            '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc',
            $image->download_url
        );
    }

    /**
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=drivers_tab_card, hook=block, override=module_override, row_state=legacy_local_row
     *
     * @effects hook_supply_modify_block_respected
     */
    #[Test]
    public function hook_block_falls_back_to_api_url(): void
    {
        HookManager::addFilter('core.storage.filter_url', fn ($url) => '');

        $image = new Ckeditor5ImageUpload([
            'hash' => 'aaaabbbbcccc',
            'file_path' => 'images/2026/01/01/a.jpg',
            'storage_disk' => 'fake_cdn',
        ]);

        $this->assertSame(
            '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc',
            $image->download_url
        );
    }
}
