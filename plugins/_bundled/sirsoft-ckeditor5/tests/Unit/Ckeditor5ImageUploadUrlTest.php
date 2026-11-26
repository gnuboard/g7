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
 *
 * `preview_url`은 행 disk 와 무관하게 항상 API 서빙 경로다 — 관리 목록 미리보기와 본문 srcset
 * 후보(`?size=`)가 이 주소를 쓴다. 직접 URL 이 없으면 `download_url` 과 같다.
 */
class Ckeditor5ImageUploadUrlTest extends PluginTestCase
{
    /**
     * 가짜 공개 자산 디스크(fake_cdn)를 등록하고 드라이버 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 드라이버 캐시와 훅 등록을 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Ckeditor5ImageUpload::flushStorageCache();
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * plugins 디스크 행은 직접 URL 이 없어 두 주소 모두 API 서빙 경로다.
     *
     * @scenario consumer=editor, disk_setting=public, e2e=ecommerce_settings_field, hook=unregistered, override=follow_core, row_state=legacy_local_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
     *
     * @return void 반환값 없음
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
        $this->assertSame($image->download_url, $image->preview_url);
    }

    /**
     * CDN 디스크 행의 download_url 은 직접 URL, preview_url 은 API 서빙 경로(apiUrl)다.
     *
     * @effects preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
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
        // 직접 URL 행이어도 썸네일 기준 주소는 API 서빙 경로
        $this->assertSame('/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc', $image->preview_url);
        $this->assertSame($image->preview_url, $image->apiUrl());
    }

    /**
     * file_path 첫 세그먼트를 카테고리로 나누는 일반형 — images 가 아닌 접두도 직접 URL 을 만든다.
     *
     * @effects url_returned_for_public_and_url_configured_disk
     *
     * @return void 반환값 없음
     */
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

    /**
     * 카테고리 접두가 없는 file_path 는 직접 URL 을 만들지 않고 API 서빙 경로로 돌아간다.
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
     *
     * @return void 반환값 없음
     */
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
     * core.storage.filter_url 훅이 직접 URL 을 막으면 두 주소가 같은 API 서빙 경로다.
     *
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=drivers_tab_card, hook=block, override=module_override, row_state=legacy_local_row
     *
     * @effects hook_supply_modify_block_respected, preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
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
        // 훅이 직접 URL 을 막으면 두 주소가 같다 — 업로드 응답은 이때 `urls` 를 싣지 않는다
        $this->assertSame($image->download_url, $image->preview_url);
    }
}
