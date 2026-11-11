<?php

namespace Tests\Unit\Support;

use App\Support\TemplateExternals;
use Tests\TestCase;

/**
 * 템플릿 externals 의 외부 스타일시트 호스트 집계 (7.1.6)
 *
 * CSP 스타일 정책(`style-src`·`style-src-elem`)의 자동 허용 호스트다. 스타일시트로 실리는 항목
 * (style·webfont, preload 중 `as=style`)의 https 호스트만 모으고, 같은 출처 자산은 `'self'` 가 덮으므로 뺀다.
 * script 호스트 집계({@see TemplateExternals::externalScriptHosts()})와 호스트 추출부를 공유한다.
 *
 * DB 를 쓰지 않는다 — 기존 `TemplateExternalsTest` 는 `RefreshDatabase` 를 쓰므로 파일을 따로 둔다.
 */
class TemplateExternalsStyleHostsTest extends TestCase
{
    /**
     * 외부 스타일시트 호스트만 모은다 — script·글꼴 preload·modulepreload·리소스 힌트·같은 출처 자산은 뺀다.
     *
     * @scenario case=style_source_template_externals
     *
     * @effects template_external_style_hosts_collected
     *
     * @return void 반환값 없음
     */
    public function test_외부_스타일시트_호스트만_모은다(): void
    {
        $externals = TemplateExternals::normalize([
            ['id' => 'css', 'type' => 'style', 'url' => 'https://css.example.com/a.css'],
            ['id' => 'font', 'type' => 'webfont', 'url' => 'https://fonts.example.com/css2?family=X'],
            ['id' => 'pre-style', 'type' => 'preload', 'as' => 'style', 'url' => 'https://preload.example.com/a.css'],
            ['id' => 'pre-font', 'type' => 'preload', 'as' => 'font', 'url' => 'https://woff.example.com/a.woff2'],
            ['id' => 'sdk', 'type' => 'script', 'url' => 'https://sdk.example.com/a.js'],
            ['id' => 'mod', 'type' => 'modulepreload', 'url' => 'https://mod.example.com/a.js'],
            ['type' => 'preconnect', 'url' => 'https://hint.example.com'],
            ['type' => 'dns-prefetch', 'url' => 'https://dns.example.com'],
            ['id' => 'own', 'type' => 'style', 'asset' => 'vendor/own.css'],
            ['id' => 'path', 'type' => 'style', 'url' => '/assets/own.css'],
        ], 'sirsoft-basic');

        $this->assertSame(
            ['css.example.com', 'fonts.example.com', 'preload.example.com'],
            TemplateExternals::externalStyleHosts($externals)
        );

        // 스크립트 호스트 집계는 그대로다
        $this->assertSame(['sdk.example.com'], TemplateExternals::externalScriptHosts($externals));
    }

    /**
     * 호스트는 소문자로 바꾸고 중복을 지우며 포트를 남긴다.
     *
     * @scenario case=style_source_template_externals
     *
     * @effects template_external_style_hosts_collected
     *
     * @return void 반환값 없음
     */
    public function test_소문자_중복_제거_포트_유지(): void
    {
        $externals = [
            ['type' => 'style', 'url' => 'https://CDN.Example.com/a.css'],
            ['type' => 'webfont', 'url' => 'https://cdn.example.com/b.css'],
            ['type' => 'style', 'url' => 'https://cdn.example.com:8443/c.css'],
            ['type' => 'preload', 'as' => 'style', 'url' => 'https://CDN.example.com:8443/d.css'],
            ['type' => 'style', 'url' => 'http://plain.example.com/e.css'],
            ['type' => 'style', 'url' => 'https://'],
            ['type' => 'style'],
        ];

        $this->assertSame(['cdn.example.com', 'cdn.example.com:8443'], TemplateExternals::externalStyleHosts($externals));
        $this->assertSame([], TemplateExternals::externalStyleHosts([]));
    }
}
