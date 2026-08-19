<?php

namespace Tests\Unit\Extension\Storage;

use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 카테고리별 스토리지 디스크 결정(getStorageDiskFor/getStorageFor/resolvePublicAssetDisk) 단위 테스트
 *
 * 기본 동작 100% 보존(기본값 = getStorageDisk), 디스크 단위 memoize,
 * 공개 자산 디스크 해석 우선순위(확장 개별 > 코어 전역 > 미설정)와
 * 고아 디스크 폴백을 검증합니다.
 */
class StorageCategoryDiskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        Config::set('core.storage.public_asset_disk', '');
    }

    /**
     * resolvePublicAssetDisk 를 노출한 테스트 모듈 더블을 생성합니다.
     *
     * @return AbstractModule&object{exposedResolvePublicAssetDisk: callable} 테스트 모듈
     */
    private function makeModule()
    {
        return new class extends AbstractModule
        {
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function exposedResolvePublicAssetDisk(?string $override = null): ?string
            {
                return $this->resolvePublicAssetDisk($override);
            }
        };
    }

    /**
     * 테스트 플러그인 더블을 생성합니다.
     *
     * @return AbstractPlugin 테스트 플러그인
     */
    private function makePlugin(): AbstractPlugin
    {
        return new class extends AbstractPlugin
        {
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }
        };
    }

    #[Test]
    public function get_storage_disk_for_defaults_to_base_disk(): void
    {
        $module = $this->makeModule();
        $plugin = $this->makePlugin();

        // 오버라이드 없는 기본 구현 — 모든 카테고리가 기본 디스크 (현행 100% 보존)
        $this->assertSame($module->getStorageDisk(), $module->getStorageDiskFor('images'));
        $this->assertSame($module->getStorageDisk(), $module->getStorageDiskFor('settings'));
        $this->assertSame($plugin->getStorageDisk(), $plugin->getStorageDiskFor('images'));
        $this->assertSame($plugin->getStorageDisk(), $plugin->getStorageDiskFor('settings'));
    }

    #[Test]
    public function get_storage_for_reuses_base_instance_when_disk_matches(): void
    {
        $module = $this->makeModule();

        $this->assertSame($module->getStorage(), $module->getStorageFor('images'));
    }

    /**
     * @scenario consumer=review, disk_setting=public, e2e=drivers_tab_card, hook=block, override=module_override, row_state=new_remote_row
     */
    #[Test]
    public function get_storage_for_memoizes_per_disk(): void
    {
        $module = new class extends AbstractModule
        {
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getStorageDiskFor(string $category): string
            {
                return $category === 'images' ? 'fake_cdn' : $this->getStorageDisk();
            }
        };

        $imagesStorage = $module->getStorageFor('images');

        $this->assertSame('fake_cdn', $imagesStorage->getDisk());
        $this->assertNotSame($module->getStorage(), $imagesStorage);
        // 디스크 단위 memoize — 반복 호출이 같은 인스턴스 반환
        $this->assertSame($imagesStorage, $module->getStorageFor('images'));
        // 기본 디스크 카테고리는 기본 인스턴스 재사용
        $this->assertSame($module->getStorage(), $module->getStorageFor('settings'));
    }

    /**
     * @scenario consumer=product, disk_setting=none, e2e=drivers_tab_card, hook=unregistered, override=module_override, row_state=legacy_local_row
     *
     * @effects none_override_forces_streaming_over_global
     */
    #[Test]
    public function resolve_public_asset_disk_prefers_override_over_global(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        $module = $this->makeModule();

        $this->assertSame('fake_cdn', $module->exposedResolvePublicAssetDisk('fake_cdn'));
    }

    /**
     * @scenario consumer=editor, disk_setting=none, e2e=save_roundtrip, hook=supply, override=follow_core, row_state=new_remote_row
     *
     * @effects none_override_forces_streaming_over_global
     */
    #[Test]
    public function resolve_public_asset_disk_falls_back_to_global_when_override_empty(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        $module = $this->makeModule();

        $this->assertSame('fake_cdn', $module->exposedResolvePublicAssetDisk(''));
        $this->assertSame('fake_cdn', $module->exposedResolvePublicAssetDisk(null));
    }

    #[Test]
    public function resolve_public_asset_disk_returns_null_when_unset(): void
    {
        $module = $this->makeModule();

        // 전역 미설정 + 오버라이드 없음 → 스트리밍 유지
        $this->assertNull($module->exposedResolvePublicAssetDisk());
    }

    #[Test]
    public function resolve_public_asset_disk_treats_none_as_streaming(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        $module = $this->makeModule();

        // 'none' 오버라이드 = 전역이 CDN 이어도 이 확장은 스트리밍 강제
        $this->assertNull($module->exposedResolvePublicAssetDisk('none'));
    }

    /**
     * @scenario consumer=product, disk_setting=s3_without_url, e2e=drivers_tab_card, hook=block, override=module_override, row_state=new_remote_row
     *
     * @effects orphan_disk_falls_back_to_streaming
     */
    #[Test]
    public function resolve_public_asset_disk_falls_back_on_orphan_disk(): void
    {
        // 플러그인 비활성화로 config 에서 사라진 디스크 → 스트리밍 자동 폴백
        Config::set('core.storage.public_asset_disk', 'vanished_plugin_disk');
        $module = $this->makeModule();

        $this->assertNull($module->exposedResolvePublicAssetDisk());
        $this->assertNull($module->exposedResolvePublicAssetDisk('vanished_plugin_disk'));
    }
}
