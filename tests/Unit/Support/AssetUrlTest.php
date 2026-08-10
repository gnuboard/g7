<?php

namespace Tests\Unit\Support;

use App\Support\AssetUrl;
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
    protected function tearDown(): void
    {
        AssetUrl::forceMode(null);
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
}
