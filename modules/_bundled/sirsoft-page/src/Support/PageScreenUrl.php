<?php

namespace Modules\Sirsoft\Page\Support;

use App\Support\ScreenUrl;

/**
 * 페이지 화면 주소 — 코어 화면 주소 헬퍼({@see ScreenUrl})의 페이지 래퍼.
 *
 * 사람이 여는 주소(검색 결과·사이트맵)는 이 래퍼로 만들어 「사용자 화면 URL」과 주소 필터를 따른다.
 * G7 엔진 SEO 캐시는 {@see defaultPath()} 로 필터 없는 기본 경로를 쓴다.
 *
 * 키: `page.show`(`/page/{slug}`)
 */
final class PageScreenUrl
{
    /** 페이지 보기 */
    public const SHOW = '/page/{slug}';

    /**
     * 페이지 주소.
     *
     * @param  string  $slug  페이지 슬러그
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function show(string $slug, bool $absolute = false): string
    {
        return $absolute
            ? ScreenUrl::absolute('page.show', self::SHOW, ['slug' => $slug])
            : ScreenUrl::path('page.show', self::SHOW, ['slug' => $slug]);
    }

    /**
     * 필터를 거치지 않은 G7 기본 경로.
     *
     * @param  string  $slug  페이지 슬러그
     * @return string 경로
     */
    public static function defaultPath(string $slug): string
    {
        return '/page/'.$slug;
    }
}
