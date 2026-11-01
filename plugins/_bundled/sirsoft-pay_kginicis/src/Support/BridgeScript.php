<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Support;

use App\Extension\PluginManager;
use App\Support\AssetUrl;

/**
 * 독립 HTML 응답(결제·본인인증 브리지)의 스크립트 태그 조립 (#158 strict CSP)
 *
 * 이 플러그인이 직접 내는 HTML 페이지(팝업·iframe 이 여는 브리지·확인창)는 엔진 셸 밖이라, 종전에는
 * 동작을 인라인 `<script>` 로 넣었다. 사이트가 `Content-Security-Policy: script-src 'self'` 를 걸면
 * 그 코드가 실행되지 않아 결제·인증 결과가 주문 화면에 전달되지 않는다. 그래서 동작은 플러그인 루트의
 * 손작성 ES5 정적 파일(`assets/bridge/*.js`, 빌드 산출물 폴더 `dist/` 가 아니다 — 빌드가 지운다)에 두고,
 * 서버 값은 비실행 JSON 데이터 블록으로만 넘긴다.
 */
final class BridgeScript
{
    /** 플러그인 식별자 */
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    /**
     * 브리지 스크립트 `<script src>` 태그를 만듭니다.
     *
     * @param  string  $file  `assets/bridge/` 아래 파일명 (예: `popup-bridge.js`)
     * @param  array<string, string>  $attributes  덧붙일 속성 (data-* 등, 값은 이스케이프된다)
     * @return string 스크립트 태그 HTML
     */
    public static function tag(string $file, array $attributes = []): string
    {
        $html = '<script src="'.e(self::url($file)).'"';

        foreach ($attributes as $name => $value) {
            $html .= ' '.e($name).'="'.e($value).'"';
        }

        return $html.'></script>';
    }

    /**
     * 비실행 JSON 데이터 블록을 만듭니다 (`</script>` 탈출 방지 이스케이프 포함).
     *
     * @param  string  $id  요소 id
     * @param  array<string, mixed>  $data  넘길 값
     * @return string `<script type="application/json">` 태그 HTML
     */
    public static function data(string $id, array $data): string
    {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return '<script type="application/json" id="'.e($id).'">'.($json === false ? '{}' : $json).'</script>';
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
