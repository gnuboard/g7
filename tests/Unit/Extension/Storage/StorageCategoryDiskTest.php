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
    /**
     * 테스트 환경을 준비합니다.
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
            /**
             * 이름을 반환합니다.
             *
             * @return array 배열
             */
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * 버전을 반환합니다.
             *
             * @return string 문자열
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 설명을 반환합니다.
             *
             * @return array 배열
             */
            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * exposedResolvePublicAssetDisk 을 처리합니다.
             *
             * @param  ?string  $override  override 값
             * @return ?string 문자열
             */
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
            /**
             * 이름을 반환합니다.
             *
             * @return array 배열
             */
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * 버전을 반환합니다.
             *
             * @return string 문자열
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 설명을 반환합니다.
             *
             * @return array 배열
             */
            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * exposedResolvePublicAssetDisk 을 처리합니다.
             *
             * @param  ?string  $override  override 값
             * @return ?string 문자열
             */
            public function exposedResolvePublicAssetDisk(?string $override = null): ?string
            {
                return $this->resolvePublicAssetDisk($override);
            }
        };
    }

    /**
     * get storage disk for defaults to base disk 을 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @effects storage_category_disk_defaults_to_base_disk
     */
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

    /**
     * get storage for reuses base instance when disk matches 을 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @effects storage_category_reuses_base_instance_for_same_disk
     */
    #[Test]
    public function get_storage_for_reuses_base_instance_when_disk_matches(): void
    {
        $module = $this->makeModule();

        $this->assertSame($module->getStorage(), $module->getStorageFor('images'));
    }

    /**
     * 카테고리 디스크마다 스토리지 인스턴스를 한 번만 만들어 재사용하는지 확인합니다.
     *
     * @scenario consumer=review, disk_setting=public, e2e=drivers_tab_card, hook=block, override=module_override, row_state=new_remote_row
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function get_storage_for_memoizes_per_disk(): void
    {
        $module = new class extends AbstractModule
        {
            /**
             * 이름을 반환합니다.
             *
             * @return array 배열
             */
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * 버전을 반환합니다.
             *
             * @return string 문자열
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 설명을 반환합니다.
             *
             * @return array 배열
             */
            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * getStorageDiskFor 을 처리합니다.
             *
             * @param  string  $category  category 값
             * @return string 문자열
             */
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
     * 확장 개별 설정이 코어 공개 자산 디스크보다 우선하는지 확인합니다.
     *
     * @scenario consumer=product, disk_setting=none, e2e=drivers_tab_card, hook=unregistered, override=module_override, row_state=legacy_local_row
     *
     * @effects none_override_forces_streaming_over_global
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resolve_public_asset_disk_prefers_override_over_global(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        $module = $this->makeModule();

        $this->assertSame('fake_cdn', $module->exposedResolvePublicAssetDisk('fake_cdn'));
    }

    /**
     * 확장 개별 설정이 비면 코어 공개 자산 디스크를 쓰는지 확인합니다.
     *
     * @scenario consumer=editor, disk_setting=none, e2e=save_roundtrip, hook=supply, override=follow_core, row_state=new_remote_row
     *
     * @effects none_override_forces_streaming_over_global
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resolve_public_asset_disk_falls_back_to_global_when_override_empty(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        $module = $this->makeModule();

        $this->assertSame('fake_cdn', $module->exposedResolvePublicAssetDisk(''));
        $this->assertSame('fake_cdn', $module->exposedResolvePublicAssetDisk(null));
    }

    /**
     * resolve public asset disk returns null when unset 을 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @effects unset_public_asset_disk_keeps_streaming
     */
    #[Test]
    public function resolve_public_asset_disk_returns_null_when_unset(): void
    {
        $module = $this->makeModule();

        // 전역 미설정 + 오버라이드 없음 → 스트리밍 유지
        $this->assertNull($module->exposedResolvePublicAssetDisk());
    }

    /**
     * resolve public asset disk treats none as streaming 을 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @effects none_override_forces_streaming_over_global
     */
    #[Test]
    public function resolve_public_asset_disk_treats_none_as_streaming(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        $module = $this->makeModule();

        // 'none' 오버라이드 = 전역이 CDN 이어도 이 확장은 스트리밍 강제
        $this->assertNull($module->exposedResolvePublicAssetDisk('none'));
    }

    /**
     * 정의되지 않은 디스크는 스트리밍(null)으로 돌아가는지 확인합니다.
     *
     * @scenario consumer=product, disk_setting=s3_without_url, e2e=drivers_tab_card, hook=block, override=module_override, row_state=new_remote_row
     *
     * @effects orphan_disk_falls_back_to_streaming
     *
     * @return void 반환값 없음
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

    /**
     * 플러그인 사본도 모듈과 동일한 해석 규칙을 따라야 합니다.
     *
     * 위임 이관(공개 #134) 전까지 플러그인 사본은 직접 테스트가 0건이라 오타가 나도
     * 아무도 잡지 못하는 자리였다. 모듈과 같은 4축을 플러그인 더블에도 돌린다.
     *
     * @effects none_override_forces_streaming_over_global, orphan_disk_falls_back_to_streaming
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function plugin_resolve_public_asset_disk_matches_module_rules(): void
    {
        $plugin = $this->makePlugin();

        // 미설정 → 스트리밍
        $this->assertNull($plugin->exposedResolvePublicAssetDisk());

        // 전역 설정 → 그 값
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        $this->assertSame('fake_cdn', $plugin->exposedResolvePublicAssetDisk());
        $this->assertSame('fake_cdn', $plugin->exposedResolvePublicAssetDisk(''));

        // override 우선
        $this->assertSame('public', $plugin->exposedResolvePublicAssetDisk('public'));

        // 'none' override → 스트리밍 강제
        $this->assertNull($plugin->exposedResolvePublicAssetDisk('none'));

        // 고아 디스크 → 스트리밍 폴백
        Config::set('core.storage.public_asset_disk', 'vanished_plugin_disk');
        $this->assertNull($plugin->exposedResolvePublicAssetDisk());
    }

    /**
     * getStorageUrl()/getStorageBasePath() 가 카테고리에 배선된 디스크를 따라야 합니다.
     *
     * 기본 디스크를 보던 시절에는 images 를 공개 자산 디스크로 옮긴 확장이 이 API 로
     * 항상 null 을 받았다 (공개 #134 전수조사 F4·F7).
     *
     * @effects url_returned_for_public_and_url_configured_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function storage_url_and_base_path_follow_category_disk(): void
    {
        $module = new class extends AbstractModule
        {
            /**
             * 이름을 반환합니다.
             *
             * @return array 배열
             */
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * 버전을 반환합니다.
             *
             * @return string 문자열
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 설명을 반환합니다.
             *
             * @return array 배열
             */
            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * getStorageDiskFor 을 처리합니다.
             *
             * @param  string  $category  category 값
             * @return string 문자열
             */
            public function getStorageDiskFor(string $category): string
            {
                return $category === 'images' ? 'fake_cdn' : $this->getStorageDisk();
            }
        };

        // 배선된 카테고리 → fake_cdn 의 url 설정을 따라 직접 URL
        $url = $module->getStorageUrl('images', 'a/b.png');
        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://cdn.test/assets', $url);

        // 미배선 카테고리는 기본 디스크(직접 URL 불가) → null
        $this->assertNull($module->getStorageUrl('settings', 'a/b.json'));

        // 기본 경로도 배선된 디스크 기준
        $this->assertStringContainsString('fake_cdn', $module->getStorageBasePath('images'));
        $this->assertStringNotContainsString('fake_cdn', $module->getStorageBasePath('settings'));
    }

    /**
     * 비공개 원본 바인딩 키(#916)는 「기본」이면 확장 기본 디스크, 선택하면 그 디스크, 고아 디스크면 기본으로 돌아간다.
     * 다른 카테고리(설정·공개 자산)는 이 선택을 따르지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=extension_binding_key
     *
     * @effects private_attachment_binding_key_follows_selection_only
     */
    #[Test]
    public function private_attachment_binding_follows_selection_only_for_its_key(): void
    {
        $module = $this->makeModule();
        $plugin = $this->makePlugin();

        Config::set('core.storage.private_attachment_disk', '');
        $this->assertSame('modules', $module->getStorageDiskFor(AbstractModule::PRIVATE_ATTACHMENT_BINDING));
        $this->assertSame('plugins', $plugin->getStorageDiskFor(AbstractPlugin::PRIVATE_ATTACHMENT_BINDING));

        Config::set('core.storage.private_attachment_disk', 'fake_cdn');
        $this->assertSame('fake_cdn', $module->getStorageDiskFor(AbstractModule::PRIVATE_ATTACHMENT_BINDING));
        $this->assertSame('fake_cdn', $plugin->getStorageDiskFor(AbstractPlugin::PRIVATE_ATTACHMENT_BINDING));
        $this->assertSame('fake_cdn', $module->getStorageFor(AbstractModule::PRIVATE_ATTACHMENT_BINDING)->getDisk());
        $this->assertSame('modules', $module->getStorageDiskFor('settings'));
        $this->assertSame('modules', $module->getStorageDiskFor('attachments'));

        Config::set('core.storage.private_attachment_disk', 'gone_disk');
        $this->assertSame('modules', $module->getStorageDiskFor(AbstractModule::PRIVATE_ATTACHMENT_BINDING));
    }

    /**
     * `.env` 고정(getPrivateAttachmentDiskPin)이 있으면 관리자 선택보다 그 디스크가 우선한다(#916 D7).
     *
     * @return void 반환값 없음
     *
     * @scenario case=env_pinned
     *
     * @effects private_attachment_env_pin_wins_over_selection
     */
    #[Test]
    public function private_attachment_pin_wins_over_selection(): void
    {
        Config::set('core.storage.private_attachment_disk', 'fake_cdn');
        $module = new class extends AbstractModule
        {
            /**
             * 이름을 반환합니다.
             *
             * @return array 배열
             */
            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * 버전을 반환합니다.
             *
             * @return string 문자열
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 설명을 반환합니다.
             *
             * @return array 배열
             */
            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            /**
             * getPrivateAttachmentDiskPin 을 처리합니다.
             *
             * @return ?array 배열
             */
            public function getPrivateAttachmentDiskPin(): ?array
            {
                return ['env' => 'FX_ATTACHMENT_DISK', 'disk' => 'local'];
            }
        };

        $this->assertSame('local', $module->getStorageDiskFor(AbstractModule::PRIVATE_ATTACHMENT_BINDING));
    }
}
