<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Support\PrivateAttachmentDisk;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\ActsAsSettingsAdmin;
use Tests\TestCase;

/**
 * 환경설정 > 드라이버 「비공개 첨부 저장소」 저장·주입·화면 메타 
 *
 * 카탈로그 = 코어(default·s3) + 플러그인이 `core.settings.available_private_attachment_drivers` 필터로 등록한 디스크.
 * 「기본」은 주입 때 빈 값으로 정규화해 지금까지의 디스크를 따른다. 공개 자산과 같은 디스크는 저장은 되지만 응답 warnings 로 알린다(D5).
 * `.env` 로 디스크를 고정한 주체는 `_meta.private_attachment.pins` 로 내보낸다(D7).
 */
class PrivateAttachmentStorageSettingsTest extends TestCase
{
    use ActsAsSettingsAdmin;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 관리자를 준비하고 플러그인 비공개 디스크를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSettingsAdmin();
        Config::set('filesystems.disks.fx916-vault', ['driver' => 'local', 'root' => storage_path('framework/testing/fx916-vault')]);
        HookManager::addFilter('core.settings.available_private_attachment_drivers', function (array $drivers) {
            $drivers[] = ['id' => 'fx916-vault', 'label' => ['ko' => '시험 금고', 'en' => 'Test vault'], 'provider' => 'fx-vault'];

            return $drivers;
        });
        HookManager::addFilter('core.settings.available_public_asset_drivers', function (array $drivers) {
            $drivers[] = ['id' => 'fx916-vault', 'label' => ['ko' => '시험 금고', 'en' => 'Test vault'], 'provider' => 'fx-vault'];

            return $drivers;
        });
    }

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
     * 설정 조회 응답에 카탈로그(기본·s3·플러그인 디스크)와 화면 메타가 실린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_catalog_exposed
     *
     * @effects private_attachment_settings_expose_catalog_and_meta
     */
    public function test_index_exposes_catalog_and_meta(): void
    {
        $response = $this->asSettingsAdmin()->getJson('/api/admin/settings')->assertStatus(200);

        $this->assertSame(['default', 's3', 'fx916-vault'], array_column($response->json('data.available_drivers.private_attachment'), 'id'));
        $this->assertIsArray($response->json('data._meta.private_attachment.pins'));
        $this->assertFalse($response->json('data._meta.private_attachment.orphaned'));
    }

    /**
     * 카탈로그 값은 저장되고, 주입하면 「기본」은 빈 값, 플러그인 디스크는 그대로 들어가 해석기가 그 디스크를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=catalog_disk_saved
     *
     * @effects private_attachment_saved_value_reaches_resolver
     */
    public function test_saved_value_reaches_resolver_through_injection(): void
    {
        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['private_attachment_disk' => 'fx916-vault']),
        ])->assertStatus(200)->assertJsonPath('data.warnings', []);
        $this->assertSame('fx916-vault', (new JsonConfigRepository)->getCategory('drivers')['private_attachment_disk'] ?? null);

        $this->inject();
        $this->assertSame('fx916-vault', config('core.storage.private_attachment_disk'));
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::resolve());
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::forExtension('modules'));

        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['private_attachment_disk' => 'default']),
        ])->assertStatus(200);
        $this->inject();
        $this->assertSame('', config('core.storage.private_attachment_disk'));
        $this->assertNull(PrivateAttachmentDisk::resolve());
        $this->assertSame('modules', PrivateAttachmentDisk::forExtension('modules'));
    }

    /**
     * 카탈로그에 없는 디스크는 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unknown_disk
     *
     * @effects private_attachment_unknown_disk_rejected_with_422
     */
    public function test_unknown_disk_is_rejected(): void
    {
        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['private_attachment_disk' => 'fx916-ghost']),
        ])->assertStatus(422)->assertJsonValidationErrors(['drivers.private_attachment_disk']);
    }

    /**
     * 공개 자산과 같은 디스크면 저장은 되고 warnings 에 그 필드 경고가 한 건 실린다. 「기본」·「사용 안 함」끼리는 경고하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=same_disk_as_public_asset
     *
     * @effects private_attachment_same_disk_as_public_asset_saves_with_warning
     */
    public function test_same_disk_as_public_asset_saves_with_warning(): void
    {
        $response = $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['private_attachment_disk' => 'fx916-vault', 'public_asset_disk' => 'fx916-vault']),
        ])->assertStatus(200);

        $warnings = $response->json('data.warnings');
        $this->assertCount(1, $warnings);
        $this->assertSame('drivers.private_attachment_disk', $warnings[0]['key']);
        $this->assertStringContainsString('fx916-vault', $warnings[0]['message']);

        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['private_attachment_disk' => 'default', 'public_asset_disk' => 'none']),
        ])->assertStatus(200)->assertJsonPath('data.warnings', []);
    }

    /**
     * `.env` 고정(코어 ATTACHMENT_DISK)이 있으면 메타 pins 에 실리고, 코어 첨부 디스크는 선택보다 그 값이 우선한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=env_pinned
     *
     * @effects private_attachment_env_pin_exposed_and_wins_for_core
     */
    public function test_env_pin_is_exposed_and_wins_for_core_attachments(): void
    {
        Config::set('attachment.disk_explicit', 'attachments');
        Config::set('core.storage.private_attachment_disk', 'fx916-vault');

        $pins = $this->asSettingsAdmin()->getJson('/api/admin/settings')->json('data._meta.private_attachment.pins');
        $this->assertContains(['owner' => 'core', 'env' => 'ATTACHMENT_DISK', 'disk' => 'attachments'], $pins);
        $this->assertSame('attachments', PrivateAttachmentDisk::forCore());

        Config::set('attachment.disk_explicit', null);
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::forCore());
    }

    /**
     * 실제 저장 산출물(drivers.json)을 읽어 주입부를 호출합니다(testing 격리 가드 뒤의 주입부).
     *
     * @return void 반환값 없음
     */
    private function inject(): void
    {
        $provider = new SettingsServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'injectPrivateAttachmentDiskConfig');
        $method->invoke($provider, new JsonConfigRepository);
    }
}
