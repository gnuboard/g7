<?php

namespace Tests\Feature\View;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Http\View\Composers\TemplateComposer;
use App\Support\CustomAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 사용자 추가 에셋의 프론트 주입 테스트
 *
 * 서버가 해석한 목록이 프론트까지 도달하지 않으면 `custom/` 에 파일을 넣어도 아무 일도
 * 일어나지 않는다 — 오류도 로그도 없이 "안 먹는다" 로만 나타난다. 배선의 존재와
 * **순서**를 함께 본다: 확장 간 순서는 모듈 → 플러그인 → 템플릿이어야 한다.
 */
class CustomAssetInjectionTest extends TestCase
{
    /** 테스트용 가짜 플러그인 식별자 */
    private const FAKE_PLUGIN = 'g7test-injection';

    protected function setUp(): void
    {
        parent::setUp();

        CustomAssets::flushCache();

        File::ensureDirectoryExists(base_path('plugins/'.self::FAKE_PLUGIN.'/custom'));
        File::put(base_path('plugins/'.self::FAKE_PLUGIN.'/custom/custom.css'), '/* operator */');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/'.self::FAKE_PLUGIN));
        CustomAssets::flushCache();

        parent::tearDown();
    }

    /**
     * 탈출구(D33) — `?custom=off` 요청은 목록을 비운다.
     *
     * 운영자가 넣은 CSS 한 줄이 관리자 화면을 조작 불능으로 만들면, 그것을 고칠 화면에도
     * 그 CSS 가 실려 있어 스스로 갇힌다. 서버가 목록을 비우면 자산이 페이지에 **도달하지
     * 않으므로**, 이미 깨진 화면에서 자바스크립트가 돌기를 기대할 필요가 없다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_assets_disabled_by_request_parameter
     */
    #[Test]
    public function custom_off_요청은_수집_결과를_비운다(): void
    {
        $this->mock(PluginManager::class, function ($mock) {
            $mock->shouldReceive('getActivePlugins')->andReturn([self::FAKE_PLUGIN => []]);
            $mock->shouldReceive('loadPlugins')->andReturnNull();
        });

        CustomAssets::flushCache();

        $composer = app(TemplateComposer::class);
        $collect = new ReflectionMethod(TemplateComposer::class, 'collectCustomAssets');

        // 평소에는 실린다 (대조군이 없으면 빈 결과가 "원래 없었다" 와 구분되지 않는다)
        $this->assertNotEmpty($collect->invoke($composer, null));

        // `?custom=off` 이면 비운다
        $this->app['request'] = Request::create('/admin/layout-editor/x', 'GET', ['custom' => 'off']);
        CustomAssets::flushCache();

        $this->assertSame([], $collect->invoke($composer, null));
    }

    /**
     * 화면이 "지금 꺼져 있다" 를 알 수 있어야 한다.
     *
     * SPA 부팅이 주소를 다시 쓰면서 `?custom=off` 를 지우므로, 화면이 URL 로 판정하면
     * 자산은 꺼졌는데 버튼은 "켜져 있음" 으로 표시되고 되돌릴 방법이 사라진다.
     * 서버가 자기가 한 일을 그대로 심어야 한다.
     *
     * @effects custom_assets_disabled_by_request_parameter
     */
    #[Test]
    public function 서버가_custom_off_상태를_화면에_알린다(): void
    {
        $composer = app(TemplateComposer::class);
        $judge = new ReflectionMethod(TemplateComposer::class, 'customAssetsDisabledByRequest');

        $this->app['request'] = Request::create('/admin', 'GET');
        $this->assertFalse($judge->invoke($composer));

        $this->app['request'] = Request::create('/admin', 'GET', ['custom' => 'off']);
        $this->assertTrue($judge->invoke($composer));
    }

    /**
     * 판정이 변경 감지보다 **앞**에 있어야 한다.
     *
     * 뒤에 두면 빈 목록이 "전부 사라짐" 으로 읽혀 캐시 버전이 오르고, 다음 정상 요청이
     * 다시 "전부 생김" 으로 읽어 또 오른다 — 요청마다 버전이 오르내리며 재게시가 끝없이
     * 돈다.
     *
     * @effects custom_assets_disabled_by_request_parameter
     */
    #[Test]
    public function custom_off_요청은_캐시_버전을_건드리지_않는다(): void
    {
        $this->mock(PluginManager::class, function ($mock) {
            $mock->shouldReceive('getActivePlugins')->andReturn([self::FAKE_PLUGIN => []]);
            $mock->shouldReceive('loadPlugins')->andReturnNull();
        });

        $cache = app(CacheInterface::class);
        $cache->put('ext.cache_version', 1);
        $cache->forget(CustomAssets::SIGNATURE_CACHE_KEY);

        $this->app['request'] = Request::create('/admin', 'GET', ['custom' => 'off']);
        CustomAssets::flushCache();

        $composer = app(TemplateComposer::class);
        $collect = new ReflectionMethod(TemplateComposer::class, 'collectCustomAssets');
        $collect->invoke($composer, null);
        $collect->invoke($composer, null);

        $this->assertSame(1, (int) $cache->get('ext.cache_version'));
        $this->assertNull($cache->get(CustomAssets::SIGNATURE_CACHE_KEY));
    }

