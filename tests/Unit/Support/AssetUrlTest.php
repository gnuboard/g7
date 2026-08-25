<?php

namespace Tests\Unit\Support;

use App\Services\ExtensionStaticCacheService;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 자산 URL 빌더 가드 (이슈 #486 단위 B).
 *
 * 단위 B 의 완료 조건은 "기본값 `extension` 에서 렌더 결과가 단위 A 이전과 바이트 동일" 이다.
 * 아래 확장자 모드 기대값은 치환 이전 소스에 하드코딩되어 있던 문자열을 그대로 옮긴 것으로,
 * 빌더 도입이 URL 을 한 글자도 바꾸지 않았음을 고정한다.
 */
class AssetUrlTest extends TestCase
{
    /** 테스트 전용 public 루트 (실 게시 트리 격리) */
    private string $isolatedPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 실 게시 트리(public/build/ext) 격리 — cleanupStaticFixture 가 base 를 통째로
        // 지우므로, 격리 없이는 단위 테스트 1회 실행이 운영 중 사이트의 게시본을 전량
        // 삭제한다 (ExtensionStaticCacheServiceTest 의 격리와 동일 근거).
        $this->isolatedPublicPath = storage_path('framework/testing/public-asseturl-'.getmypid());
        File::ensureDirectoryExists($this->isolatedPublicPath);
        $this->app->usePublicPath($this->isolatedPublicPath);
    }

    protected function tearDown(): void
    {
        AssetUrl::forceMode(null);
        File::deleteDirectory($this->isolatedPublicPath);
        parent::tearDown();
    }

    /**
     * 기본 모드는 확장자 유지여야 한다.
     *
     * 기본값이 뒤집히면 정상 환경 다수가 확장자 기반 캐시·gzip 최적화를 잃는다.
     */
    public function test_기본_모드는_확장자_유지다(): void
    {
        AssetUrl::forceMode(null);

        $this->assertSame(AssetUrl::MODE_EXTENSION, AssetUrl::mode());
        $this->assertFalse(AssetUrl::isExtensionless());
    }

    /**
     * 확장자 모드 출력이 치환 이전 하드코딩 문자열과 동일해야 한다.
     */
    public function test_확장자_모드_출력이_기존_하드코딩과_바이트_동일하다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        // resources/views/{app,admin}.blade.php
        $this->assertSame(
            '/api/templates/assets/sirsoft-basic/css/components.css?v=7',
            AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 7)
        );
        $this->assertSame(
            '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=7',
            AssetUrl::templateAsset('sirsoft-basic', 'js/components.iife.js', 7)
        );

        // TemplateComposer::buildExtensionBundleUrls
        $this->assertSame('/api/modules/bundle.js?v=7', AssetUrl::extensionBundle('modules', 'js', 7));
        $this->assertSame('/api/modules/bundle.css?v=7', AssetUrl::extensionBundle('modules', 'css', 7));
        $this->assertSame('/api/plugins/bundle.js?v=7', AssetUrl::extensionBundle('plugins', 'js', 7));
        $this->assertSame('/api/plugins/bundle.css?v=7', AssetUrl::extensionBundle('plugins', 'css', 7));

        // TemplateComposer::collect{Module,Plugin}Assets — 모듈/플러그인은 dist/ 를 경로에 포함
        $this->assertSame(
            '/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js?v=7',
            AssetUrl::moduleAsset('sirsoft-ecommerce', 'dist/js/module.iife.js', 7)
        );
        $this->assertSame(
            '/api/plugins/assets/sirsoft-gdpr/dist/css/plugin.css?v=7',
            AssetUrl::pluginAsset('sirsoft-gdpr', 'dist/css/plugin.css', 7)
        );

        // ModuleManager / PluginManager — 버전 미부착
        $this->assertSame(
            '/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js',
            AssetUrl::moduleAsset('sirsoft-ecommerce', 'dist/js/module.iife.js')
        );

        // SeoRenderer — dist/ 를 벗긴 경로 + 버전 없음
        $this->assertSame(
            '/api/templates/assets/sirsoft-basic/css/components.css',
            AssetUrl::templateAsset('sirsoft-basic', 'css/components.css')
        );

        // AdminTemplateAssetController — 편집기 CSS
        $this->assertSame(
            '/api/admin/templates/sirsoft-basic/editor/component-styles.css?v=7',
            AssetUrl::suffixed('/api/admin/templates/sirsoft-basic/editor/component-styles', 'css', 7)
        );
    }

    /**
     * 확장자 없는 모드에서는 자산 경로가 `file` 쿼리로 옮겨져야 한다.
     */
    public function test_확장자_없는_모드는_자산_경로를_쿼리로_옮긴다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $this->assertSame(
            '/api/templates/assets/sirsoft-basic?file=js%2Fcomponents.iife.js&v=7',
            AssetUrl::templateAsset('sirsoft-basic', 'js/components.iife.js', 7)
        );
        $this->assertSame(
            '/api/modules/assets/sirsoft-ecommerce?file=dist%2Fjs%2Fmodule.iife.js',
            AssetUrl::moduleAsset('sirsoft-ecommerce', 'dist/js/module.iife.js')
        );
    }

    /**
     * 확장자 없는 모드에서는 번들 접미사가 경로 세그먼트로 내려가야 한다.
     *
     * 접미사를 제거하면 js/css 가 둘 다 `bundle` 이 되어 구분 불가.
     */
    public function test_확장자_없는_모드는_번들_접미사를_세그먼트로_내린다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $this->assertSame('/api/modules/bundle/js?v=7', AssetUrl::extensionBundle('modules', 'js', 7));
        $this->assertSame('/api/plugins/bundle/css?v=7', AssetUrl::extensionBundle('plugins', 'css', 7));
    }

    /**
     * 확장자 없는 모드에서는 고정 접미사가 제거되어야 한다.
     */
    public function test_확장자_없는_모드는_고정_접미사를_제거한다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $this->assertSame(
            '/api/templates/sirsoft-basic/routes',
            AssetUrl::suffixed('/api/templates/sirsoft-basic/routes', 'json')
        );
        $this->assertSame(
            '/api/admin/templates/sirsoft-basic/editor/component-styles?v=7',
            AssetUrl::suffixed('/api/admin/templates/sirsoft-basic/editor/component-styles', 'css', 7)
        );
    }

    /**
     * 생성된 URL 의 경로 부분에는 어떤 모드에서도 정적 확장자가 남지 않아야 한다.
     *
     * 이것이 이번 이슈의 본질 — 경로에 확장자가 남으면 nginx 정적 블록이 그대로 가로챈다.
     */
    public function test_확장자_없는_모드_주소_경로에_정적_확장자가_없다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $urls = [
            AssetUrl::templateAsset('t', 'js/a.js', 7),
            AssetUrl::moduleAsset('m', 'dist/js/a.js', 7),
            AssetUrl::pluginAsset('p', 'dist/css/a.css'),
            AssetUrl::extensionBundle('modules', 'js', 7),
            AssetUrl::extensionBundle('plugins', 'css'),
            AssetUrl::suffixed('/api/templates/t/routes', 'json'),
        ];

        foreach ($urls as $url) {
            $path = parse_url($url, PHP_URL_PATH);

            $this->assertDoesNotMatchRegularExpression(
                '/\.(js|mjs|css|json|map)$/i',
                (string) $path,
                "확장자 없는 모드인데 경로에 정적 확장자가 남아있음: {$url}"
            );
        }
    }

    /**
     * 설정 조회가 실패해도 기본 모드로 폴백해야 한다.
     *
     * 이 값은 blade 렌더 경로에서 읽히므로 여기서 예외가 나면 화면 전체가 죽는다.
     */
    public function test_설정_조회_실패시_기본_모드로_폴백한다(): void
    {
        AssetUrl::forceMode(null);

        $this->assertSame(AssetUrl::MODE_EXTENSION, AssetUrl::mode());
    }

    // ── 정적 게시(bake) 게이트 (#122 S2) ─────────────────────────────────

    /**
     * 정적 게이트용 게시 픽스처를 만든다.
     *
     * @param  int  $version  버전
     * @param  array<string>  $files  버전 디렉토리 내 상대 경로 목록
     */
    private function publishFixture(int $version, array $files = []): void
    {
        $dir = public_path('build/ext/'.$version);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/manifest.json', '{}');

        foreach ($files as $relative) {
            File::ensureDirectoryExists(dirname($dir.'/'.$relative));
            File::put($dir.'/'.$relative, 'x');
        }
    }

    private function cleanupStaticFixture(): void
    {
        File::deleteDirectory(public_path('build/ext'));
        AssetUrl::resetStaticExtBaseMemo();
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
    }

    /**
     * 정적 게이트 3조건 — 프로덕션 + enabled + 게시 완료(manifest) 를 전부
     * 통과해야만 base 가 반환된다.
     *
     * @scenario publish_state=unpublished, environment=production, trigger=self_heal, process_user=web
     *
     * @effects static_gate_requires_manifest, kill_switch_disables_publish_and_gate
     */
    public function test_정적_게이트는_프로덕션_활성_게시완료_3조건이다(): void
    {
        try {
            Cache::put('g7:core:ext.cache_version', 424242);

            // 비프로덕션(testing) → null
            AssetUrl::resetStaticExtBaseMemo();
            $this->publishFixture(424242);
            $this->assertNull(AssetUrl::staticExtBase());

            // 프로덕션 + 게시 완료 → base
            app()['env'] = 'production';
            AssetUrl::resetStaticExtBaseMemo();
            $this->assertSame('/build/ext/424242', AssetUrl::staticExtBase());

            // kill-switch off → null
            config(['core.static_cache.enabled' => false]);
            AssetUrl::resetStaticExtBaseMemo();
            $this->assertNull(AssetUrl::staticExtBase());
            config(['core.static_cache.enabled' => true]);

            // manifest 부재(미게시) → null
            File::delete(public_path('build/ext/424242/manifest.json'));
            AssetUrl::resetStaticExtBaseMemo();
            $this->assertNull(AssetUrl::staticExtBase());
        } finally {
            $this->cleanupStaticFixture();
        }
    }

    /**
     * 태그 계층 파일 단위 게이트 — manifest 는 있어도 그 자산의 실파일이 없으면
     * 그 자산만 종전 API URL 로 방출된다 (나머지는 정적 URL).
     *
     * @scenario publish_state=partial, environment=production, trigger=manual_command, process_user=web
     *
     * @effects tag_layer_checks_individual_file_existence
     */
    public function test_태그_계층은_개별_파일_존재까지_확인한다(): void
    {
        try {
            Cache::put('g7:core:ext.cache_version', 424242);
            app()['env'] = 'production';

            $this->publishFixture(424242, [
                'templates/sirsoft-basic/assets/css/components.css',
                'bundles/modules.js',
            ]);
            AssetUrl::resetStaticExtBaseMemo();

            // 존재하는 파일 → 정적 URL (버전 디렉토리 경로, `?v` 불요)
            $this->assertSame(
                '/build/ext/424242/templates/sirsoft-basic/assets/css/components.css',
                AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 424242)
            );
            $this->assertSame(
                '/build/ext/424242/bundles/modules.js',
                AssetUrl::extensionBundle('modules', 'js', 424242)
            );

            // 부재 파일 → 종전 API URL 그대로 (바이트 동일)
            $this->assertSame(
                '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=424242',
                AssetUrl::templateAsset('sirsoft-basic', 'js/components.iife.js', 424242)
            );
            $this->assertSame(
                '/api/plugins/bundle.css?v=424242',
                AssetUrl::extensionBundle('plugins', 'css', 424242)
            );
        } finally {
            $this->cleanupStaticFixture();
        }
    }

    /**
     * 현재 게시 버전과 다른 `$version` 을 요구하는 호출은 정적 분기를 타지 않는다.
     *
     * 정적 경로는 항상 현재 게시본을 가리키므로, 다른 버전을 명시한 호출에 현재본을
     * 돌려주면 "요청 버전이 URL 에 반영된다" 는 시그니처 계약이 조용히 깨진다.
     * (현 호출부는 전부 현재 버전을 넘기므로 실동작 불변 — 미래 호출부 방어)
     */
    public function test_요청_버전이_현재_게시_버전과_다르면_정적_분기를_건너뛴다(): void
    {
        try {
            Cache::put('g7:core:ext.cache_version', 424242);
            app()['env'] = 'production';
            $this->publishFixture(424242, [
                'templates/sirsoft-basic/assets/css/components.css',
            ]);
            AssetUrl::resetStaticExtBaseMemo();

            // 현재 버전 요청 → 정적
            $this->assertSame(
                '/build/ext/424242/templates/sirsoft-basic/assets/css/components.css',
                AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 424242)
            );

            // 다른 버전 요청 → 종전 API URL (요청 버전 유지)
            $this->assertSame(
                '/api/templates/assets/sirsoft-basic/css/components.css?v=111111',
                AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 111111)
            );
        } finally {
            $this->cleanupStaticFixture();
        }
    }

    /**
     * 정적 게이트의 버전 조회는 PHP deprecation 을 발생시키지 않아야 한다.
     *
     * 트레이트 정적 메서드 직접 호출(`ClearsTemplateCaches::getExtensionCacheVersion()`)은
     * PHP 8.1+ E_DEPRECATED 다 — blade 게이트는 매 프로덕션 요청마다 실행되므로
     * 트레이트를 사용하는 클래스 경유로 호출해야 한다.
     *
     * @effects static_gate_requires_manifest
     */
    public function test_정적_게이트는_deprecation_없이_동작한다(): void
    {
        try {
            Cache::put('g7:core:ext.cache_version', 424242);
            app()['env'] = 'production';
            $this->publishFixture(424242);
            AssetUrl::resetStaticExtBaseMemo();

            $deprecations = [];
            set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
                $deprecations[] = "[{$errno}] {$errstr}";

                return true;
            }, E_DEPRECATED | E_USER_DEPRECATED);

            try {
                AssetUrl::staticExtBase();
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $deprecations);
        } finally {
            $this->cleanupStaticFixture();
        }
    }

    /**
     * base null(게이트 미통과) 이면 기존 URL 과 바이트 동일해야 한다 (호출부 무변경 계약).
     *
     * @scenario publish_state=unpublished, environment=dev, trigger=kill_switch, process_user=web
     */
    public function test_게이트_미통과시_종전_ur_l_바이트_동일(): void
    {
        AssetUrl::resetStaticExtBaseMemo();
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        $this->assertSame(
            '/api/templates/assets/sirsoft-basic/css/components.css?v=7',
            AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 7)
        );
        $this->assertSame('/api/modules/bundle.js?v=7', AssetUrl::extensionBundle('modules', 'js', 7));
    }
}
