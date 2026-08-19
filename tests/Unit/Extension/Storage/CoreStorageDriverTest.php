<?php

namespace Tests\Unit\Extension\Storage;

use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CoreStorageDriver + url() 공개 URL 계약(ResolvesPublicUrl) 단위 테스트
 *
 * url() 의 직접 URL 판정(public / url 설정 디스크 / url 미설정)과
 * `core.storage.filter_url` 훅 계약(공급/수정/차단/컨텍스트)을 드라이버 3종에서 검증합니다.
 */
class CoreStorageDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 가짜 CDN 디스크 — S3 실체 없이 url 설정 디스크의 직접 URL 생성 검증
        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        Storage::disk('public')->deleteDirectory('core-url-test');

        parent::tearDown();
    }

    #[Test]
    public function it_can_put_get_and_delete_file(): void
    {
        $driver = new CoreStorageDriver('local');

        $this->assertTrue($driver->put('temp', 'core-test/file.txt', 'content'));
        $this->assertSame('content', $driver->get('temp', 'core-test/file.txt'));
        $this->assertTrue($driver->exists('temp', 'core-test/file.txt'));
        $this->assertTrue($driver->delete('temp', 'core-test/file.txt'));
        $this->assertFalse($driver->exists('temp', 'core-test/file.txt'));
    }

    /**
     * @scenario consumer=product, disk_setting=none, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=legacy_local_row
     *
     * @effects url_null_for_disk_without_url_config, download_url_falls_back_to_api_path_when_direct_unavailable
     */
    #[Test]
    public function it_returns_null_url_for_disk_without_url_config(): void
    {
        // local 디스크는 filesystems.disks.local.url 미설정 — 직접 URL 불가
        $driver = new CoreStorageDriver('local');

        $this->assertNull($driver->url('images', 'core-url-test/a.jpg'));
    }

    /**
     * @scenario consumer=product, disk_setting=public, e2e=drivers_tab_card, hook=unregistered, override=none_override, row_state=legacy_local_row
     *
     * @effects url_returned_for_public_and_url_configured_disk
     */
    #[Test]
    public function it_returns_url_for_public_disk(): void
    {
        // 기존 동작 보존 — public 디스크는 항상 직접 URL
        $driver = new CoreStorageDriver('public');

        $url = $driver->url('images', 'core-url-test/a.jpg');

        $this->assertNotNull($url);
        $this->assertStringContainsString('images/core-url-test/a.jpg', $url);
    }

    /**
     * @scenario consumer=category, disk_setting=fake_cdn, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects url_returned_for_public_and_url_configured_disk
     */
    #[Test]
    public function it_returns_url_for_disk_with_url_config(): void
    {
        $driver = new CoreStorageDriver('fake_cdn');

        $this->assertSame(
            'https://cdn.test/assets/images/core-url-test/a.jpg',
            $driver->url('images', 'core-url-test/a.jpg')
        );
    }

    /**
     * @scenario consumer=category, disk_setting=s3_without_url, e2e=save_roundtrip, hook=unregistered, override=none_override, row_state=legacy_local_row
     *
     * @effects blank_url_config_defended
     */
    #[Test]
    public function it_returns_null_url_when_disk_url_config_is_blank(): void
    {
        // AWS_URL 빈값/공백 방어 — url 이 빈 문자열이거나 공백뿐이면 직접 URL 불가.
        // 두 형태를 모두 고정한다: 구현이 trim() 단일 분기라 지금은 같은 경로를 타지만,
        // 판정이 `!== ''` 로 좁혀지면 공백 케이스만 깨지므로 한쪽만으로는 검출되지 않는다.
        foreach (['', '   '] as $blank) {
            Config::set('filesystems.disks.fake_cdn.url', $blank);
            $driver = new CoreStorageDriver('fake_cdn');

            $this->assertNull(
                $driver->url('images', 'core-url-test/a.jpg'),
                sprintf('url 설정이 %s 이면 직접 URL 을 만들지 않아야 한다', var_export($blank, true))
            );
        }
    }

    #[Test]
    public function module_and_plugin_drivers_return_url_for_url_configured_disk(): void
    {
        $moduleDriver = new ModuleStorageDriver('test-module', 'fake_cdn');
        $pluginDriver = new PluginStorageDriver('test-plugin', 'fake_cdn');

        $this->assertSame(
            'https://cdn.test/assets/test-module/images/products/1/a.jpg',
            $moduleDriver->url('images', 'products/1/a.jpg')
        );
        $this->assertSame(
            'https://cdn.test/assets/test-plugin/images/2026/01/01/b.png',
            $pluginDriver->url('images', '2026/01/01/b.png')
        );
    }

    /**
     * @scenario consumer=editor, disk_setting=s3_without_url, e2e=drivers_tab_card, hook=supply, override=none_override, row_state=new_remote_row
     *
     * @effects filter_url_hook_always_fires_with_six_context_keys, hook_supply_modify_block_respected
     */
    #[Test]
    public function filter_url_hook_fires_even_when_url_is_null(): void
    {
        // 확정 #5 — null 일 때도 항상 발화 (훅이 URL 을 공급할 수 있다)
        HookManager::addFilter('core.storage.filter_url', function ($url, array $context) {
            $this->assertNull($url);

            return 'https://hook.test/supplied/'.$context['path'];
        });

        $driver = new CoreStorageDriver('local');

        $this->assertSame(
            'https://hook.test/supplied/a.jpg',
            $driver->url('images', 'a.jpg')
        );
    }

    /**
     * @scenario consumer=product, disk_setting=fake_cdn, e2e=ecommerce_settings_field, hook=supply, override=none_override, row_state=legacy_local_row
     *
     * @effects hook_supply_modify_block_respected
     */
    #[Test]
    public function filter_url_hook_can_modify_generated_url(): void
    {
        HookManager::addFilter('core.storage.filter_url', function ($url) {
            return str_replace('https://cdn.test', 'https://cdn2.test', (string) $url);
        });

        $driver = new CoreStorageDriver('fake_cdn');

        $this->assertSame(
            'https://cdn2.test/assets/images/a.jpg',
            $driver->url('images', 'a.jpg')
        );
    }

    /**
     * @scenario consumer=review, disk_setting=fake_cdn, e2e=save_roundtrip, hook=block, override=none_override, row_state=legacy_local_row
     *
     * @effects hook_supply_modify_block_respected
     */
    #[Test]
    public function filter_url_hook_can_block_url_by_returning_empty(): void
    {
        // 반환이 빈문자열/비문자열이면 호출측 스트리밍 폴백 (null)
        HookManager::addFilter('core.storage.filter_url', fn ($url) => '');

        $driver = new CoreStorageDriver('fake_cdn');

        $this->assertNull($driver->url('images', 'a.jpg'));
    }

    /**
     * @effects hook_supply_modify_block_respected
     */
    #[Test]
    public function filter_url_hook_non_string_or_blank_returns_fall_back_to_null(): void
    {
        // 훅이 계약 밖의 값을 돌려줘도 예외 없이 스트리밍 폴백 (방어 계약)
        foreach ([null, false, ['url' => 'https://x.test/a.jpg'], '   '] as $badReturn) {
            HookManager::resetAll();
            HookManager::addFilter('core.storage.filter_url', fn ($url) => $badReturn);

            $driver = new CoreStorageDriver('fake_cdn');

            $this->assertNull(
                $driver->url('images', 'a.jpg'),
                '훅 반환값 '.var_export($badReturn, true).' 은 null 폴백이어야 한다'
            );
        }
    }

    /**
     * @scenario consumer=review, disk_setting=none, e2e=drivers_tab_card, hook=supply, override=none_override, row_state=legacy_local_row
     *
     * @effects filter_url_hook_always_fires_with_six_context_keys
     */
    #[Test]
    public function filter_url_hook_receives_six_context_keys(): void
    {
        $captured = null;
        HookManager::addFilter('core.storage.filter_url', function ($url, array $context) use (&$captured) {
            $captured = $context;

            return $url;
        });

        (new CoreStorageDriver('fake_cdn'))->url('images', 'sub/a.jpg');

        $this->assertSame([
            'scope' => 'core',
            'identifier' => null,
            'disk' => 'fake_cdn',
            'category' => 'images',
            'path' => 'sub/a.jpg',
            'full_path' => 'images/sub/a.jpg',
        ], $captured);
    }

    #[Test]
    public function filter_url_hook_context_identifies_module_and_plugin_scope(): void
    {
        $contexts = [];
        HookManager::addFilter('core.storage.filter_url', function ($url, array $context) use (&$contexts) {
            $contexts[] = $context;

            return $url;
        });

        (new ModuleStorageDriver('test-module', 'local'))->url('images', 'a.jpg');
        (new PluginStorageDriver('test-plugin', 'local'))->url('images', 'b.jpg');

        $this->assertSame('module', $contexts[0]['scope']);
        $this->assertSame('test-module', $contexts[0]['identifier']);
        $this->assertSame('test-module/images/a.jpg', $contexts[0]['full_path']);
        $this->assertSame('plugin', $contexts[1]['scope']);
        $this->assertSame('test-plugin', $contexts[1]['identifier']);
        $this->assertSame('test-plugin/images/b.jpg', $contexts[1]['full_path']);
    }
}
