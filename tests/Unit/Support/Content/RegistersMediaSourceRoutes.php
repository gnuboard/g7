<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\MediaSourceRoutes;
use Illuminate\Support\Facades\Route;

/**
 * 시험용 본문 미디어 서빙 라우트 등록 — 라우터에 라우트를 만들고 그 이름을 새 레지스트리에 등록한다 (#887)
 *
 * 레지스트리는 새 인스턴스로 바꿔 넣는다 — 부팅 때 확장 어댑터가 등록한 이름과 섞이지 않게(고정본·패턴 단언이 결정적이게).
 */
trait RegistersMediaSourceRoutes
{
    /** 번들 게시판 서빙 라우트와 같은 모양 — 고정본·표본 주소가 쓰는 경로 */
    public const BOARD_MEDIA_URI = 'api/modules/sirsoft-board/boards/{slug}/content-media/{hash}';

    /** 번들 게시판 서빙 라우트 이름 */
    public const BOARD_MEDIA_ROUTE = 'api.modules.sirsoft-board.boards.content-media.show';

    /**
     * 빈 레지스트리로 바꿔 넣고 돌려줍니다.
     *
     * @return MediaSourceRoutes 레지스트리
     */
    public static function freshMediaSourceRoutes(): MediaSourceRoutes
    {
        $registry = new MediaSourceRoutes;
        app()->instance(MediaSourceRoutes::class, $registry);

        return $registry;
    }

    /**
     * 서빙 라우트를 만들고 이름을 레지스트리에 등록합니다.
     *
     * @param  string  $uri  라우트 URI
     * @param  string  $name  라우트 이름
     * @param  array<string, string>  $wheres  파라미터 제약
     * @return void 반환값 없음
     */
    public static function registerMediaRoute(string $uri, string $name, array $wheres = ['hash' => '[a-zA-Z0-9]{12}']): void
    {
        Route::get($uri, fn () => '')->where($wheres)->name($name);

        app(MediaSourceRoutes::class)->register($name);
    }

    /**
     * 빈 레지스트리에 번들 게시판 모양 서빙 라우트 하나를 등록합니다.
     *
     * @return void 반환값 없음
     */
    public static function registerBoardMediaRoute(): void
    {
        self::freshMediaSourceRoutes();
        self::registerMediaRoute(self::BOARD_MEDIA_URI, self::BOARD_MEDIA_ROUTE);
    }
}
