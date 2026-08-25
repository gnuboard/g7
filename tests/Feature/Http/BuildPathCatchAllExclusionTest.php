<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * 정적 게시본 미스(`.json`)는 SPA catch-all 에 매칭되지 않는다 (#122 / 공개 #47 상호작용).
 *
 * catch-all 의 확장자 제외 lookahead(`(?!.*\.(js|css|…))`)는 끝 앵커가 없어 부분일치로
 * 동작한다 — `.json` 은 `.js` 를 부분 문자열로 포함하므로 함께 제외된다. 덕분에 정적
 * 게시본(`/build/ext/{v}/**.json`) 미스는 `template.dependencies` + `seo` 미들웨어와
 * blade 풀 렌더를 거치지 않고 즉시 404 가 된다 (미스 비용 < API 폴백).
 *
 * 이 테스트는 그 성질을 계약으로 잠근다 — 훗날 lookahead 에 끝 앵커(`$`)를 붙이는
 * "정리" 가 들어오면 정적 미스가 SPA 풀 렌더 경유로 조용히 비싸진다.
 */
class BuildPathCatchAllExclusionTest extends TestCase
{
    /**
     * 정적 게시 트리 미스는 유저 catch-all 에 매칭되지 않는다.
     *
     * @effects static_miss_skips_spa_catch_all
     */
    public function test_build_경로는_유저_catch_all_에_매칭되지_않는다(): void
    {
        $this->expectException(NotFoundHttpException::class);

        app('router')->getRoutes()->match(
            Request::create('/build/ext/1787637589/templates/sirsoft-basic/lang/ko.json', 'GET')
        );
    }

    /**
     * 일반 SPA 경로는 여전히 catch-all 에 매칭된다 (과잉 제외 방지 가드).
     */
    public function test_일반_경로는_여전히_유저_catch_all_에_매칭된다(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/some-page', 'GET'));

        $this->assertContains('GET', $route->methods());
    }
}
