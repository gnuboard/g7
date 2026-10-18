<?php

namespace Modules\Sirsoft\Board\Support;

use App\Support\ScreenUrl;

/**
 * 게시판 화면 주소 — 코어 화면 주소 헬퍼({@see ScreenUrl})의 게시판 래퍼 (#792).
 *
 * 사람이 여는 주소(알림 링크·검색 결과·사이트맵)는 이 래퍼로 만들어 「사용자 화면 URL」과 주소 필터
 * `core.screen_url.filter_path` 를 따른다. G7 엔진 SEO 캐시처럼 G7 화면 자체의 주소가 필요한 곳은
 * {@see defaultPath()} 로 필터 없는 기본 경로를 쓴다.
 *
 * 키: `board.index`(`/boards`) · `board.list`(`/board/{slug}`) · `board.post`(`/board/{slug}/{id}`)
 */
final class BoardScreenUrl
{
    /** 게시판 목록 */
    public const INDEX = '/boards';

    /** 게시판(글 목록) */
    public const LIST = '/board/{slug}';

    /** 글보기 */
    public const POST = '/board/{slug}/{id}';

    /**
     * 게시판 목록 주소.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function index(bool $absolute = false): string
    {
        return self::make('board.index', self::INDEX, [], $absolute);
    }

    /**
     * 게시판(글 목록) 주소.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function list(string $slug, bool $absolute = false): string
    {
        return self::make('board.list', self::LIST, ['slug' => $slug], $absolute);
    }

    /**
     * 글보기 주소.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int|string  $id  게시글 ID
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function post(string $slug, int|string $id, bool $absolute = false): string
    {
        return self::make('board.post', self::POST, ['slug' => $slug, 'id' => $id], $absolute);
    }

    /**
     * 필터를 거치지 않은 G7 기본 경로 (SEO 캐시 무효화 등 G7 화면 자체의 주소).
     *
     * @param  string  $template  기본 경로 상수 (INDEX·LIST·POST)
     * @param  array<string, scalar>  $params  자리 값
     * @return string 경로
     */
    public static function defaultPath(string $template, array $params = []): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', fn (array $m) => (string) ($params[$m[1]] ?? $m[0]), $template);
    }

    /**
     * 헬퍼 호출.
     *
     * @param  string  $key  화면 키
     * @param  string  $template  기본 경로
     * @param  array<string, scalar>  $params  자리 값
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    private static function make(string $key, string $template, array $params, bool $absolute): string
    {
        return $absolute
            ? ScreenUrl::absolute($key, $template, $params)
            : ScreenUrl::path($key, $template, $params);
    }
}
