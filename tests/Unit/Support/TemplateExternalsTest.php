<?php

namespace Tests\Unit\Support;

use App\Support\TemplateExternals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateExternalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_supported_externals_and_attributes(): void
    {
        $externals = TemplateExternals::normalize([
            [
                'id' => 'style-main',
                'type' => 'style',
                'url' => 'https://cdn.example.com/main.css',
                'preconnect' => 'https://cdn.example.com',
                'crossorigin' => true,
                'integrity' => 'sha384-style',
                'referrerpolicy' => 'no-referrer',
                'media' => 'screen',
            ],
            [
                'id' => 'script-default',
                'type' => 'script',
                'url' => 'https://cdn.example.com/default.js',
                'defer' => true,
            ],
            [
                'id' => 'preload-font',
                'type' => 'preload',
                'url' => 'https://cdn.example.com/font.woff2',
                'as' => 'font',
                'mimeType' => 'font/woff2',
                'fetchpriority' => 'high',
                'crossorigin' => 'use-credentials',
            ],
        ]);

        $this->assertCount(3, $externals);
        $this->assertSame('anonymous', $externals[0]['crossorigin']);
        $this->assertSame('before-template', $externals[1]['position']);
        $this->assertTrue($externals[1]['defer']);
        $this->assertSame('font/woff2', $externals[2]['mimeType']);
        $this->assertSame('high', $externals[2]['fetchpriority']);

        $styleAttributes = TemplateExternals::linkAttributes($externals[0]);
        $this->assertSame('stylesheet', $styleAttributes['rel']);
        $this->assertSame('https://cdn.example.com/main.css', $styleAttributes['href']);
        $this->assertSame('style-main', $styleAttributes['id']);
        $this->assertSame('screen', $styleAttributes['media']);

        $scriptAttributes = TemplateExternals::scriptAttributes($externals[1]);
        $this->assertSame('https://cdn.example.com/default.js', $scriptAttributes['src']);
        $this->assertTrue($scriptAttributes['defer']);
    }

    public function test_filters_invalid_and_legacy_external_declarations(): void
    {
        $externals = TemplateExternals::normalize([
            ['url' => 'https://legacy.example.com/style.css'],
            ['type' => 'style', 'url' => 'http://cdn.example.com/insecure.css'],
            ['type' => 'preload', 'url' => 'https://cdn.example.com/missing-as.woff2'],
            ['type' => 'script', 'url' => 'https://cdn.example.com/both.js', 'async' => true, 'defer' => true],
            ['type' => 'script', 'url' => 'https://cdn.example.com/invalid-position.js', 'position' => 'after-core'],
            ['type' => 'style', 'url' => 'https://cdn.example.com/valid.css'],
            ['type' => 'style', 'url' => 'https://cdn.example.com/valid.css'],
        ]);

        $this->assertSame([
            [
                'type' => 'style',
                'url' => 'https://cdn.example.com/valid.css',
            ],
        ], $externals);
    }

    public function test_resource_hints_are_deduplicated_by_type_and_origin(): void
    {
        $externals = TemplateExternals::normalize([
            [
                'type' => 'preconnect',
                'url' => 'https://cdn.example.com',
                'crossorigin' => 'anonymous',
            ],
            [
                'type' => 'webfont',
                'url' => 'https://cdn.example.com/font.css',
                'preconnect' => 'https://cdn.example.com',
                'crossorigin' => true,
            ],
            [
                'type' => 'dns-prefetch',
                'url' => 'https://static.example.com',
            ],
        ]);

        $hints = TemplateExternals::resourceHints($externals);

        $this->assertCount(2, $hints);
        $this->assertSame('preconnect', $hints[0]['type']);
        $this->assertSame('https://cdn.example.com', $hints[0]['url']);
        $this->assertSame('dns-prefetch', $hints[1]['type']);
    }

    /**
     * 자체 제공 자산(asset) 선언은 same-origin 자산 URL 로 해석된다.
     *
     * @scenario asset_class=vendored, outcome=loaded
     *
     * @effects template_external_asset_field_resolves_to_asset_url, runtime_asset_served_same_origin
     */
    public function test_asset_field_resolves_to_same_origin_asset_url(): void
    {
        $normalized = TemplateExternals::normalize([
            ['id' => 'fontawesome', 'type' => 'style', 'asset' => 'vendor/font-awesome/6.4.0/css/all.inlined.css'],
        ], 'sirsoft-basic', 123);

        $this->assertCount(1, $normalized);
        $this->assertStringStartsWith('/api/templates/assets/sirsoft-basic', $normalized[0]['url']);
        $this->assertStringNotContainsString('/dist/', $normalized[0]['url']);
        $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $normalized[0]['url']);
    }

    /**
     * same-origin 항목에서는 외부 전용 키를 무시한다.
     *
     * 자기 서버 자산에 preconnect 를 걸거나 CORS 모드를 붙일 이유가 없다.
     *
     *
     * @effects template_external_same_origin_ignores_preconnect_and_crossorigin
     */
    public function test_same_origin_item_drops_external_only_keys(): void
    {
        $normalized = TemplateExternals::normalize([
            [
                'id' => 'pretendard',
                'type' => 'webfont',
                'asset' => 'vendor/pretendard/1.3.9/pretendard-variable.css',
                'preconnect' => 'https://cdn.example.com',
                'crossorigin' => 'anonymous',
            ],
        ], 'sirsoft-basic', 1);

        $this->assertArrayNotHasKey('preconnect', $normalized[0]);
        $this->assertArrayNotHasKey('crossorigin', $normalized[0]);
        $this->assertSame([], TemplateExternals::resourceHints($normalized));
    }

    /**
     * same-origin 절대 경로 url 도 그대로 통과시킨다.
     *
     *
     * @effects template_external_asset_field_resolves_to_asset_url
     */
    public function test_same_origin_path_url_is_accepted(): void
    {
        $normalized = TemplateExternals::normalize([
            ['id' => 'x', 'type' => 'style', 'url' => '/api/templates/assets/t/vendor/a.css'],
        ], 't', null);

        $this->assertCount(1, $normalized);
        $this->assertSame('/api/templates/assets/t/vendor/a.css', $normalized[0]['url']);
    }

    /**
     * 브라우저가 외부 origin 으로 해석하는 형태는 same-origin 으로 인정하지 않는다.
     *
     * 접두 문자열만 보면 path 처럼 보이지만 브라우저는 authority 로 읽는 값들이다.
     *
     * @scenario asset_class=vendored, outcome=failed
     *
     * @effects template_external_invalid_url_is_logged_not_silently_dropped
     */
    public function test_authority_bypass_shapes_are_rejected(): void
    {
        $normalized = TemplateExternals::normalize([
            ['id' => 'a', 'type' => 'style', 'url' => '//evil.example.com/x.css'],
            ['id' => 'b', 'type' => 'style', 'url' => '/\\evil.example.com/x.css'],
            ['id' => 'c', 'type' => 'style', 'url' => 'ftp://evil.example.com/x.css'],
        ], 't', null);

        $this->assertSame([], $normalized);
    }

    /**
     * asset 경로가 확장 디렉토리를 벗어나면 거부한다.
     *
     *
     * @effects template_external_invalid_url_is_logged_not_silently_dropped
     */
    public function test_asset_path_escape_is_rejected(): void
    {
        $normalized = TemplateExternals::normalize([
            ['id' => 'a', 'type' => 'style', 'asset' => '../../.env'],
            ['id' => 'b', 'type' => 'style', 'asset' => '/etc/passwd'],
        ], 'sirsoft-basic', null);

        $this->assertSame([], $normalized);
    }

    /**
     * 구동 자산(style/webfont/script)에는 실패 신호가 붙는다.
     *
     * 이 태그들은 서버가 HTML 에 직접 심으므로, 브라우저가 자산에 도달하지 못해도
     * 자바스크립트에는 아무 신호가 오지 않는다. 실패는 "아이콘이 0×0 으로 사라진 화면"
     * 으로만 나타나고 자체 서버 로그에도 흔적이 남지 않아 운영자가 원인을 특정할 수 없다.
     * `onerror` 가 그 유일한 통로다.
     */
    public function test_runtime_assets_carry_a_failure_signal(): void
    {
        $normalized = TemplateExternals::normalize([
            ['id' => 'fontawesome', 'type' => 'style', 'asset' => 'vendor/font-awesome/6.4.0/css/all.css'],
            ['id' => 'pretendard', 'type' => 'webfont', 'asset' => 'vendor/pretendard/1.3.9/pretendard.css'],
            ['id' => 'bootstrapper', 'type' => 'script', 'asset' => 'vendor/boot/boot.js'],
        ], 'sirsoft-basic', 1);

        $this->assertCount(3, $normalized);

        foreach ($normalized as $item) {
            $attributes = $item['type'] === 'script'
                ? TemplateExternals::scriptAttributes($item)
                : TemplateExternals::linkAttributes($item);

            $this->assertArrayHasKey('onerror', $attributes, $item['id'].' 에 실패 신호가 있어야 한다');
            $this->assertStringContainsString('__g7ExternalAssetFailed', $attributes['onerror']);
            $this->assertStringContainsString("'".$item['id']."'", $attributes['onerror']);
        }
    }

    /**
     * 힌트(preload/preconnect 등)에는 실패 신호를 붙이지 않는다.
     *
     * 힌트는 실패해도 화면 기능이 사라지지 않는다. 여기에까지 배너를 띄우면 안내가
     * 잡음이 되어 정작 조작 불능을 만드는 실패가 묻힌다.
     */
    public function test_resource_hints_do_not_carry_a_failure_signal(): void
    {
        $normalized = TemplateExternals::normalize([
            ['id' => 'pre', 'type' => 'preload', 'asset' => 'vendor/font.woff2', 'as' => 'font'],
        ], 'sirsoft-basic', 1);

        $this->assertCount(1, $normalized);
        $this->assertArrayNotHasKey('onerror', TemplateExternals::linkAttributes($normalized[0]));
    }

    /**
     * 실패 신호 라벨은 id 가 없으면 파일명으로 떨어진다 (운영자가 대상을 알아볼 수 있어야 한다).
     */
    public function test_failure_signal_label_falls_back_to_file_name(): void
    {
        $normalized = TemplateExternals::normalize([
            ['type' => 'style', 'asset' => 'vendor/font-awesome/6.4.0/css/all.inlined.css'],
        ], 'sirsoft-basic', 1);

        $this->assertCount(1, $normalized);
        $this->assertStringContainsString("'all.inlined.css'", TemplateExternals::linkAttributes($normalized[0])['onerror']);
    }
}
