<?php

namespace Tests\Feature\Http;

use App\Support\StaticExtensionPattern;
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

    /**
     * 에셋 서빙이 허용하는 **모든** 확장자가 catch-all 에서 제외된다 (R1).
     *
     * 회귀 가드: 제외 목록이 라우트 파일에 손으로 적혀 있던 동안 `mjs` · `webp` · `otf`
     * 세 가지가 빠져 있었다. 없는 `.mjs` 가 SPA 셸 HTML 200 을 받으면 브라우저는 그것을
     * 스크립트로 파싱하다 죽는데, 응답이 성공이라 `onerror` 가 발화하지 않아 태그 복구기도
     * 뜨지 않는다. `.json` 이 무사했던 것은 lookahead 에 끝 앵커가 없어 `.js` 에 부분일치한
     * **우연**이었을 뿐이다. 이제 목록은 화이트리스트에서 파생되므로 우연에 기대지 않는다.
     *
     * @effects static_miss_returns_404_for_every_servable_extension
     */
    public function test_모든_서빙_확장자가_catch_all_에서_제외된다(): void
    {
        $extensions = StaticExtensionPattern::servedExtensions();

        $this->assertNotEmpty($extensions, '모집단이 비었다 — 검사가 공허하게 통과한다');

        // 과거에 빠져 있던 세 가지가 모집단에 실제로 포함되는지 먼저 고정한다.
        foreach (['mjs', 'webp', 'otf'] as $regressed) {
            $this->assertContains($regressed, $extensions, "{$regressed} 가 모집단에서 빠졌다");
        }

        foreach ($extensions as $extension) {
            $path = "/build/ext/1787637589/templates/sirsoft-basic/assets/missing.{$extension}";

            try {
                app('router')->getRoutes()->match(Request::create($path, 'GET'));
                $this->fail(".{$extension} 미스가 SPA catch-all 에 매칭됐다 — HTML 200 이 나간다");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * admin catch-all 도 같은 제외 목록을 쓴다 — 두 목록이 갈라지지 않는다.
     *
     * @effects admin_catch_all_shares_static_exclusion
     */
    public function test_admin_catch_all_도_같은_제외_목록을_쓴다(): void
    {
        foreach (StaticExtensionPattern::servedExtensions() as $extension) {
            $path = "/admin/build/missing.{$extension}";

            try {
                app('router')->getRoutes()->match(Request::create($path, 'GET'));
                $this->fail("admin: .{$extension} 미스가 catch-all 에 매칭됐다");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
