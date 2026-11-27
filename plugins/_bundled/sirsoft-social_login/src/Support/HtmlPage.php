<?php

namespace Plugins\Sirsoft\SocialLogin\Support;

use App\Extension\PluginManager;
use App\Support\AssetUrl;
use Illuminate\Http\Response;

/**
 * 플러그인 웹 라우트가 내보내는 단독 HTML 페이지 (테스트 결과 화면, 모의 제공자 동의 화면).
 *
 * 템플릿 엔진을 거치지 않는 일회성 화면이라 뷰를 등록하지 않고 여기서 조립한다. 본문은 호출자가 `e()` 로 이스케이프한
 * 조각만 넘긴다. 응답은 캐시하지 않는다(`no-store`) — 일회성 결과가 브라우저·프록시에 남지 않게.
 *
 * @since 1.0.0
 */
final class HtmlPage
{
    /** 플러그인 식별자 */
    private const PLUGIN_IDENTIFIER = 'sirsoft-social_login';

    /** 단독 페이지 스타일시트 (플러그인 루트 기준) */
    public const STYLESHEET_PATH = 'assets/pages/html-page.css';

    /**
     * HTML 응답을 만듭니다.
     *
     * @param  string  $title  제목 (이스케이프 전 문자열)
     * @param  string  $bodyHtml  본문 HTML (이스케이프된 조각)
     * @param  int  $status  HTTP 상태
     * @return Response 응답
     */
    public static function render(string $title, string $bodyHtml, int $status = 200): Response
    {
        $lang = e(str_replace('_', '-', app()->getLocale()));
        $title = e($title);
        // 모양은 같은 출처 정적 파일로 싣는다 — 사이트가 스타일 보안 정책(`style-src 'self'`)을 걸면 `<style>` 요소가 막혀
        // 화면이 무스타일로 떴다(코어 공개 #163, 7.1.6). 페이지에는 `<link>` 만 둔다
        $stylesheet = '<link rel="stylesheet" href="'.e(self::stylesheetUrl()).'">';

        $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title}</title>
{$stylesheet}
</head>
<body><main><h1>{$title}</h1>{$bodyHtml}</main></body>
</html>
HTML;

        return response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * 단독 페이지 스타일시트 URL 을 만듭니다 (플러그인 자산 경로 + 플러그인 버전 캐시 무효화).
     *
     * 파일은 플러그인 루트의 손작성 정적 파일(`assets/pages/html-page.css`)이다 — 빌드 산출물 폴더 `dist/` 는 빌드가 지운다.
     *
     * @return string 같은 출처 자산 URL
     */
    public static function stylesheetUrl(): string
    {
        $version = null;

        try {
            $version = app(PluginManager::class)->getPlugin(self::PLUGIN_IDENTIFIER)?->getVersion();
        } catch (\Throwable) {
            // 버전을 못 읽어도 자산 URL 자체는 만든다 (캐시 무효화만 빠진다)
        }

        return AssetUrl::pluginAsset(self::PLUGIN_IDENTIFIER, self::STYLESHEET_PATH, $version);
    }

    /**
     * 정의 목록(dl)을 만듭니다.
     *
     * @param  array<string, string|null>  $rows  라벨 => 값 (이스케이프 전)
     * @return string HTML
     */
    public static function definitionList(array $rows): string
    {
        $html = '<dl>';
        foreach ($rows as $label => $value) {
            $html .= '<dt>'.e($label).'</dt><dd>'.e($value ?? '—').'</dd>';
        }

        return $html.'</dl>';
    }
}
