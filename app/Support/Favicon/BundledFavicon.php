<?php

namespace App\Support\Favicon;

use App\Support\AssetUrl;

/**
 * 파비콘 최하위 계층 — 코어 번들 아이콘 또는 운영자가 직접 넣은 `public/favicon.ico` (#434)
 *
 * DB·API 를 보지 않는다. 그래서 DB 접속 오류·점검 모드(`/api/*` 503)·오류 화면·설치기처럼 아무것도 믿을 수 없는
 * 화면도 이 계층으로 아이콘을 낸다.
 *
 * 판정: `public/favicon.ico` 가 비어 있지 않고 번들 ico 와 내용이 다르면 운영자가 직접 바꿔 둔 파일로
 * 보고 그것 하나만 링크한다. 아니면 번들 3종(ico·svg·apple-touch)을 링크한다. 코어 업데이트는 `favicon.ico` 를
 * 건드리지 않으므로(`G7_UPDATE_EXCLUDES` 기본값) 운영자 파일이 그대로 남는다.
 */
final class BundledFavicon
{
    /**
     * 번들 아이콘 디렉토리 (public 기준)
     */
    public const DIRECTORY = 'assets/core/favicon';

    /**
     * 요청 내 판정 캐시
     *
     * @var array<int, array<string, string>>|null
     */
    private static ?array $linksCache = null;

    /**
     * 최하위 계층 링크 목록을 돌려줍니다.
     *
     * @return array<int, array<string, string>> 링크 속성 목록 (rel·href·type?·sizes?)
     */
    public static function links(): array
    {
        if (self::$linksCache !== null) {
            return self::$linksCache;
        }

        $prefix = AssetUrl::basePrefix();
        $custom = public_path('favicon.ico');

        if (self::isOperatorIco($custom)) {
            return self::$linksCache = [
                ['rel' => 'icon', 'href' => $prefix.'/favicon.ico?v='.(int) @filemtime($custom)],
            ];
        }

        $links = [];

        foreach ([
            ['file' => 'favicon.ico', 'rel' => 'icon', 'sizes' => '32x32'],
            ['file' => 'favicon.svg', 'rel' => 'icon', 'type' => 'image/svg+xml'],
            ['file' => 'apple-touch-icon.png', 'rel' => 'apple-touch-icon'],
        ] as $entry) {
            $path = public_path(self::DIRECTORY.'/'.$entry['file']);

            if (! is_file($path)) {
                continue;
            }

            $link = ['rel' => $entry['rel'], 'href' => $prefix.'/'.self::DIRECTORY.'/'.$entry['file'].'?v='.(int) @filemtime($path)];

            if (isset($entry['type'])) {
                $link['type'] = $entry['type'];
            }

            if (isset($entry['sizes'])) {
                $link['sizes'] = $entry['sizes'];
            }

            $links[] = $link;
        }

        return self::$linksCache = $links;
    }

    /**
     * `public/favicon.ico` 가 운영자가 직접 넣은 파일인지 판정합니다 — 비어 있지 않고 번들 ico 와 내용이 다르다.
     *
     * @param  string  $path  `public/favicon.ico` 절대 경로
     * @return bool 운영자 파일이면 true
     */
    public static function isOperatorIco(string $path): bool
    {
        if (! is_file($path) || (int) @filesize($path) === 0) {
            return false;
        }

        $bundled = public_path(self::DIRECTORY.'/favicon.ico');

        if (! is_file($bundled)) {
            return true;
        }

        return @md5_file($path) !== @md5_file($bundled);
    }

    /**
     * 링크 목록을 `<link>` 태그 HTML 로 바꿉니다 (속성값은 이스케이프).
     *
     * @param  array<int, array<string, string>>  $links  링크 속성 목록
     * @return string HTML
     */
    public static function render(array $links): string
    {
        $tags = [];

        foreach ($links as $link) {
            $attributes = '';

            foreach (['rel', 'type', 'sizes', 'href'] as $name) {
                if (isset($link[$name]) && $link[$name] !== '') {
                    $attributes .= ' '.$name.'="'.htmlspecialchars((string) $link[$name], ENT_QUOTES, 'UTF-8').'"';
                }
            }

            $tags[] = '<link'.$attributes.'>';
        }

        return implode("\n        ", $tags);
    }

    /**
     * 요청 내 판정 캐시를 비웁니다 (테스트·파일 교체 직후용).
     *
     * @return void 반환값 없음
     */
    public static function flush(): void
    {
        self::$linksCache = null;
    }
}
