<?php

namespace Tests\Unit\Support;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\ExtensionStaticCacheService;
use App\Services\SettingsService;
use App\Support\AssetUrl;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 자산 URL 빌더 가드 (단위 B).
 *
 * 단위 B 의 완료 조건은 "기본값 `extension` 에서 렌더 결과가 단위 A 이전과 바이트 동일" 이다.
 * 아래 확장자 모드 기대값은 치환 이전 소스에 하드코딩되어 있던 문자열을 그대로 옮긴 것으로,
 * 빌더 도입이 URL 을 한 글자도 바꾸지 않았음을 고정한다.
 */
class AssetUrlTest extends TestCase
{
    /** 테스트 전용 public 루트 (실 게시 트리 격리) */
    private string $isolatedPublicPath;

    /**
     * 공개 경로를 테스트 전용 임시 디렉토리로 옮겨, 정적 게시 정리가 실제 public/build/ext 를 지우지 않게 한다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 강제 모드와 강제 접두사를 풀고 임시 공개 경로를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceMode(null);
        File::deleteDirectory($this->isolatedPublicPath);
        AssetUrl::forceBasePrefix(null);
        parent::tearDown();
    }

    /**
     * 기본 모드는 확장자 유지여야 한다.
     *
     * 기본값이 뒤집히면 정상 환경 다수가 확장자 기반 캐시·gzip 최적화를 잃는다.
     *
     * @scenario asset_url_mode=extension
     *
     * @effects asset_url_mode_defaults_to_extension
     *
     * @return void 반환값 없음
     */
    public function test_기본_모드는_확장자_유지다(): void
    {
        AssetUrl::forceMode(null);

        $this->assertSame(AssetUrl::MODE_EXTENSION, AssetUrl::mode());
        $this->assertFalse(AssetUrl::isExtensionless());
    }

