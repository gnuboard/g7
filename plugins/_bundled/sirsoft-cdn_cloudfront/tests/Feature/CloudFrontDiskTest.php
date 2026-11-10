<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests\Feature;

use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Models\Plugin as PluginRecord;
use App\Services\DriverRegistryService;
use App\Services\PluginSettingsService;
use App\Support\PublicAssetDisk;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\CdnCloudfront\Providers\CdnCloudfrontServiceProvider;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontDisk;
use Plugins\Sirsoft\CdnCloudfront\Tests\PluginTestCase;

/**
 * 디스크 `cloudfront` 정의 · 공개 자산 디스크 카탈로그 노출 · 비활성 시 고아 디스크 폴백
 *
 * @scenario-manifest tests/scenarios/cloudfront-disk.yaml
 */
class CloudFrontDiskTest extends PluginTestCase
{
    /** 프레임워크가 큐 워커 재시작 신호로 쓰는 캐시 키 */
    private const RESTART_SIGNAL = 'illuminate:queue:restart';

    /**
     * 테스트 시작 — 코어 S3 디스크 설정을 시험값으로 채운다(실제 버킷이 아니다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.bucket' => 'test-bucket',
            'filesystems.disks.s3.region' => 'ap-northeast-2',
            'filesystems.disks.s3.key' => 'S3TESTKEY',
            'filesystems.disks.s3.secret' => 's3-test-secret',
        ]);
    }

    /**
     * 디스크는 코어 S3 설정을 그대로 복제하고 url 만 CDN 주소로 바꾼다. CDN 주소가 비면 url 이 null 이다.
     *
     * @scenario case=disk_definition
     *
     * @effects disk_clones_s3_with_cdn_url
     *
     * @return void 반환값 없음
     */
    public function test_disk_clones_core_s3_settings_with_cdn_url(): void
    {
        $this->configure(['cdn_base_url' => self::CDN.'/']);

        $this->assertTrue(app(CloudFrontDisk::class)->register());

        $disk = config('filesystems.disks.cloudfront');
        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('test-bucket', $disk['bucket']);
        $this->assertSame('ap-northeast-2', $disk['region']);
        $this->assertSame('S3TESTKEY', $disk['key']);
        $this->assertSame(self::CDN, $disk['url']);

        $this->configure(['cdn_base_url' => '']);
        app(CloudFrontDisk::class)->register();
        $this->assertNull(config('filesystems.disks.cloudfront.url'));
    }

    /**
     * 활성 플러그인의 리스너가 공개 자산 디스크 카탈로그에 cloudfront 를 올린다 — 라벨은 로케일별, 제공자는 이 플러그인.
     * 디스크 정의가 없으면(코어 S3 디스크 설정 없음) 올리지 않는다.
     *
     * @scenario case=catalog
     *
     * @effects catalog_lists_cloudfront_disk
     *
     * @return void 반환값 없음
     */
    public function test_catalog_lists_cloudfront_when_disk_defined(): void
    {
        $this->registerListeners();
        $registry = app(DriverRegistryService::class);

        $this->assertNotContains('cloudfront', array_column($registry->getAvailableDrivers('public_asset'), 'id'));

        app(CloudFrontDisk::class)->register();
        $entry = collect($registry->getAvailableDrivers('public_asset'))->firstWhere('id', 'cloudfront');

        $this->assertNotNull($entry);
        $this->assertSame(self::IDENTIFIER, $entry['provider']);
        $this->assertSame('CloudFront (S3 + CDN)', $entry['label']['ko']);
        $this->assertArrayHasKey('en', $entry['label']);
        $this->assertTrue($registry->isDriverAvailable('public_asset', 'cloudfront'));
        $this->assertSame(1, collect($registry->getAvailableDrivers('public_asset'))->where('id', 'cloudfront')->count());
    }

