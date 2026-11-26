<?php

namespace Tests\Feature\Settings;

use App\Extension\HookManager;
use App\Providers\CoreServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Services\DriverRegistryService;
use App\Support\PublicAssetDisk;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 쓰는 시점에 해석하는 디스크 카테고리의 부팅 순서
 *
 * 플러그인이 등록하는 공개 자산 디스크(CloudFront 등)는 디스크 정의를 모든 프로바이더 부팅이 끝난 뒤(`app->booted`)에 한다 —
 * 코어 S3 설정이 그 전에 확정되기 때문이다. 그 플러그인의 드라이버 목록 필터는 디스크 정의가 있어야 자기 드라이버를 넣는다.
 * 그런데 코어 `applyExtensionDriverConfigs()` 는 그보다 먼저 돌아, 카탈로그에 아직 없는 저장값을 기본값(`none`)으로 덮었다.
 * 그 결과 관리자가 CloudFront 를 골라 저장해도 모든 요청에서 공개 자산이 스트리밍으로 돌아갔다.
 *
 * 디스크 카테고리(공개 자산·비공개 첨부 저장소)는 해석기(`PublicAssetDisk`·`PrivateAttachmentDisk`)가 쓰는 시점에 고아 디스크를
 * 걸러 내므로, 부팅 시 폴백 쓰기를 하지 않는다. 저장 드라이버(`storage`) 같은 다른 카테고리의 폴백은 그대로다.
 */
class RuntimeResolvedDriverCategoryBootTest extends TestCase
{
    /**
     * 시험이 등록한 훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 저장된 드라이버 설정을 흉내 내고 코어의 확장 드라이버 적용을 실제로 태웁니다.
     *
     * @param  array<string, string>  $drivers  drivers 카테고리 저장값
     * @return void 반환값 없음
     */
    private function bootWithSavedDrivers(array $drivers): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')->willReturnCallback(
            fn (string $category) => $category === 'drivers' ? $drivers : []
        );
        $this->app->instance(JsonConfigRepository::class, $configRepository);

        $provider = new CoreServiceProvider($this->app);
        $apply = new \ReflectionMethod($provider, 'applyExtensionDriverConfigs');
        $apply->setAccessible(true);
        $apply->invoke($provider);
    }

    /**
     * 디스크 정의가 있어야 드라이버를 넣는 플러그인 필터를 등록합니다(CloudFront 리스너와 같은 꼴).
     *
     * @param  string  $category  드라이버 카테고리
     * @param  string  $disk  디스크 이름
     * @return void 반환값 없음
     */
    private function registerLateDiskPlugin(string $category, string $disk): void
    {
        HookManager::addFilter("core.settings.available_{$category}_drivers", function (array $drivers) use ($disk) {
            if (config("filesystems.disks.{$disk}") === null) {
                return $drivers;
            }
            $drivers[] = ['id' => $disk, 'label' => ['ko' => $disk, 'en' => $disk], 'provider' => 'fx-cdn'];

            return $drivers;
        });
    }

    /**
     * 공개 자산 디스크는 부팅 중 카탈로그에 아직 없어도 저장값을 덮지 않고, booted 에 디스크가 정의되면 그 디스크로 해석된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=late_disk_definition
     *
     * @effects runtime_resolved_selection_survives_boot_before_disk_defined
     */
    public function test_public_asset_selection_survives_boot_before_late_disk_definition(): void
    {
        Config::set('filesystems.disks.fx-cdn', null);
        Config::set('core.storage.public_asset_disk', 'fx-cdn'); // SettingsServiceProvider 가 저장값을 먼저 적용한 상태
        $this->registerLateDiskPlugin('public_asset', 'fx-cdn');
        Log::spy();

        $this->bootWithSavedDrivers(['public_asset_disk' => 'fx-cdn']);

        $this->assertSame('fx-cdn', config('core.storage.public_asset_disk'), '부팅 중 저장값이 기본값으로 덮였습니다 — CloudFront 선택이 모든 요청에서 사라집니다.');

        // 플러그인이 booted 에 디스크를 정의한다
        Config::set('filesystems.disks.fx-cdn', ['driver' => 'local', 'root' => storage_path('framework/testing/fx-cdn')]);
        $this->assertSame('fx-cdn', PublicAssetDisk::resolve());
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * 디스크가 끝내 정의되지 않으면(플러그인 제거) 해석기가 쓰는 시점에 null 로 걸러 스트리밍으로 돌아간다 — 저장값은 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=orphan_disk
     *
     * @effects orphan_runtime_resolved_disk_filtered_at_use_time
     */
    public function test_orphan_public_asset_disk_is_filtered_at_use_time(): void
    {
        Config::set('filesystems.disks.fx-gone', null);
        Config::set('core.storage.public_asset_disk', 'fx-gone');

        $this->bootWithSavedDrivers(['public_asset_disk' => 'fx-gone']);

        $this->assertSame('fx-gone', config('core.storage.public_asset_disk'));
        $this->assertNull(PublicAssetDisk::resolve());
    }

    /**
     * 런타임 해석 카테고리 목록은 공개 자산과 비공개 첨부 저장소다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=runtime_resolved_categories
     *
     * @effects runtime_resolved_categories_are_public_asset_and_private_attachment
     */
    public function test_runtime_resolved_categories_are_disk_categories(): void
    {
        $registry = app(DriverRegistryService::class);

        $this->assertTrue($registry->isRuntimeResolved('public_asset'));
        $this->assertTrue($registry->isRuntimeResolved('private_attachment'));
        $this->assertFalse($registry->isRuntimeResolved('storage'));
        $this->assertFalse($registry->isRuntimeResolved('cache'));
    }

    /**
     * 저장 드라이버(storage)는 예전처럼 사용 불가 플러그인 값을 기본 드라이버로 되돌린다 — filesystems.default 가 고아면 모든 업로드가 실패한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=storage_boot_fallback
     *
     * @effects storage_category_keeps_boot_fallback
     */
    public function test_storage_category_keeps_boot_fallback(): void
    {
        Config::set('filesystems.default', 'fx-ghost');
        Log::spy();

        $this->bootWithSavedDrivers(['storage_driver' => 'fx-ghost']);

        $this->assertSame('local', config('filesystems.default'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'fx-ghost'))->atLeast()->once();
    }
}