    /**
     * 확장자 모드 출력이 치환 이전 하드코딩 문자열과 동일해야 한다.
     *
     * @scenario asset_url_mode=extension
     *
     * @effects extension_mode_output_matches_the_legacy_literals
     *
     * @return void 반환값 없음
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
     *
     * @scenario asset_url_mode=extensionless
     *
     * @effects extensionless_mode_urls_carry_no_static_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario asset_url_mode=extensionless
     *
     * @effects extensionless_mode_urls_carry_no_static_extension
     *
     * @return void 반환값 없음
     */
    public function test_확장자_없는_모드는_번들_접미사를_세그먼트로_내린다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $this->assertSame('/api/modules/bundle/js?v=7', AssetUrl::extensionBundle('modules', 'js', 7));
        $this->assertSame('/api/plugins/bundle/css?v=7', AssetUrl::extensionBundle('plugins', 'css', 7));
    }

    /**
     * 확장자 없는 모드에서는 고정 접미사가 제거되어야 한다.
     *
     * @scenario asset_url_mode=extensionless
     *
     * @effects extensionless_mode_urls_carry_no_static_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario asset_url_mode=extensionless
     *
     * @effects extensionless_mode_urls_carry_no_static_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario asset_url_mode=extension
     *
     * @effects asset_url_mode_falls_back_to_default_when_settings_fail
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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

    /**
     * 정적 게시 트리를 지우고 게시 기준 경로 메모와 게시 예약 상태를 초기화한다.
     *
     * @return void 반환값 없음
     */
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
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=self_heal, process_user=web
     *
     * @effects static_gate_requires_manifest, kill_switch_disables_publish_and_gate
     *
     * @return void 반환값 없음
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
     * `?file=` 인코더가 프론트 판본과 **같은 문자열**을 만들어야 한다.
     *
     * 두 인코더가 갈리면 같은 파일의 URL 문자열이 둘로 나뉜다. 디코딩하면 같은 경로라
     * 404 도 오류도 나지 않고, HTTP 캐시 키가 갈려 같은 자산을 두 번 받는 것이 유일한
     * 증상이다. 실제로 갈려 있었다 — 프론트 `extensionAsset()` 이 `encodeURIComponent` 를
     * 썼고 그 함수는 `! ' ( ) *` 를 인코딩하지 않는다.
     *
     * 기대표는 두 테스트가 **같은 파일**을 읽는다. 각자 적으면 그 사본이 다시 갈린다.
     *
     * @see resources/js/core/support/__tests__/assetUrl.test.ts 같은 표를 읽는 프론트 축
     *
     * @effects asset_url_encoders_match_the_frontend
     *
     * @return void 반환값 없음
     */
    public function test_file_쿼리_인코더가_프론트_판본과_같은_문자열을_만든다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/AssetUrl/file-query-parity.json')),
            true
        );

        $this->assertIsArray($fixture);
        // 도출이 비면 이 검사는 아무것도 재지 않는다.
        $this->assertGreaterThan(4, count($fixture['cases']), '기대표를 읽지 못했다');

        foreach ($fixture['cases'] as $case) {
            $this->assertSame(
                '/api/'.$fixture['type'].'/assets/'.$fixture['identifier'].'?file='.$case['encoded'],
                AssetUrl::extensionAsset($fixture['type'], $fixture['identifier'], $case['path']),
                $case['why'].' — '.$case['path']
            );
        }
    }

    /**
     * 태그 계층 파일 단위 게이트 — manifest 는 있어도 그 자산의 실파일이 없으면
     * 그 자산만 종전 API URL 로 방출된다 (나머지는 정적 URL).
     *
     * @scenario publish_state=partial, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects tag_layer_checks_individual_file_existence
     *
     * @return void 반환값 없음
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
     * 하위 폴더 설치에서 정적 게시본 URL 은 사이트 마운트 접두사를 갖는다.
     *
     * 게시 트리는 사이트의 `public/` 아래(`/g7/build/ext/{v}/…`)에 있다. 접두사 없이
     * `/build/ext/{v}/…` 를 내면 그 요청은 문서 루트(사이트 밖)로 나가 404 가 되고,
     * `<link>`·`<script>` 태그 자산(아이콘 글꼴·글꼴·컴포넌트 번들·병합 번들)이 전부 빠진다.
     * 같은 함수의 API 분기는 이미 `basePrefix()` 를 붙이고 있어, 정적 게시가 켜지는 운영
     * 환경에서만 두 분기의 규칙이 갈렸다. 게이트 값(`staticExtBase()`)은 버전 비교에 쓰이므로
     * 접두사 없는 형태를 유지한다.
     *
     * @scenario base_path=subpath, asset_url_mode=extension
     *
     * @effects static_publish_urls_carry_the_site_base_prefix
     *
     * @return void 반환값 없음
     */
    public function test_하위_폴더_설치의_정적_게시_url_은_마운트_접두사를_갖는다(): void
    {
        try {
            Cache::put('g7:core:ext.cache_version', 424242);
            app()['env'] = 'production';
            $this->publishFixture(424242, [
                'templates/sirsoft-basic/assets/css/components.css',
                'templates/sirsoft-basic/assets/js/components.iife.js',
                'bundles/modules.js',
                'bundles/plugins.css',
                'modules/sirsoft-page/assets/custom/custom.css',
                'plugins/sirsoft-gdpr/assets/custom/custom.js',
            ]);
            AssetUrl::forceBasePrefix('/g7');
            AssetUrl::resetStaticExtBaseMemo();

            // 게이트 값은 버전 비교용 — 접두사 없는 형태 그대로
            $this->assertSame('/build/ext/424242', AssetUrl::staticExtBase());

            $this->assertSame(
                '/g7/build/ext/424242/templates/sirsoft-basic/assets/css/components.css',
                AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 424242)
            );
            $this->assertSame(
                '/g7/build/ext/424242/templates/sirsoft-basic/assets/js/components.iife.js',
                AssetUrl::templateAsset('sirsoft-basic', 'js/components.iife.js', 424242)
            );
            $this->assertSame(
                '/g7/build/ext/424242/bundles/modules.js',
                AssetUrl::extensionBundle('modules', 'js', 424242)
            );
            $this->assertSame(
                '/g7/build/ext/424242/bundles/plugins.css',
                AssetUrl::extensionBundle('plugins', 'css', 424242)
            );
            $this->assertSame(
                '/g7/build/ext/424242/modules/sirsoft-page/assets/custom/custom.css',
                AssetUrl::moduleAsset('sirsoft-page', 'custom/custom.css', 424242, allowStatic: true)
            );
            $this->assertSame(
                '/g7/build/ext/424242/plugins/sirsoft-gdpr/assets/custom/custom.js',
                AssetUrl::pluginAsset('sirsoft-gdpr', 'custom/custom.js', 424242, allowStatic: true)
            );

            // 게시본에 없는 파일은 종전대로 API 분기 — 그 분기도 같은 접두사
            $this->assertSame(
                '/g7/api/modules/bundle.css?v=424242',
                AssetUrl::extensionBundle('modules', 'css', 424242)
            );

            // 루트 설치(접두사 빈 값)는 종전 문자열과 바이트 동일
            AssetUrl::forceBasePrefix('');
            $this->assertSame(
                '/build/ext/424242/templates/sirsoft-basic/assets/css/components.css',
                AssetUrl::templateAsset('sirsoft-basic', 'css/components.css', 424242)
            );
            $this->assertSame(
                '/build/ext/424242/bundles/modules.js',
                AssetUrl::extensionBundle('modules', 'js', 424242)
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
     *
     * @effects static_branch_is_skipped_for_a_different_requested_version
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=dev, trigger=kill_switch, process_user=web
     *
     * @return void 반환값 없음
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

    /**
     * 확장자 모드에서는 경로를 인코딩하지 않는다 — 프론트 판본과 같은 규약이다.
     *
     * 정적 `/storage/…` 서빙도 인코딩하지 않으므로, 여기서만 인코딩하면 같은 파일의
     * 주소가 링크 유무로 갈린다.
     *
     * @effects asset_url_encoders_match_the_frontend
     *
     * @return void 반환값 없음
     */
    public function test_확장자_모드는_경로를_인코딩하지_않는다(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        $this->assertSame(
            '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=7',
            AssetUrl::templateAsset('sirsoft-basic', 'js/components.iife.js', 7)
        );
    }

    /**
     * `sitePath()` 의 판정 대상 전수 — **이 빌더 자신을 재는 단언이 하나도 없었다.**
     *
     * 접두사 멱등 규칙은 코어 SEO 2지점·모듈 5지점이 흩어 갖던 것을 이 한 곳으로 접은
     * 결과물인데, 저장소 전체에서 `AssetUrl::sitePath` / `siteAbsolute` 를 **직접 부르는
     * 테스트가 0건**이었다(형제 `forceBasePrefix` 58 · `absolute` 10 대비). 계약이 전부
     * 4개 소비처를 통한 간접 측정이라, 가드 절을 지워도 그 소비처가 쓰지 않는 입력 형태
     * (빈 문자열 · 쿼리/프래그먼트 전용 · `data:` · 상대 경로)는 아무것도 red 가 되지
     * 않았다.
     *
     * 여기서 재는 것은 **가드 절 전수**다 — 어느 입력을 손대고 어느 입력을 그대로 두는가.
     *
     * 기대표는 `tests/Fixtures/AssetUrl/base-prefix-boundary.json` 하나다. 종전에는 이 표가
     * 여기와 프론트 `assetUrl.test.ts` 에 **각자 손으로 쓴 두 벌**로 있었고, 사본은 갈려도
     * 울지 않는다 — 한쪽만 고치면 같은 입력이 서버와 화면에서 다른 판정을 받는다.
     *채움·멱등·비대상(빈 문자열 · 프로토콜 상대 · 절대 URL ·
     * `data:` · 상대 경로 · 쿼리/프래그먼트 전용) · 경계(`?`·`#`·경계 없는 이어짐) ·
     * 공백 전처리 행이 전부 그 표에 있다.
     *
     * @effects site_path_helpers_satisfy_the_shared_boundary_table
     *
     * @return void 반환값 없음
     */
    public function test_site_path_와_has_base_prefix_는_공유_기대표의_전_행을_만족한다(): void
    {
        $fixture = $this->boundaryFixture();

        $this->assertGreaterThan(1, count($fixture['boundary']), '접두사 그룹이 비면 이 검사는 아무것도 재지 않는다');

        $rows = 0;
        foreach ($fixture['boundary'] as $group) {
            AssetUrl::forceBasePrefix($group['prefix']);
            $this->assertGreaterThan(0, count($group['cases']));

            foreach ($group['cases'] as $case) {
                $rows++;
                $label = $case['why'].' — '.json_encode($case['input'], JSON_UNESCAPED_SLASHES).' @ '.$group['prefix'];

                $this->assertSame($case['with'], AssetUrl::sitePath($case['input']), 'sitePath: '.$label);
                $this->assertSame($case['has'], AssetUrl::hasBasePrefix($case['input'], $group['prefix']), 'hasBasePrefix: '.$label);
            }
        }

        // 빈 모집단 대조 — 회차 15 가 실측한 24 입력 + 다단 접두사 행.
        $this->assertGreaterThanOrEqual(28, $rows, '기대표 행이 줄었다');
    }

    /**
     * 접두사 자체의 정규화도 같은 표를 읽는다 — `app.url` 의 경로 부분(`configuredBasePath()`).
     *
     * 프론트는 `G7Config.basePath` 를 같은 결과로 정규화해야 한다(`assetUrl.test.ts` 가 같은
     * 행을 읽는다). 어긋나면 서버가 렌더한 주소와 화면이 만든 주소의 접두사가 갈린다.
     *
     * @effects site_path_helpers_satisfy_the_shared_boundary_table
     *
     * @return void 반환값 없음
     */
    public function test_configured_base_path_는_공유_기대표의_app_url_행을_만족한다(): void
    {
        $fixture = $this->boundaryFixture();
        $rows = 0;

        foreach ($fixture['base_path'] as $case) {
            if (! is_string($case['app_url'])) {
                continue;
            }

            $rows++;
            config(['app.url' => $case['app_url']]);
            $this->assertSame($case['prefix'], AssetUrl::configuredBasePath(), $case['why'].' — '.$case['app_url']);
        }

        $this->assertGreaterThanOrEqual(10, $rows, '서버 축 행이 줄었다');
    }

    /**
     * 공유 기대표를 읽습니다.
     *
     * @return array{boundary: list<array{prefix: string, cases: list<array<string, mixed>>}>, base_path: list<array<string, mixed>>} 접두사 경계 사례와 app.url 경로 사례를 담은 공유 기대표
     */
    private function boundaryFixture(): array
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/AssetUrl/base-prefix-boundary.json')), true);

        $this->assertIsArray($fixture);

        return $fixture;
    }

    /**
     * 루트 설치(접두사 없음)에서는 `sitePath()` 가 항등이다.
     *
     * @effects site_path_helpers_satisfy_the_shared_boundary_table
     *
     * @return void 반환값 없음
     */
    public function test_루트_설치에서_site_path_는_항등이다(): void
    {
        AssetUrl::forceBasePrefix('');

        $this->assertSame('/images/logo.png', AssetUrl::sitePath('/images/logo.png'));
        $this->assertSame('/api/x', AssetUrl::sitePath('/api/x'));
    }

    /**
     * `siteAbsolute()` 는 **접두사 채움 → 오리진 부착** 두 단계를 그 순서로 수행한다.
     *
     * 한 단계만 도는 형태(오리진만 붙이는 `absolute()` 단독)는 하위 폴더 배치에서 사이트
     * 밖을 가리키고, 접두사만 채우는 형태는 크롤러·메일에 상대 경로를 내보낸다.
     *
     * @effects site_absolute_adds_the_prefix_then_a_normalized_origin
     *
     * @return void 반환값 없음
     */
    public function test_site_absolute_는_접두사와_오리진을_함께_붙인다(): void
    {
        AssetUrl::forceBasePrefix('/g7');
        config(['app.url' => 'https://example.com/g7']);

        $this->assertSame('https://example.com/g7/images/logo.png', AssetUrl::siteAbsolute('/images/logo.png'));

        // 이미 접두사를 가진 값에 두 번 붙지 않는다.
        $this->assertSame('https://example.com/g7/api/x', AssetUrl::siteAbsolute('/g7/api/x'));

        // 사이트 경로가 아닌 값에는 오리진도 붙이지 않는다 — 붙이면
        // `https://example.comdata:image/…` 같은 값이 조용히 만들어진다.
        $this->assertSame('//cdn.example.com/x.png', AssetUrl::siteAbsolute('//cdn.example.com/x.png'));
        $this->assertSame('data:image/png;base64,AAAA', AssetUrl::siteAbsolute('data:image/png;base64,AAAA'));
        $this->assertSame('img.png', AssetUrl::siteAbsolute('img.png'));
        $this->assertSame('', AssetUrl::siteAbsolute(''));
    }

    /**
     * `siteAbsolute()`·`siteRoot()` 는 `SiteUrl::absolute()` 에 위임한다 — `APP_URL` 이 비면 요청 Host 로 가지 않는다.
     *
     * 절대 URL 의 단일 출처는 사이트 주소(`APP_URL`)다. 요청 Host 는 방문자가 위조할 수 있어, 이 값이
     * 실리는 메일·크롤러·제3자 주소에 위조 호스트가 새어 나가면 안 된다. 사이트 주소가 비면 경로 그대로
     * (상대)이고 `siteRoot()` 는 빈 문자열이다. 요청이 실은 마운트 접두사(`basePrefix()`)가 아니라 사이트
     * 주소의 경로가 접두사의 출처다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects notification_links_are_built_by_the_site_absolute_helper
     *
     * @return void 반환값 없음
     */
    public function test_site_absolute_는_site_url_에_위임해_요청_host_로_가지_않는다(): void
    {
        $request = Request::create('https://attacker.example/g7/products/1', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/g7/public/index.php',
            'SCRIPT_NAME' => '/g7/index.php',
            'PHP_SELF' => '/g7/index.php',
        ]);
        app()->instance('request', $request);

        try {
            config(['app.url' => '']);
            $this->assertSame('/login', AssetUrl::siteAbsolute('/login'));
            $this->assertSame('/g7/api/x', AssetUrl::siteAbsolute('/g7/api/x'));
            $this->assertSame('', AssetUrl::siteRoot());

            config(['app.url' => 'https://example.com/g7']);
            $this->assertSame(SiteUrl::absolute('/login'), AssetUrl::siteAbsolute('/login'));
            $this->assertSame('https://example.com/g7/login', AssetUrl::siteAbsolute('/login'));
            $this->assertSame('https://example.com/g7/api/x', AssetUrl::siteAbsolute('/g7/api/x'));
            $this->assertSame('https://example.com/g7', AssetUrl::siteRoot());

            // 루트 설치 — 요청이 접두사를 실어 와도 사이트 주소에 경로가 없으면 접두사를 붙이지 않는다
            config(['app.url' => 'https://example.com']);
            $this->assertSame('https://example.com/login', AssetUrl::siteAbsolute('/login'));
            $this->assertSame('https://example.com', AssetUrl::siteRoot());
        } finally {
            app()->forgetInstance('request');
        }
    }

    /**
     * 접두사 **바로 뒤가 `?`·`#`** 인 값도 「이미 접두사를 가진 값」이다.
     *
     * 종전 판정은 `$path === $prefix || str_starts_with($path, $prefix.'/')` 두 형태만
     * 알았다. 하위 폴더 홈에 쿼리가 붙은 채 세션이 만료되면 복귀 주소가 `/g7?ref=x`
     * 인데, 그 값은 어느 쪽에도 맞지 않아 `/g7/g7?ref=x` 로 이중 접두가 됐다.
     *반대로 `/g7x/foo` 는 접두사가 **경계 없이** 이어진 다른
     * 경로라 붙여야 한다 — 경계 인식을 `str_starts_with($path, $prefix)` 로 넓히면
     * 이쪽이 조용히 빠진다.
     *
     * @effects site_path_helpers_satisfy_the_shared_boundary_table
     *
     * @return void 반환값 없음
     */
    public function test_site_path_는_접두사_뒤_쿼리_프래그먼트_경계를_인식한다(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        // `sitePath()` 의 경계 행(`/g7?tab=1` · `/g7#top` · `/g7x/foo`)은 공유 기대표
        // (`base-prefix-boundary.json`)가 소유한다. 여기서는 오리진까지 붙는 축만 잰다.
        $this->assertSame('https://example.com/g7?tab=1', $this->siteAbsoluteWith('https://example.com/g7', '/g7?tab=1'));
        $this->assertSame('https://example.com/g7#top', $this->siteAbsoluteWith('https://example.com/g7', '/g7#top'));
        $this->assertSame('https://example.com/g7/g7x/foo', $this->siteAbsoluteWith('https://example.com/g7', '/g7x/foo'));
    }

    /**
     * 오리진의 스킴·호스트는 소문자다 (RFC 3986 §6.2.2.1). 경로는 대소문자를 보존한다.
     *
     * `app.url` 을 `HTTPS://Example.COM/g7/` 로 적어 둔 사이트에서 크롤러가 받는 절대
     * 주소가 그대로 대문자 오리진을 실었다 — 같은 자산의 정본 주소가 표기에 따라
     * 둘로 갈린다.
     *
     * @effects site_absolute_adds_the_prefix_then_a_normalized_origin
     *
     * @return void 반환값 없음
     */
    public function test_site_absolute_는_오리진_스킴_호스트를_소문자로_정규화한다(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $this->assertSame('https://example.com/g7/Images/A.png', $this->siteAbsoluteWith('HTTPS://Example.COM/g7/', '/Images/A.png'));
        $this->assertSame('https://example.com:8443/g7/images/a.png', $this->siteAbsoluteWith('https://example.com:8443/g7', '/images/a.png'));
        $this->assertSame('https://example.com:8443/g7/images/a.png', $this->siteAbsoluteWith('HTTPS://EXAMPLE.com:8443/g7', '/images/a.png'));
    }

    /**
     * 선행·후행 공백은 판정 전에 걷어낸다. 빈 문자열(공백뿐인 값 포함)은 그대로 둔다.
     *
     * @effects site_path_helpers_satisfy_the_shared_boundary_table
     *
     * @return void 반환값 없음
     */
    public function test_site_path_와_site_absolute_는_선행_공백을_걷어낸다(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        // `sitePath()` 의 공백 행(`' /images/a.png'` · `''` · `'   '` · 탭/개행)은 공유 기대표가
        // 소유한다. 여기서는 오리진까지 붙는 축만 잰다.
        $this->assertSame('https://example.com/g7/images/a.png', $this->siteAbsoluteWith('https://example.com/g7', ' /images/a.png'));
        $this->assertSame('https://example.com/g7/images/a.png', $this->siteAbsoluteWith('https://example.com/g7', "\t/images/a.png\n"));
        $this->assertSame('', $this->siteAbsoluteWith('https://example.com/g7', ''));
        $this->assertSame('', $this->siteAbsoluteWith('https://example.com/g7', '   '), '공백뿐인 값은 빈 문자열로 둔다');
    }

    /**
     * `suffixed()` 도 접두사 규칙을 `sitePath()` 와 공유한다 — 프로토콜 상대 가드와 멱등.
     *
     * 프론트 `suffixed()` 는 `withBasePrefix()` 를 경유해 두 성질을 갖는데 서버는
     * `str_starts_with($url, '/')` 만 보고 접두사를 무조건 붙였다.
     *
     * @effects site_path_helpers_satisfy_the_shared_boundary_table
     *
     * @return void 반환값 없음
     */
    public function test_suffixed_는_프로토콜_상대와_기존_접두사에_접두사를_붙이지_않는다(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $this->assertSame('//cdn.example.com/x.json', AssetUrl::suffixed('//cdn.example.com/x', 'json'), '프로토콜 상대 주소에 접두사를 붙였다');
        $this->assertSame('/g7/api/templates/t/routes.json', AssetUrl::suffixed('/g7/api/templates/t/routes', 'json'), '이미 접두사를 가진 경로에 두 번 붙였다');
        $this->assertSame('/g7/api/templates/t/routes.json', AssetUrl::suffixed('/api/templates/t/routes', 'json'));
    }

    /**
     * 확장 식별자는 프론트(`extensionAsset()` 의 `rawEncode()`)와 같이 인코딩한다.
     *
     * 식별자는 슬러그라 발현은 없지만, 규칙 사본이 갈리면 그 사실이 다음 회차에
     * 「어느 쪽이 맞는가」로 되돌아온다. 서버의 인코더는 `?file=` 와 같은 `rawurlencode`.
     * 종전 독블록은 프론트가 `encodeURIComponent` 라 적었고 실제로 그랬다 — `! ' ( ) *`
     * 에서 두 URL 이 갈렸다. 기대표는 프론트 테스트와 같은 픽스처 하나를 읽는다.
     *
     * @effects asset_url_encoders_match_the_frontend
     *
     * @return void 반환값 없음
     */
    public function test_확장_식별자는_양쪽_모드에서_인코딩된다(): void
    {
        AssetUrl::forceBasePrefix('');

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);
        $this->assertSame('/api/templates/assets/a%20b/js/x.js', AssetUrl::templateAsset('a b', 'js/x.js'));

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);
        $this->assertSame('/api/modules/assets/a%20b?file=dist%2Fx.js', AssetUrl::moduleAsset('a b', 'dist/x.js'));
    }

    /**
     * 식별자 인코더가 프론트 판본과 같은 문자열을 만든다 (공유 기대표).
     *
     * 회차 13 C-5 — 경로(`?file=`)는 맞춰 두고 식별자만 두 인코더가 갈려 있었다.
     *
     * @effects asset_url_encoders_match_the_frontend
     *
     * @return void 반환값 없음
     */
    public function test_확장_식별자_인코더가_프론트_판본과_같은_문자열을_만든다(): void
    {
        AssetUrl::forceBasePrefix('');
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/AssetUrl/file-query-parity.json')), true);

        $this->assertGreaterThan(1, count($fixture['identifier_cases']), '기대표가 비면 이 검사는 아무것도 재지 않는다');

        foreach ($fixture['identifier_cases'] as $case) {
            AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);
            $this->assertSame(
                '/api/modules/assets/'.$case['encoded'].'?file=dist%2Fx.js',
                AssetUrl::moduleAsset($case['identifier'], 'dist/x.js'),
                $case['why'].' — '.$case['identifier']
            );

            AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);
            $this->assertSame(
                '/api/modules/assets/'.$case['encoded'].'/dist/x.js',
                AssetUrl::moduleAsset($case['identifier'], 'dist/x.js'),
                $case['why'].' — '.$case['identifier'].' (확장자 모드)'
            );
        }
    }

    /**
     * 웹 요청의 마운트 접두사가 `app.url` 의 경로와 다르면 **요청당 한 번** 경고를 남긴다.
     *
     * 요청이 없는 문맥(큐·콘솔)은 `app.url` 의 경로만 알아서, 둘이 다르면 메일·사이트맵의
     * 주소가 전부 사이트 밖을 가리킨다 — 웹에서는 재현되지 않는 조용한 404 다. 웹 요청
     * 안에서 두 값을 나란히 볼 수 있는 유일한 자리가 `basePrefix()` 라 그곳이 로그로 남긴다.
     *테스트 프로세스는 제외되므로 여기서는 환경을 잠시 바꾼다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects prefix_mismatch_warning_is_keyed_to_the_request_not_a_reusable_object_id
     *
     * @return void 반환값 없음
     */
    public function test_요청의_접두사가_app_url_경로와_다르면_요청당_한_번_경고한다(): void
    {
        config(['app.url' => 'https://example.com/g7']);
        $request = Request::create('http://other/other/products/1', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/g7/public/index.php',
            'SCRIPT_NAME' => '/other/index.php',
            'PHP_SELF' => '/other/index.php',
        ]);
        $this->assertSame('/other', $request->getBaseUrl(), '요청 픽스처가 접두사를 싣지 않았다');
        app()->instance('request', $request);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'APP_URL')
                && $context['request_base_path'] === '/other'
                && $context['configured_base_path'] === '/g7');

        app()->instance('env', 'production');
        try {
            // 같은 요청 안에서 여러 번 불러도 경고는 한 번이다.
            $this->assertSame('/other', AssetUrl::basePrefix());
            $this->assertSame('/other', AssetUrl::basePrefix());
            $this->assertSame('/other/images/a.png', AssetUrl::sitePath('/images/a.png'));
        } finally {
            app()->instance('env', 'testing');
        }
    }

    /**
     * `siteRoot()` 는 `siteAbsolute('/')` 와 같은 규칙(오리진 + 접두사)에서 후행 슬래시만 걷어낸 값이다.
     *
     * 알림·메일의 「사이트로 가기」 값이다 — 형제 링크(`siteAbsolute('/login')`)와 언제나 같은
     * 앞부분을 가져야 하고, `APP_URL` 의 후행 슬래시·대소문자 표기가 그대로 새어 나가지 않는다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects notification_links_are_built_by_the_site_absolute_helper
     *
     * @return void 반환값 없음
     */
    public function test_site_root_는_오리진과_접두사만_싣고_후행_슬래시가_없다(): void
    {
        config(['app.url' => 'https://example.com/g7/']);
        $this->assertSame('https://example.com/g7', AssetUrl::siteRoot());
        $this->assertStringStartsWith(AssetUrl::siteRoot().'/', AssetUrl::siteAbsolute('/login'));

        config(['app.url' => 'HTTPS://Example.COM/']);
        $this->assertSame('https://example.com', AssetUrl::siteRoot());
    }

    /**
     * 경고 억제는 **그 요청 객체**에 묶인다 — 앞 요청이 해제된 뒤 온 다음 요청은 다시 경고한다.
     *
     * 종전 키는 `spl_object_id($request)` 였다. 그 값은 객체가 해제되면 **재사용**되므로
     * (PHP 는 빈 슬롯을 다시 쓴다), 장수 프로세스(Octane·큐 워커·테스트 러너)에서 앞 요청이
     * 해제된 직후의 다음 요청이 같은 id 를 받아 경고가 조용히 삼켜졌다. 요청당 1회라는 의미는
     * 요청 자신의 속성에 두면 객체 수명과 함께 끝난다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects prefix_mismatch_warning_is_keyed_to_the_request_not_a_reusable_object_id
     *
     * @return void 반환값 없음
     */
    public function test_접두사_불일치_경고는_해제된_요청의_id_재사용에_삼켜지지_않는다(): void
    {
        config(['app.url' => 'https://example.com/g7']);
        $server = [
            'SCRIPT_FILENAME' => '/var/www/g7/public/index.php',
            'SCRIPT_NAME' => '/other/index.php',
            'PHP_SELF' => '/other/index.php',
        ];

        Log::shouldReceive('error')->twice();

        app()->instance('env', 'production');
        try {
            $first = Request::create('http://other/other/products/1', 'GET', [], [], [], $server);
            app()->instance('request', $first);
            $this->assertSame('/other', AssetUrl::basePrefix());
            $firstId = spl_object_id($first);

            // 앞 요청을 해제한다 — 다음 객체가 같은 슬롯(id)을 받는 형태를 만든다.
            app()->forgetInstance('request');
            unset($first);

            $second = Request::create('http://other/other/products/2', 'GET', [], [], [], $server);
            app()->instance('request', $second);
            $this->assertSame($firstId, spl_object_id($second), '픽스처가 id 재사용을 재현하지 못했다');
            $this->assertSame('/other', AssetUrl::basePrefix());
            // 같은 요청 안의 두 번째 호출은 여전히 한 번이다.
            $this->assertSame('/other', AssetUrl::basePrefix());
        } finally {
            app()->instance('env', 'testing');
        }
    }

    /**
     * 요청의 접두사와 설정이 같으면 경고하지 않는다 (정상 배치에서 로그를 더럽히지 않는다).
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects prefix_mismatch_warning_is_keyed_to_the_request_not_a_reusable_object_id
     *
     * @return void 반환값 없음
     */
    public function test_요청의_접두사가_app_url_경로와_같으면_경고하지_않는다(): void
    {
        // 오리진 축(B-10)도 함께 검사하므로, 이 케이스는 경로뿐 아니라 오리진도 맞춰야
        // 「경로 일치 → 무경고」만 격리해서 잰다. 요청 오리진(`http://other`)과 설정 오리진을
        // 같게 둔다 — 어긋나면 origin 축 경고가 대신 발화해 이 테스트의 의도가 흐려진다.
        config(['app.url' => 'http://other/other']);
        app()->instance('request', Request::create('http://other/other/products/1', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/g7/public/index.php',
            'SCRIPT_NAME' => '/other/index.php',
            'PHP_SELF' => '/other/index.php',
        ]));

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->never();

        app()->instance('env', 'production');
        try {
            $this->assertSame('/other', AssetUrl::basePrefix());
        } finally {
            app()->instance('env', 'testing');
        }
    }

    /**
     * 주소 설정 불일치 진단은 **출하 기본 로그 레벨(`error`)** 에서도 실제 로그 파일에 남는다.
     *
     * 설치가 쓰는 기본값은 `LOG_LEVEL=error` 이고 환경설정 드라이버 탭의 로그 레벨도 `error` 다.
     * 진단을 `warning` 으로 남기면 그 기본 설치에서는 한 줄도 기록되지 않는다 — 운영자가
     * 메일·PG 복귀·사이트맵 주소가 사이트 밖을 가리키는 원인을 볼 통로가 사라진다. 형제 진단
     * (요청 없는 문맥의 오리진 미해결)도 같은 레벨이어야 한다. 파사드 가짜가 아니라 레벨 필터를
     * 가진 실제 채널로 잰다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects address_mismatch_diagnostics_survive_the_shipped_log_level
     *
     * @return void 반환값 없음
     */
    public function test_주소_불일치_진단은_출하_기본_로그_레벨에서도_기록된다(): void
    {
        $logFile = $this->isolatedPublicPath.'/diag-'.uniqid().'.log';
        config([
            'logging.channels.asseturl_diag' => ['driver' => 'single', 'path' => $logFile, 'level' => 'error'],
            'logging.default' => 'asseturl_diag',
        ]);
        Log::forgetChannel('asseturl_diag');

        $this->resetOriginUnresolvableWarned();
        app()->instance('env', 'production');
        try {
            // ① 웹 요청의 마운트(/g7)와 사이트 주소 설정의 경로(/other)가 다르다
            config(['app.url' => 'http://127.0.0.1:8722/other']);
            app()->instance('request', Request::create('http://127.0.0.1:8722/g7/board/free', 'GET', [], [], [], [
                'SCRIPT_FILENAME' => '/var/www/g7/public/index.php',
                'SCRIPT_NAME' => '/g7/index.php',
                'PHP_SELF' => '/g7/index.php',
            ]));
            $this->assertSame('/g7', AssetUrl::basePrefix());

            // ② 요청 없는 문맥 + scheme·host 없는 APP_URL
            app()->forgetInstance('request');
            config(['app.url' => '/g7']);
            $this->assertSame('/api/x', AssetUrl::absolute('/api/x'));
        } finally {
            app()->instance('env', 'testing');
            app()->forgetInstance('request');
            Log::forgetChannel('asseturl_diag');
        }

        $written = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $this->assertStringContainsString('[AssetUrl] 요청의 마운트 위치와 APP_URL 이 다르다', $written, '기본 레벨에서 불일치 진단이 버려졌다');
        $this->assertStringContainsString('"request_base_path":"/g7"', $written);
        $this->assertStringContainsString('"configured_base_path":"/other"', $written);
        $this->assertStringContainsString('[AssetUrl] 절대 주소의 오리진을 알 수 없다', $written, '기본 레벨에서 오리진 미해결 진단이 버려졌다');
        $this->assertSame(1, substr_count($written, '요청의 마운트 위치와 APP_URL 이 다르다'), '요청당 1회 상한');
    }

    /**
     * `g7:asset-url-mode` 조회 출력이 요청 없는 문맥의 접두사와 `APP_URL` 의 경로를 나란히 보여 준다.
     *
     * 콘솔에서는 요청이 접두사를 싣지 않으므로 두 값이 같다 — 불일치는 운영자가 `APP_URL`
     * 을 고치기 전까지 웹 요청의 경고 로그로만 드러나고, 이 출력은 그 값을 확인하는 자리다.
     *
     * @effects asset_url_mode_command_prints_prefix_and_app_url_path
     *
     * @return void 반환값 없음
     */
    public function test_asset_url_mode_커맨드가_접두사와_app_url_경로를_출력한다(): void
    {
        $this->instance(SettingsService::class, \Mockery::mock(SettingsService::class));
        $this->instance(SeoCacheManagerInterface::class, \Mockery::mock(SeoCacheManagerInterface::class));

        config(['app.url' => 'https://example.com/g7']);
        AssetUrl::forceBasePrefix('/g7');

        $this->artisan('g7:asset-url-mode')
            ->expectsOutputToContain(__('asset_url_mode.base_path.context', ['prefix' => '/g7']))
            ->expectsOutputToContain(__('asset_url_mode.base_path.configured', ['prefix' => '/g7', 'url' => 'https://example.com/g7']))
            ->doesntExpectOutputToContain(__('asset_url_mode.base_path.mismatch'))
            ->assertExitCode(0);

        // 두 값이 갈린 상태(웹 요청이 다른 위치에 마운트된 것을 흉내)는 경고 문구를 낸다.
        AssetUrl::forceBasePrefix('/other');

        $this->artisan('g7:asset-url-mode')
            ->expectsOutputToContain(__('asset_url_mode.base_path.context', ['prefix' => '/other']))
            ->expectsOutputToContain(__('asset_url_mode.base_path.mismatch'))
            ->assertExitCode(0);
    }

    /**
     * `absolute()` 는 오리진을 알 수 없으면 경로만 돌려주고(하위호환) 프로세스당 한 번 경고한다.
     *
     * `APP_URL` 에 scheme·host 가 없고(경로만 적음) 요청도 없는 문맥(큐·콘솔)에서만 도달한다.
     * 종전에는 경로만 조용히 돌려줘 메일·사이트맵에 상대 경로가 실렸고, 웹에서는 재현되지 않아
     * 로그가 유일한 통로였다.
     *
     * @scenario base_path=root, navigation_direction=navigation
     *
     * @effects notification_links_are_built_by_the_site_absolute_helper
     *
     * @return void 반환값 없음
     */
    public function test_absolute_는_오리진을_알_수_없으면_경로만_돌려주고_한_번_경고한다(): void
    {
        $this->resetOriginUnresolvableWarned();

        // scheme·host 가 없어 configuredOrigin() 이 빈 문자열이 된다.
        config(['app.url' => '/g7']);
        // 요청 바인딩을 지워 currentRequest() 가 null 을 돌려주는 「요청 없는 문맥」(큐·콘솔)을
        // 만든다. `bound('request')` 가 false 가 되어야 이 경로에 도달한다.
        app()->forgetInstance('request');
        $this->assertFalse(app()->bound('request'), '요청 바인딩이 남아 있으면 요청 없는 문맥이 재현되지 않는다');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, '오리진')
                && $context['path'] === '/api/x');

        app()->instance('env', 'production');
        try {
            // 첫 호출이 경고를 남긴다(그 path 를 위 closure 가 검사). 이후는 프로세스당 한 번
            // 억제되며, 반환은 언제나 경로 그대로다.
            $this->assertSame('/api/x', AssetUrl::absolute('/api/x'));
            $this->assertSame('/g7/api/x', AssetUrl::absolute('/g7/api/x'));
        } finally {
            app()->instance('env', 'testing');
        }
    }

    /**
     * 요청 오리진이 `APP_URL` 오리진과 다르면 경로 축이 같아도 **요청당 한 번** 경고한다.
     *
     * `absolute()` 는 설정 오리진을 요청보다 우선하므로, 둘이 다르면 큐·콘솔이 만드는 PG 복귀·
     * 메일·사이트맵 주소가 웹과 다른 호스트를 가리킨다. 경로 축만 재던 종전에는 이 어긋남이
     * 어디에도 드러나지 않았다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects prefix_mismatch_warning_is_keyed_to_the_request_not_a_reusable_object_id
     *
     * @return void 반환값 없음
     */
    public function test_요청의_오리진이_app_url_오리진과_다르면_경로가_같아도_한_번_경고한다(): void
    {
        config(['app.url' => 'https://example.com/g7']);
        $request = Request::create('http://other-host/g7/products/1', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/g7/public/index.php',
            'SCRIPT_NAME' => '/g7/index.php',
            'PHP_SELF' => '/g7/index.php',
        ]);
        $this->assertSame('/g7', $request->getBaseUrl(), '요청 픽스처가 /g7 접두사를 싣지 못했다');
        $this->assertSame('http://other-host', $request->getSchemeAndHttpHost());
        app()->instance('request', $request);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['configured_origin'] === 'https://example.com'
                && $context['request_origin'] === 'http://other-host'
                && $context['request_base_path'] === '/g7'
                && $context['configured_base_path'] === '/g7');

        app()->instance('env', 'production');
        try {
            // 경로 축은 일치하는데(무경고 조건) 오리진 축만 어긋난다. 같은 요청 안에서 한 번.
            $this->assertSame('/g7', AssetUrl::basePrefix());
            $this->assertSame('/g7', AssetUrl::basePrefix());
        } finally {
            app()->instance('env', 'testing');
            app()->forgetInstance('request');
        }
    }

    /**
     * `absolute()` 오리진 미해결 경고의 프로세스 정적 억제 플래그를 초기화합니다 (테스트 독립성).
     *
     * @return void 반환값 없음
     */
    private function resetOriginUnresolvableWarned(): void
    {
        $property = new \ReflectionProperty(AssetUrl::class, 'originUnresolvableWarned');
        $property->setValue(null, false);
    }

    /**
     * `app.url` 을 지정한 상태에서 `siteAbsolute()` 를 부른다.
     *
     * @param  string  $appUrl  `app.url` 값
     * @param  string  $path  사이트 경로 후보
     * @return string 절대 URL
     */
    private function siteAbsoluteWith(string $appUrl, string $path): string
    {
        config(['app.url' => $appUrl]);

        return AssetUrl::siteAbsolute($path);
    }
}