    /**
     * 드라이버 탭에 S3 버킷을 저장하지 않으면 디스크를 만들지 않는다 — 코어 config 는 s3 디스크를 항상 정의하므로
     * 드라이버 값만으로는 판정할 수 없다. 선택지에도 오르지 않는다.
     *
     * @scenario case=no_bucket
     *
     * @effects disk_undefined_without_bucket
     *
     * @return void 반환값 없음
     */
    public function test_disk_is_not_defined_without_s3_bucket(): void
    {
        foreach ([null, '', '   '] as $bucket) {
            config(['filesystems.disks.s3.bucket' => $bucket]);

            $this->assertNull(app(CloudFrontDisk::class)->definition());
        }

        $this->registerListeners();
        $this->assertFalse(app(CloudFrontDisk::class)->register());
        $this->assertDiskAbsent();
        $this->assertNotContains('cloudfront', array_column(app(DriverRegistryService::class)->getAvailableDrivers('public_asset'), 'id'));

        // 이미 정의된 디스크도 버킷이 비면 다시 등록할 때 사라진다
        config(['filesystems.disks.s3.bucket' => 'test-bucket']);
        $this->assertTrue(app(CloudFrontDisk::class)->register());
        config(['filesystems.disks.s3.bucket' => '']);
        $this->assertFalse(app(CloudFrontDisk::class)->register());
        $this->assertDiskAbsent();
    }

    /**
     * 플러그인 설정을 저장하면 그 프로세스의 디스크를 새 CDN 주소로 다시 정의하고 큐 워커에 재시작 신호를 보낸다 —
     * 워커는 부팅 때 정의한 디스크를 들고 있어 옛 주소로 URL 을 만들고, 그러면 무효화 대상에서 조용히 빠진다.
     * 설정 초기화도 같다.
     *
     * @scenario case=settings_sync
     *
     * @effects disk_refreshed_and_workers_restarted_on_settings_save
     *
     * @return void 반환값 없음
     */
    public function test_settings_save_and_reset_refresh_disk_and_restart_workers(): void
    {
        $this->configure(['cdn_base_url' => self::CDN]);
        app(CloudFrontDisk::class)->register();
        $this->registerListeners();
        Cache::forget(self::RESTART_SIGNAL);

        $this->configure(['cdn_base_url' => 'https://new-cdn.example.test']);
        $this->assertSame('https://new-cdn.example.test', config('filesystems.disks.cloudfront.url'));
        $this->assertWorkersSignalled();

        app(PluginSettingsService::class)->reset(self::IDENTIFIER);
        $this->assertNull(config('filesystems.disks.cloudfront.url'));
        $this->assertWorkersSignalled();
    }

    /**
     * 다른 플러그인 설정 저장이나 실패한 저장은 디스크와 워커를 건드리지 않는다.
     *
     * @scenario case=settings_sync
     *
     * @effects disk_refreshed_and_workers_restarted_on_settings_save
     *
     * @return void 반환값 없음
     */
    public function test_other_plugin_or_failed_save_does_not_restart_workers(): void
    {
        $this->configure(['cdn_base_url' => self::CDN]);
        app(CloudFrontDisk::class)->register();
        $this->registerListeners();
        Cache::forget(self::RESTART_SIGNAL);

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-other', ['cdn_base_url' => 'x'], true);
        HookManager::doAction('core.plugin_settings.after_save', self::IDENTIFIER, ['cdn_base_url' => 'x'], false);
        HookManager::doAction('core.plugins.after_deactivate', 'sirsoft-other');

        $this->assertSame(self::CDN, config('filesystems.disks.cloudfront.url'));
        $this->assertFalse(Cache::has(self::RESTART_SIGNAL));
    }

