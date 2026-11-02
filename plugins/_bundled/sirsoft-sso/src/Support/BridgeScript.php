<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Sso\Support;

use App\Extension\PluginManager;
use App\Support\AssetUrl;

/**
 * 독립 HTML 응답(SAML POST 바인딩 자동 제출)의 스크립트 태그 조립 (#158 strict CSP)
 *
 * 이 플러그인이 직접 내는 HTML 페이지는 엔진 셸 밖이라, 동작을 인라인 `onload`·`<script>` 로 넣으면
 * 사이트가 `Content-Security-Policy: script-src 'self'` 를 걸었을 때 실행되지 않는다. 동작은 플러그인 루트의
 * 손작성 ES5 정적 파일(`assets/bridge/*.js`, 빌드 산출물 폴더 `dist/` 가 아니다 — 빌드가 지운다)에 둔다.
 */
final class BridgeScript
{
    /** 플러그인 식별자 */
    private const PLUGIN_IDENTIFIER = 'sirsoft-sso';

    /**
     * 브리지 스크립트 `<script src>` 태그를 만듭니다.
     *
     * @param  string  $file  `assets/bridge/` 아래 파일명 (예: `saml-autosubmit.js`)
     * @return string 스크립트 태그 HTML
     */
    public static function tag(string $file): string
    {
        return '<script src="'.e(self::url($file)).'"></script>';
    }

    /**
     * 브리지 스크립트 URL 을 만듭니다 (플러그인 자산 경로 + 플러그인 버전 캐시 무효화).
     *
     * @param  string  $file  `assets/bridge/` 아래 파일명
     * @return string 같은 출처 자산 URL
     */
    public static function url(string $file): string
    {
        $version = null;

        try {
            $version = app(PluginManager::class)->getPlugin(self::PLUGIN_IDENTIFIER)?->getVersion();
        } catch (\Throwable) {
            // 버전을 못 읽어도 자산 URL 자체는 만든다 (캐시 무효화만 빠진다)
        }

        return AssetUrl::pluginAsset(self::PLUGIN_IDENTIFIER, 'assets/bridge/'.$file, $version);
    }
}
