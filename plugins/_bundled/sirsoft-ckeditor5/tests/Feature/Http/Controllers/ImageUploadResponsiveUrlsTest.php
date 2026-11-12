<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers;

use App\Extension\HookManager;
use App\Extension\Storage\PluginStorageDriver;
use App\Thumbnails\ThumbnailPresetRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 업로드 응답의 반응형 후보 `urls` (#878)
 *
 * 공개 자산 디스크(CDN)에 저장된 본문 이미지는 `<img src>` 에 직접 주소가 박혀 썸네일 파이프를 지나지 않는다.
 * 업로드 응답이 `urls`(`default` = 직접 주소, 숫자 키 = API 주소 + `?size=`)를 함께 내리면 편집기가 본문에
 * `srcset` 으로 저장한다. 직접 주소가 없는 행(로컬 디스크)·썸네일 기능 꺼짐은 종전 응답(`{ url }`) 그대로다.
 *
 * 기본 규격 표(defaults.json)의 숫자 요청 대상은 md 640 · lg 1280 · xl 1920 이다.
 */
class ImageUploadResponsiveUrlsTest extends PluginTestCase
{
    private const UPLOAD = '/api/plugins/sirsoft-ckeditor5/upload';

    private const API = '/api/plugins/sirsoft-ckeditor5/images/';

    /** 시험 환경 사이트 주소(`.env.testing` 의 `APP_URL`) — 축소 후보가 절대 주소인지 잰다 */
    private const SITE = 'http://localhost';

    private string $cdnRoot;

    /**
     * plugins 디스크를 가짜로 바꾸고 가짜 공개 자산 디스크(fake_cdn)·기본 규격 표를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('plugins');
        $this->cdnRoot = storage_path('framework/testing/disks/fake_cdn_ckeditor5_urls');

        config([
            'filesystems.disks.fake_cdn' => [
                'driver' => 'local',
                'root' => $this->cdnRoot,
                'url' => 'https://cdn.test/assets',
            ],
            'core.thumbnail.enabled' => true,
            'core.thumbnail.presets' => [],
        ]);
        app(ThumbnailPresetRegistry::class)->flush();
        Ckeditor5ImageUpload::flushStorageCache();
    }

    /**
     * 드라이버 캐시·훅 등록·가짜 CDN 디렉터리를 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Ckeditor5ImageUpload::flushStorageCache();
        HookManager::resetAll();
        File::deleteDirectory($this->cdnRoot);

        parent::tearDown();
    }

    /**
     * 직접 URL 디스크에 저장된 업로드는 `urls` 를 함께 낸다 — `default` 는 직접 주소, 숫자 키는 원본 가로 이하
     * 규격만, 값은 API 주소 + `?size=`.
     *
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects editor_upload_returns_responsive_api_urls_for_direct_rows
     *
     * @return void 반환값 없음
     */
    public function test_cdn_upload_returns_responsive_api_urls_up_to_original_width(): void
    {
        $this->storeUploadsOn('fake_cdn');

        $response = $this->actingAs($this->createAdminUser())->postJson(self::UPLOAD, [
            'upload' => UploadedFile::fake()->image('wide.jpg', 1500, 900),
        ]);

        $response->assertStatus(201);
        $record = Ckeditor5ImageUpload::firstOrFail();
        $direct = $response->json('url');

        $this->assertStringStartsWith('https://cdn.test/assets/sirsoft-ckeditor5/images/', $direct);
        // 축소 후보는 사이트 절대 주소(메일 본문에서도 열리게), 마지막 원본 가로 후보는 직접 주소(큰 화면이 CDN 원본을 받게)
        $this->assertSame([
            '640' => self::SITE.self::API.$record->hash.'?size=md',
            '1280' => self::SITE.self::API.$record->hash.'?size=lg',
            '1500' => $direct,
            'default' => $direct,
        ], $response->json('urls'));
        $this->assertArrayNotHasKey('1920', $response->json('urls'), '원본(1500)보다 큰 후보가 실렸다');
    }

    /**
     * 원본이 가장 작은 숫자 규격보다 좁으면 후보가 없으므로 `urls` 를 싣지 않는다.
     *
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects editor_upload_returns_responsive_api_urls_for_direct_rows
     *
     * @return void 반환값 없음
     */
    public function test_cdn_upload_narrower_than_every_numeric_preset_has_no_urls(): void
    {
        $this->storeUploadsOn('fake_cdn');

        $response = $this->actingAs($this->createAdminUser())->postJson(self::UPLOAD, [
            'upload' => UploadedFile::fake()->image('small.jpg', 300, 200),
        ]);

        $response->assertStatus(201);
        $this->assertStringStartsWith('https://cdn.test/assets/', $response->json('url'));
        $this->assertArrayNotHasKey('urls', $response->json());
    }

    /**
     * 로컬 디스크(직접 URL 없음) 업로드는 종전 응답 그대로 — 본문 렌더가 API 주소에 srcset 을 붙인다.
     *
     * @scenario consumer=editor, disk_setting=none, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=legacy_local_row
     *
     * @effects editor_upload_returns_responsive_api_urls_for_direct_rows
     *
     * @return void 반환값 없음
     */
    public function test_local_disk_upload_has_no_urls(): void
    {
        $response = $this->actingAs($this->createAdminUser())->postJson(self::UPLOAD, [
            'upload' => UploadedFile::fake()->image('local.jpg', 1500, 900),
        ]);

        $response->assertStatus(201);
        $record = Ckeditor5ImageUpload::firstOrFail();
        $this->assertSame(self::API.$record->hash, $response->json('url'));
        $this->assertArrayNotHasKey('urls', $response->json());
    }

    /**
     * 썸네일 기능을 끄면 직접 URL 행이어도 `urls` 를 싣지 않는다.
     *
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects editor_upload_returns_responsive_api_urls_for_direct_rows
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_disabled_has_no_urls(): void
    {
        config(['core.thumbnail.enabled' => false]);
        $this->storeUploadsOn('fake_cdn');

        $response = $this->actingAs($this->createAdminUser())->postJson(self::UPLOAD, [
            'upload' => UploadedFile::fake()->image('off.jpg', 1500, 900),
        ]);

        $response->assertStatus(201);
        $this->assertStringStartsWith('https://cdn.test/assets/', $response->json('url'));
        $this->assertArrayNotHasKey('urls', $response->json());
    }

    /**
     * `core.storage.filter_url` 훅이 직접 URL 을 막으면 두 주소가 같아 `urls` 를 싣지 않는다.
     *
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=save_roundtrip, hook=block, override=follow_core, row_state=new_remote_row
     *
     * @effects editor_upload_returns_responsive_api_urls_for_direct_rows
     *
     * @return void 반환값 없음
     */
    public function test_hook_blocked_direct_url_has_no_urls(): void
    {
        HookManager::addFilter('core.storage.filter_url', fn ($url) => '');
        $this->storeUploadsOn('fake_cdn');

        $response = $this->actingAs($this->createAdminUser())->postJson(self::UPLOAD, [
            'upload' => UploadedFile::fake()->image('blocked.jpg', 1500, 900),
        ]);

        $response->assertStatus(201);
        $record = Ckeditor5ImageUpload::firstOrFail();
        $this->assertSame(self::API.$record->hash, $response->json('url'));
        $this->assertArrayNotHasKey('urls', $response->json());
    }

    /**
     * 가로를 모르면(null) 후보를 가로로 자르지 않는다 — 같은 가로의 규격은 앞 것 하나만.
     *
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects editor_upload_returns_responsive_api_urls_for_direct_rows
     *
     * @return void 반환값 없음
     */
    public function test_responsive_urls_without_known_width_keeps_every_numeric_preset(): void
    {
        $image = new Ckeditor5ImageUpload([
            'hash' => 'aaaabbbbcccc',
            'file_path' => 'images/2026/11/12/a.jpg',
            'storage_disk' => 'fake_cdn',
        ]);

        $urls = app(ImageUploadService::class)->responsiveUrls($image, null);

        // 원본 가로를 모르면 원본 후보에 붙일 가로 기술자가 없어 축소 후보만 싣는다
        $this->assertSame([
            '640' => self::SITE.self::API.'aaaabbbbcccc?size=md',
            '1280' => self::SITE.self::API.'aaaabbbbcccc?size=lg',
            '1920' => self::SITE.self::API.'aaaabbbbcccc?size=xl',
            'default' => 'https://cdn.test/assets/sirsoft-ckeditor5/images/2026/11/12/a.jpg',
        ], $urls);
    }

    /**
     * 업로드 서비스가 지정 디스크에 저장하도록 바꾼다 (공개 자산 디스크 설정과 같은 효과).
     *
     * @param  string  $disk  저장 디스크
     * @return void 반환값 없음
     */
    private function storeUploadsOn(string $disk): void
    {
        $this->app->bind(ImageUploadService::class, fn ($app) => new ImageUploadService(
            $app->make(ImageUploadRepositoryInterface::class),
            new PluginStorageDriver('sirsoft-ckeditor5', $disk),
        ));
    }
}