    /**
     * 플러그인을 끄면 그 프로세스의 디스크 정의를 지우고 워커에 재시작 신호를 보낸다 — 코어는 끄는 사건에는 재시작을 보내지
     * 않으므로, 이 신호가 없으면 워커가 재시작 전까지 CDN 주소를 계속 낸다.
     *
     * @scenario case=settings_sync
     *
     * @effects disk_removed_and_workers_restarted_on_deactivate
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_removes_disk_and_restarts_workers(): void
    {
        $this->configure(['cdn_base_url' => self::CDN]);
        app(CloudFrontDisk::class)->register();
        $this->registerListeners();

        HookManager::doAction('core.plugins.after_deactivate', self::IDENTIFIER);

        $this->assertDiskAbsent();
        $this->assertWorkersSignalled();
    }

    /**
     * 재시작 신호가 실패해도 설정 저장은 그대로 끝난다 — 코어 저장 경로와 같은 계약이다.
     *
     * @scenario case=settings_sync
     *
     * @effects disk_refreshed_and_workers_restarted_on_settings_save
     *
     * @return void 반환값 없음
     */
    public function test_worker_restart_failure_does_not_break_settings_save(): void
    {
        $this->registerListeners();
        Artisan::shouldReceive('call')->with('queue:restart')->andThrow(new \RuntimeException('cache down'));
        Log::spy();

        $this->configure(['cdn_base_url' => self::CDN]);

        $this->assertSame(self::CDN, config('filesystems.disks.cloudfront.url'));
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 서비스 프로바이더는 플러그인이 활성일 때만 디스크를 정의한다.
     *
     * @scenario case=inactive_fallback
     *
     * @effects disk_defined_only_while_active
     *
     * @return void 반환값 없음
     */
    public function test_provider_defines_disk_only_while_plugin_is_active(): void
    {
        // setUp 에서 등록한 프로바이더 — 플러그인 행이 없으니(비활성) 디스크가 없다
        $this->assertDiskAbsent();

        PluginRecord::factory()->create(['identifier' => self::IDENTIFIER, 'vendor' => 'sirsoft', 'status' => 'active']);
        PluginManager::invalidatePluginStatusCache();
        $this->app->register(CdnCloudfrontServiceProvider::class, true);

        $this->assertSame('s3', config('filesystems.disks.cloudfront.driver'));
    }

    /**
     * 플러그인을 끄면 디스크 정의가 사라지고, 그 디스크를 고른 저장값은 남은 채 코어가 기본값으로 바꿔 쓴다 —
     * 공개 썸네일 디스크는 비공개 썸네일 디스크로, 공개 자산 디스크는 스트리밍(null)으로. 다시 켜면 되살아난다.
     *
     * @scenario case=inactive_fallback
     *
     * @effects orphan_disk_falls_back_while_inactive
     *
     * @return void 반환값 없음
     */
    public function test_orphan_disk_falls_back_while_plugin_inactive(): void
    {
        $this->registerListeners();
        config(['core.thumbnail.disk_public' => 'cloudfront', 'core.storage.public_asset_disk' => 'cloudfront']);
        $store = app(ThumbnailStore::class);

        app(CloudFrontDisk::class)->register();
        $this->assertSame('cloudfront', $store->diskFor(false));
        $this->assertSame('cloudfront', PublicAssetDisk::resolve());

        // 비활성 — 다음 요청부터 프로바이더가 디스크를 정의하지 않고 리스너도 등록되지 않는다
        config(['filesystems.disks.cloudfront' => null]);

        $this->assertSame(ThumbnailStore::PRIVATE_DISK, $store->diskFor(false));
        $this->assertSame(ThumbnailStore::PRIVATE_DISK, $store->diskFor(true));
        $this->assertNull(PublicAssetDisk::resolve());
        $this->assertNotContains('cloudfront', array_column(app(DriverRegistryService::class)->getAvailableDrivers('public_asset'), 'id'));
        $this->assertSame('cloudfront', config('core.thumbnail.disk_public'));

        app(CloudFrontDisk::class)->register();
        $this->assertSame('cloudfront', $store->diskFor(false));
    }

    /**
     * 공개 썸네일 디스크로 고르면 파생본 공개 URL 이 CDN 주소로 나가고, 그 접두를 지운 퍼지 항목이 CDN 호스트를 가리킨다.
     *
     * @scenario case=disk_definition
     *
     * @effects thumbnail_urls_on_cdn_host
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_url_on_cloudfront_disk_points_at_cdn(): void
    {
        $this->configure(['cdn_base_url' => self::CDN]);
        app(CloudFrontDisk::class)->register();
        config(['core.thumbnail.disk_public' => 'cloudfront']);

        $store = app(ThumbnailStore::class);
        $url = $store->storage('cloudfront')->url('', $store->sourceDirectory('public', 'modules/shop/p1.jpg'));

        $this->assertSame(self::CDN.'/thumbnails/public/modules/shop/p1.jpg', $url);
    }

    /**
     * 큐 워커 재시작 신호가 나갔는지 확인하고 다음 확인을 위해 신호를 지웁니다 (`queue:restart` 가 쓰는 캐시 값).
     *
     * @return void 반환값 없음
     */
    private function assertWorkersSignalled(): void
    {
        $this->assertTrue(Cache::has(self::RESTART_SIGNAL));
        Cache::forget(self::RESTART_SIGNAL);
    }

    /**
     * 디스크 정의가 키째 없는지 확인합니다 — null 값으로 남으면 프레임워크 파일시스템 프로바이더가
     * 디스크 목록을 돌며 null 을 배열로 받다가 TypeError 로 부팅이 깨진다(버킷 없는 사이트에서 플러그인 활성).
     *
     * @return void 반환값 없음
     */
    private function assertDiskAbsent(): void
    {
        $this->assertArrayNotHasKey('cloudfront', (array) config('filesystems.disks'));
        $this->assertNotContains(null, (array) config('filesystems.disks'));
    }
}
