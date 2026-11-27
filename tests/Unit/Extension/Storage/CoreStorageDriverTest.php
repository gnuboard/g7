<?php

namespace Tests\Unit\Extension\Storage;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use App\Support\AssetUrl;
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
    /**
     * url 이 설정된 가짜 CDN 디스크(fake_cdn)를 local 드라이버로 등록한다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 등록된 훅을 비우고 public 디스크에 만든 테스트 디렉토리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        Storage::disk('public')->deleteDirectory('core-url-test');

        parent::tearDown();
    }

    /**
     * 코어 드라이버로 파일을 쓰고 읽고 존재를 확인한 뒤 지우면 더는 존재하지 않는지 확인한다.
     *
     * @effects core_storage_driver_put_get_delete_roundtrip
     *
     * @return void 반환값 없음
     */
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
     * url 설정이 없는 local 디스크는 직접 URL 을 만들지 않고 null 을 돌려주는지 확인한다.
     *
     * @scenario consumer=product, disk_setting=none, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=legacy_local_row
     *
     * @effects url_null_for_disk_without_url_config, download_url_falls_back_to_api_path_when_direct_unavailable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_returns_null_url_for_disk_without_url_config(): void
    {
        // local 디스크는 filesystems.disks.local.url 미설정 — 직접 URL 불가
        $driver = new CoreStorageDriver('local');

        $this->assertNull($driver->url('images', 'core-url-test/a.jpg'));
    }

    /**
     * 링크가 쓸 수 있는 표준 배치에서 public 디스크 URL 이 종전과 같은 직접 URL 형태 그대로인지 확인한다.
     *
     * @scenario consumer=product, disk_setting=public, e2e=drivers_tab_card, hook=unregistered, override=none_override, row_state=legacy_local_row
     *
     * @effects url_returned_for_public_and_url_configured_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_returns_url_for_public_disk(): void
    {
        // 기존 동작 보존 — 링크가 있는 표준 배치에서 public 디스크는 **종전과 같은 직접
        // URL** 이어야 한다. 경로 조각만 확인하면(`assertStringContainsString`) 그 조각이
        // 대체 서빙 URL(`/api/system/public-storage/images/…`)에도 들어 있어, 링크 판정이
        // 뒤집혀도 초록이다 — 「형태를 보존한다」고 선언한 자리가 아무것도 잠그지 않는다.
        StorageLinkHelper::forcePublicStorageLinkUsable(true);
        $driver = new CoreStorageDriver('public');

        $this->assertSame(
            rtrim((string) config('filesystems.disks.public.url'), '/').'/images/core-url-test/a.jpg',
            $driver->url('images', 'core-url-test/a.jpg')
        );
    }

    /**
     * url 이 설정된 디스크는 그 주소 아래에 카테고리와 경로를 붙인 직접 URL 을 돌려주는지 확인한다.
     *
     * @scenario consumer=category, disk_setting=fake_cdn, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects url_returned_for_public_and_url_configured_disk
     *
     * @return void 반환값 없음
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
     * 디스크 url 설정이 빈 문자열이거나 공백뿐이면 직접 URL 을 만들지 않는지 확인한다.
     *
     * @scenario consumer=category, disk_setting=s3_without_url, e2e=save_roundtrip, hook=unregistered, override=none_override, row_state=legacy_local_row
     *
     * @effects blank_url_config_defended
     *
     * @return void 반환값 없음
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

    /**
     * 하위 폴더 마운트(`https://host/g7/`)에서도 대체 서빙 전환이 성립한다 (B-3 · 레이아웃 ③).
     *
     * `config/filesystems.php` 의 기본식은 `env('APP_URL').'/storage'` 라, 그 배치의
     * public 디스크 URL 경로는 `/g7/storage` 다. 판정이 `=== '/storage'` 로 고정돼 있으면
     * 그 배치는 언제나 「운영자가 다른 위치로 지정했다」로 오분류되어 **대체 서빙이 절대
     * 켜지지 않는다** — 링크를 만들 수 없는 그 사이트의 공개 이미지가 전부 404 가 되고,
     * 오류도 로그도 남지 않는다. 하위 폴더 마운트는 이 저장소가 1급으로 지원하는 배치다.
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_disk_url_switches_to_the_serving_route_in_a_subfolder_mount(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        Config::set('app.url', 'https://host.test/g7');
        Config::set('filesystems.disks.public.url', 'https://host.test/g7/storage');
        AssetUrl::forceBasePrefix('/g7');

        try {
            $url = (string) (new CoreStorageDriver('public'))->url('images', 'core-url-test/a.jpg');

            $this->assertSame(
                '/g7/api/system/public-storage/images/core-url-test/a.jpg',
                parse_url($url, PHP_URL_PATH),
                '하위 폴더 배치에서 링크가 없으면 대체 서빙 라우트로 전환돼야 한다'
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * `APP_URL` 이 슬래시로 끝나도 대체 서빙 판정이 성립해야 한다.
     *
     * `config/filesystems.php` 의 기본식은 `env('APP_URL').'/storage'` 다. 운영자가
     * `APP_URL=https://host.test/` 처럼 슬래시로 끝맺는 것은 흔한 표기인데, 그러면
     * 파생값이 `https://host.test//storage` 가 되어 경로가 `//storage` 로 온다.
     * 슬래시를 접기 전에는 `=== '/storage'` 가 거짓이라 **대체 서빙이 켜지지 않았고**,
     * 링크를 만들 수 없는 호스팅에서 그 사이트의 공개 이미지가 전부 404 가 됐다 —
     * 오류도 로그도 없이 슬래시 하나가 G3 의 핵심 기능 전체를 끄는 형태였다.
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_disk_url_switches_to_the_serving_route_when_app_url_has_a_trailing_slash(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        Config::set('app.url', 'https://host.test/');
        // `env('APP_URL', …).'/storage'` 가 실제로 만들어 내는 값이다.
        Config::set('filesystems.disks.public.url', 'https://host.test//storage');

        $url = (string) (new CoreStorageDriver('public'))->url('images', 'core-url-test/a.jpg');

        $this->assertSame(
            '/api/system/public-storage/images/core-url-test/a.jpg',
            parse_url($url, PHP_URL_PATH),
            'APP_URL 후행 슬래시로 생긴 `//storage` 도 표준 위치로 읽혀야 한다'
        );
    }

    /**
     * 하위 폴더 + 후행 슬래시가 겹쳐도 성립해야 한다 (두 정규화가 함께 걸리는 자리).
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_disk_url_keeps_a_neighbouring_prefix_without_a_boundary(): void
    {
        // 접두사 판정을 `AssetUrl::hasBasePrefix()` 한 곳으로 접었다 — 그 판정은 접두사
        // 뒤가 끝·`/`·`?`·`#` 일 때만 보유다. `/g7x/storage` 는 `/g7` 로 시작하지만
        // 경계 없이 이어지는 **다른 위치**라, 벗기면 `x/storage` 라는 값이 표준 위치로
        // 오인될 여지가 생긴다. 운영자가 다른 위치로 지정한 것으로 읽어 링크 판정을
        // 우선시켜야 한다(정적 `/storage` 유지, 대체 서빙 전환 없음).
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        Config::set('app.url', 'https://host.test/g7');
        Config::set('filesystems.disks.public.url', 'https://host.test/g7x/storage');
        AssetUrl::forceBasePrefix('/g7');

        try {
            $url = (string) (new CoreStorageDriver('public'))->url('images', 'core-url-test/a.jpg');

            $this->assertSame(
                '/g7x/storage/images/core-url-test/a.jpg',
                parse_url($url, PHP_URL_PATH),
                '경계 없이 이어지는 이웃 접두사는 벗기지 않아야 한다 — 운영자 지정 위치로 읽는다'
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 링크가 없고 하위 폴더 마운트의 앱 주소에 꼬리 슬래시가 있어도 public 디스크 URL 이 접두사 한 번만 붙은 대체 서빙 경로로 바뀌는지 확인한다.
     *
     * @scenario storage_link=missing, base_path=subpath
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_disk_url_switches_to_the_serving_route_in_a_subfolder_mount_with_trailing_slash(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        Config::set('app.url', 'https://host.test/g7/');
        Config::set('filesystems.disks.public.url', 'https://host.test/g7//storage');
        AssetUrl::forceBasePrefix('/g7');

        try {
            $url = (string) (new CoreStorageDriver('public'))->url('images', 'core-url-test/a.jpg');

            $this->assertSame(
                '/g7/api/system/public-storage/images/core-url-test/a.jpg',
                parse_url($url, PHP_URL_PATH),
                '접두사 벗기기와 슬래시 접기가 함께 걸려야 한다'
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 접두사를 벗기는 판정이 **진짜 다른 위치**까지 로컬로 읽으면 안 된다 (위 케이스의 대조군).
     *
     * 이 대조군이 없으면 「무조건 대체 서빙으로 전환」하는 구현으로도 위 테스트가 초록이
     * 되고, CDN 으로 돌린 사이트의 공개 자산이 통째로 앱 오리진으로 끌려온다.
     *
     * @scenario storage_link=missing
     *
     * @effects public_disk_url_keeps_operator_configured_location_without_a_link
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_disk_url_keeps_an_operator_configured_location_even_without_a_link(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        Config::set('app.url', 'https://host.test/g7');
        Config::set('filesystems.disks.public.url', 'https://cdn.test/media');
        AssetUrl::forceBasePrefix('/g7');

        try {
            $this->assertSame(
                'https://cdn.test/media/images/core-url-test/a.jpg',
                (new CoreStorageDriver('public'))->url('images', 'core-url-test/a.jpg'),
                '운영자가 지정한 위치는 로컬 링크 유무와 무관하다'
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 모듈과 플러그인 드라이버도 url 설정 디스크에서 식별자 경로를 붙인 직접 URL 을 돌려주는지 확인한다.
     *
     * @effects url_returned_for_public_and_url_configured_disk
     *
     * @return void 반환값 없음
     */
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
     * 직접 URL 이 null 이어도 filter_url 훅이 불리고, 훅이 공급한 URL 이 그대로 쓰이는지 확인한다.
     *
     * @scenario consumer=editor, disk_setting=s3_without_url, e2e=drivers_tab_card, hook=supply, override=none_override, row_state=new_remote_row
     *
     * @effects filter_url_hook_always_fires_with_six_context_keys, hook_supply_modify_block_respected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function filter_url_hook_fires_even_when_url_is_null(): void
    {
        // 확정 5 — null 일 때도 항상 발화 (훅이 URL 을 공급할 수 있다)
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
     * filter_url 훅이 생성된 직접 URL 을 바꾸면 그 값이 돌아오는지 확인한다.
     *
     * @scenario consumer=product, disk_setting=fake_cdn, e2e=ecommerce_settings_field, hook=supply, override=none_override, row_state=legacy_local_row
     *
     * @effects hook_supply_modify_block_respected
     *
     * @return void 반환값 없음
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
     * filter_url 훅이 빈 문자열을 돌려주면 URL 이 null 로 막혀 스트리밍 폴백으로 가는지 확인한다.
     *
     * @scenario consumer=review, disk_setting=fake_cdn, e2e=save_roundtrip, hook=block, override=none_override, row_state=legacy_local_row
     *
     * @effects hook_supply_modify_block_respected
     *
     * @return void 반환값 없음
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
     * filter_url 훅이 null, false, 배열, 공백 같은 계약 밖 값을 돌려줘도 예외 없이 null 로 떨어지는지 확인한다.
     *
     * @effects hook_supply_modify_block_respected
     *
     * @return void 반환값 없음
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
     * filter_url 훅이 scope, identifier, disk, category, path, full_path 여섯 키의 문맥을 받는지 확인한다.
     *
     * @scenario consumer=review, disk_setting=none, e2e=drivers_tab_card, hook=supply, override=none_override, row_state=legacy_local_row
     *
     * @effects filter_url_hook_always_fires_with_six_context_keys
     *
     * @return void 반환값 없음
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

    /**
     * 모듈과 플러그인 드라이버가 훅 문맥에 자기 scope 와 식별자, 식별자가 붙은 full_path 를 싣는지 확인한다.
     *
     * @effects filter_url_hook_always_fires_with_six_context_keys
     *
     * @return void 반환값 없음
     */
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
