<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 동봉 자산 선언 ↔ 실제 파일 정합 테스트
 *
 * 편집기 본체를 CDN 이 아니라 플러그인이 직접 담게 되면서, "선언한 경로에 파일이
 * 실제로 있는가" 가 새로운 실패 지점이 됐다. 이 정합이 깨지면 개발 환경에서는
 * 아무 증상이 없고 **배포본에서만** 편집기가 뜨지 않는다 — 자산 404 는 예외를
 * 남기지 않고 빈 화면으로만 나타나기 때문이다.
 *
 * 경로가 세 곳(레이아웃 확장 JSON · TS 상수 · 실제 디렉토리)에 나뉘어 있으므로
 * 셋이 같은 버전을 가리키는지도 함께 본다.
 */
class VendoredAssetManifestTest extends PluginTestCase
{
    /** 동봉 자산 루트 (플러그인 루트 기준) */
    private const VENDOR_ROOT = 'dist/vendor/ckeditor5';

    /**
     * 플러그인 루트 경로를 돌려줍니다.
     *
     * @return string 플러그인 루트 절대 경로
     */
    private function pluginPath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * `ckeditorAssets.ts` 가 선언한 동봉 버전을 읽습니다.
     *
     * @return string 버전 문자열
     */
    private function declaredVersion(): string
    {
        $source = file_get_contents($this->pluginPath().'/resources/js/handlers/ckeditorAssets.ts');

        $this->assertIsString($source, 'ckeditorAssets.ts 를 읽을 수 없습니다.');
        $this->assertSame(
            1,
            preg_match("/CKEDITOR5_VERSION\s*=\s*'([^']+)'/", $source, $matches),
            'ckeditorAssets.ts 에서 CKEDITOR5_VERSION 을 찾을 수 없습니다.'
        );

        return $matches[1];
    }

    /**
     * @scenario asset_class=vendored, outcome=loaded
     *
     * @effects vendored_asset_declared_path_exists_on_disk
     */
    #[Test]
    public function 동봉_디렉토리에_필수_파일이_모두_존재한다(): void
    {
        $version = $this->declaredVersion();
        $base = $this->pluginPath().'/'.self::VENDOR_ROOT.'/'.$version;

        foreach (['ckeditor5.umd.js', 'ckeditor5.css', 'LICENSE.md', 'translations/ko.umd.js'] as $relative) {
            $this->assertFileExists($base.'/'.$relative, "동봉 자산이 없습니다: {$relative}");
        }
    }

    /**
     * @effects vendored_asset_declared_path_exists_on_disk, runtime_asset_served_same_origin
     */
    #[Test]
    public function 레이아웃_확장이_가리키는_스크립트가_실제로_존재한다(): void
    {
        $extension = json_decode(
            file_get_contents($this->pluginPath().'/resources/extensions/html-editor.json'),
            true
        );

        $src = $extension['scripts'][0]['src'] ?? null;

        $this->assertIsString($src, 'html-editor.json 에 스크립트 src 가 없습니다.');
        $this->assertStringStartsWith(
            '/api/plugins/assets/sirsoft-ckeditor5/',
            $src,
            '편집기 본체는 same-origin 자산 경로여야 합니다 (외부 CDN 금지).'
        );

        $relative = substr($src, strlen('/api/plugins/assets/sirsoft-ckeditor5/'));

        $this->assertFileExists(
            $this->pluginPath().'/'.$relative,
            "레이아웃 확장이 가리키는 파일이 없습니다: {$relative}"
        );
    }

    #[Test]
    public function 선언한_버전과_레이아웃_확장_경로의_버전이_일치한다(): void
    {
        $version = $this->declaredVersion();
        $extension = json_decode(
            file_get_contents($this->pluginPath().'/resources/extensions/html-editor.json'),
            true
        );

        $this->assertStringContainsString(
            self::VENDOR_ROOT.'/'.$version.'/',
            $extension['scripts'][0]['src'] ?? '',
            'TS 상수의 버전과 레이아웃 확장 경로의 버전이 어긋났습니다.'
        );
    }

    #[Test]
    public function 자체_제공으로_전환했으므로_신뢰_외부_호스트_선언이_없다(): void
    {
        $manifest = json_decode(file_get_contents($this->pluginPath().'/plugin.json'), true);

        $this->assertArrayNotHasKey(
            'trusted_script_hosts',
            $manifest,
            '동봉으로 전환한 뒤에도 외부 호스트 선언이 남아 있으면, 그 호스트로 되돌아가는 회귀를 막지 못합니다.'
        );
    }

    /**
     * @effects no_third_party_request_on_page_load
     */
    #[Test]
    public function 런타임_소스에_외부_cd_n_주소가_남아_있지_않다(): void
    {
        $sources = [
            'resources/js/handlers/initEditor.ts',
            'resources/js/index.ts',
            'resources/extensions/html-editor.json',
        ];

        foreach ($sources as $relative) {
            $content = file_get_contents($this->pluginPath().'/'.$relative);

            // 주석의 설명 문구는 대상이 아니다 — 실제 로드 대상이 되는 URL 형태만 본다
            $this->assertDoesNotMatchRegularExpression(
                '#https?://cdn\.ckeditor\.com#',
                $content,
                "{$relative} 에 외부 CDN 주소가 남아 있습니다."
            );
        }
    }
}