    /**
     * @scenario custom_source=assets_json, custom_asset=css
     *
     * @effects custom_asset_loaded_after_extension_bundles
     */
    #[Test]
    public function 필터_훅으로_다른_출처의_항목을_더할_수_있다(): void
    {
        // 7.1.0 템플릿 환경설정이 화면에서 입력한 CSS 를 실어 보낼 통로다.
        // 이 훅이 없으면 그때 코어 해석기를 다시 열어야 하고, 그 순간 "운영자 CSS 가
        // 어디서 오는가" 의 SSoT 가 둘로 갈린다.
        HookManager::addFilter(
            'core.assets.custom_assets',
            function (array $assets, string $type, string $identifier): array {
                $assets[] = [
                    'id' => 'custom:settings:'.$identifier,
                    'type' => 'style',
                    'url' => '/api/'.$type.'/settings-css',
                    'version' => 42,
                    'source' => 'settings',
                ];

                return $assets;
            },
            10,
            3
        );

        CustomAssets::flushCache();

        $assets = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN);

        $sources = array_column($assets, 'source');

        $this->assertContains('file', $sources, '파일 출처 항목이 사라졌습니다.');
        $this->assertContains('settings', $sources, '필터 훅으로 더한 항목이 반영되지 않았습니다.');

        // 파일 출처가 먼저 온다 — 화면에서 방금 입력한 것이 파일보다 뒤여야
        // "고쳤는데 안 먹는다" 가 생기지 않는다
        $this->assertSame('file', $sources[0]);
        $this->assertSame('settings', $sources[count($sources) - 1]);
    }

    /**
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_loaded_after_extension_bundles
     */
    #[Test]
    public function 서술자는_프론트가_읽는_형태를_그대로_갖춘다(): void
    {
        // 프론트 로더(parseCustomAssetsFromConfig)는 id·type·url 셋으로 항목을 판정한다.
        // 하나라도 빠지면 그 항목은 조용히 버려진다.
        $asset = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN)[0];

        $this->assertIsString($asset['id']);
        $this->assertContains($asset['type'], ['style', 'script']);
        $this->assertIsString($asset['url']);
        $this->assertNotSame('', $asset['url']);
    }

    /**
     * 확장 간 순서: 모듈 → 플러그인 → 템플릿.
     *
     * CSS 는 나중에 온 규칙이 이긴다. 템플릿이 화면 외관의 최종 책임을 지므로 템플릿
     * 운영자의 재정의가 가장 뒤에 와야 한다. 순서가 뒤집히면 오류 없이 "재정의가
     * 안 먹는다" 로만 나타난다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_loaded_after_extension_bundles
     */
    #[Test]
    public function 확장_간_순서는_모듈_플러그인_템플릿이다(): void
    {
        // 활성 목록을 고정한다 — 테스트 DB 에 무엇이 설치되어 있는지에 순서 검증이
        // 좌우되면, 설치본이 비었을 때 수집 결과가 비어 단언이 공허하게 통과한다.
        $module = 'g7test-order-module';
        $plugin = 'g7test-order-plugin';
        $template = 'g7test-order-template';

        $this->mock(ModuleManager::class, function ($mock) use ($module) {
            $mock->shouldReceive('getActiveModules')->andReturn([$module => []]);
            $mock->shouldReceive('loadModules')->andReturnNull();
        });

        $this->mock(PluginManager::class, function ($mock) use ($plugin) {
            $mock->shouldReceive('getActivePlugins')->andReturn([$plugin => []]);
            $mock->shouldReceive('loadPlugins')->andReturnNull();
        });

        $created = [
            'modules' => base_path('modules/'.$module.'/custom'),
            'plugins' => base_path('plugins/'.$plugin.'/custom'),
            'templates' => base_path('templates/'.$template.'/custom'),
        ];

        try {
            foreach ($created as $type => $dir) {
                File::ensureDirectoryExists($dir);
                File::put($dir.'/zz-order-probe.css', '/* '.$type.' */');
            }

            CustomAssets::flushCache();

            $composer = app(TemplateComposer::class);
            $collect = new ReflectionMethod(TemplateComposer::class, 'collectCustomAssets');
            $assets = $collect->invoke($composer, $template);

            // 이 테스트가 놓은 프로브만 골라 순서를 본다 — 다른 운영자 파일이 있어도 무관.
            $observed = [];

            foreach ($assets as $asset) {
                if (! str_contains($asset['id'], 'zz-order-probe.css')) {
                    continue;
                }

                // id 형식: custom:{type}:{identifier}:{file}
                $observed[] = explode(':', $asset['id'])[1];
            }

            $this->assertSame(
                ['modules', 'plugins', 'templates'],
                $observed,
                '확장 간 순서가 모듈 → 플러그인 → 템플릿이 아닙니다. '
                .'CSS 는 나중에 온 규칙이 이기므로 템플릿 재정의가 가장 뒤여야 합니다.'
            );
        } finally {
            foreach ($created as $dir) {
                File::deleteDirectory($dir);
            }

            CustomAssets::flushCache();
        }
    }

    /**
     * 관리자 렌더와 사용자 렌더가 **하나의 서명 키를 서로 덮어쓰지 않는다.**
     *
     * 서명은 수집된 서술자로 만드는데, 그 수집은 모듈·플러그인(양쪽 동일)에 더해
     * **그 렌더의 템플릿 하나**만 싣는다(`resolveCustomAssets`). 관리자 렌더는 admin
     * 템플릿, 사용자 렌더는 user 템플릿이라 두 서명은 정상적으로 다르다. 그런데 저장
     * 키가 하나뿐이면 두 렌더가 번갈아 덮어쓰며 매번 "파일이 바뀌었다" 로 읽힌다.
     *
     * 그 오독의 대가는 `incrementExtensionCacheVersion()` 이다 — 확장 캐시 버전이 오르고
     * 정적 재게시가 예약된다. 운영자가 아무것도 건드리지 않았는데 관리자·사용자 페이지를
     * 오갈 때마다 모든 자산 URL 이 바뀌어 브라우저 캐시가 상시 무효가 되고 전체 재게시가
     * 반복된다. 예외도 화면 이상도 없어 로그 외에는 드러나지 않는다.
     *
     * `?custom=off` 진동은 `customAssetsDisabledByRequest` 가 이미 막고 있다 — 같은
     * 진동의 다른 원인인 admin/user 템플릿 비대칭만 열려 있었다.
     *
     * @scenario custom_source=convention_scan, custom_ext_type=template
     *
     * @effects custom_asset_signature_is_scoped_per_render
     */
    #[Test]
    public function 관리자_사용자_렌더가_서명_키를_서로_덮어쓰지_않는다(): void
    {
        $adminTemplate = 'g7test-admin_tpl';
        $userTemplate = 'g7test-user_tpl';

        foreach ([$adminTemplate, $userTemplate] as $identifier) {
            File::ensureDirectoryExists(base_path('templates/'.$identifier.'/custom'));
            File::put(base_path('templates/'.$identifier.'/custom/custom.css'), '/* '.$identifier.' */');
        }

        try {
            $composer = app(TemplateComposer::class);
            $resolve = new ReflectionMethod(TemplateComposer::class, 'resolveCustomAssets');
            $sync = new ReflectionMethod(TemplateComposer::class, 'syncCustomAssetCacheVersion');
            $collect = new ReflectionMethod(TemplateComposer::class, 'collectCustomAssets');
            $cache = app(CacheInterface::class);

            CustomAssets::flushCache();
            $adminAssets = $resolve->invoke($composer, $adminTemplate);
            $userAssets = $resolve->invoke($composer, $userTemplate);

            // 두 렌더의 수집 결과가 실제로 다르다 — 같으면 이 테스트는 공허하다
            $this->assertNotSame($adminAssets, $userAssets);

            $cache->forget(CustomAssets::SIGNATURE_CACHE_KEY);

            // 실제 진입점으로 두 렌더를 번갈아 태운다 (스코프 전달 누락도 함께 잡는다)
            foreach ([$adminTemplate, $userTemplate, $adminTemplate, $userTemplate] as $identifier) {
                CustomAssets::flushCache();
                $collect->invoke($composer, $identifier);
            }

            // 두 렌더의 서명이 **각자** 기억된다 — 하나의 자리를 서로 덮어쓰지 않는다
            $stored = $cache->get(CustomAssets::SIGNATURE_CACHE_KEY);

            $this->assertIsArray($stored, '서명이 스코프별로 기억되지 않는다 (단일 값으로 덮어쓰기)');
            $this->assertArrayHasKey($adminTemplate, $stored);
            $this->assertArrayHasKey($userTemplate, $stored);
            $this->assertNotSame($stored[$adminTemplate], $stored[$userTemplate]);

            // 파일은 그대로다 — 렌더를 오간다고 버전이 올라서는 안 된다
            $this->assertFalse(
                $sync->invoke($composer, $adminAssets, $adminTemplate),
                '관리자 렌더가 사용자 렌더의 서명을 덮어써 파일 변경으로 오독했다'
            );
            $this->assertFalse(
                $sync->invoke($composer, $userAssets, $userTemplate),
                '사용자 렌더가 관리자 렌더의 서명을 덮어써 파일 변경으로 오독했다'
            );

            // 대조군 — 실제로 파일이 바뀌면 그 스코프에서는 감지된다 (가드가 사문이 아니다)
            File::put(base_path('templates/'.$userTemplate.'/custom/custom.css'), '/* changed */');
            touch(base_path('templates/'.$userTemplate.'/custom/custom.css'), time() + 10);
            CustomAssets::flushCache();

            $this->assertTrue(
                $sync->invoke($composer, $resolve->invoke($composer, $userTemplate), $userTemplate),
                '파일을 고쳤는데 변경 감지가 발화하지 않았다'
            );
        } finally {
            foreach ([$adminTemplate, $userTemplate] as $identifier) {
                File::deleteDirectory(base_path('templates/'.$identifier));
            }
            CustomAssets::flushCache();
        }
    }
}
