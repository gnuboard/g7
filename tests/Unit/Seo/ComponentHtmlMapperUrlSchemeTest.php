<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * SEO 렌더러의 URL 속성 실행 스킴 무해화 (KVE-2026-2348 — React 렌더러와 패리티)
 *
 * 클라이언트는 이동 sink·`<a href>` 에서 실행 스킴을 막는데, 봇 HTML 이 같은 값을 `<a href>` 로
 * 되살리면 그 자리가 우회로가 된다. href 는 `#`, src 는 스크립트 스킴만 제거, navigate 링크
 * 주입은 실행 스킴이면 생략한다.
 */
class ComponentHtmlMapperUrlSchemeTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 테스트용 최소 컴포넌트 매핑으로 매퍼와 평가기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap([
            'A' => ['tag' => 'a'],
            'Img' => ['tag' => 'img'],
            'Button' => ['tag' => 'button'],
        ]);
        $this->mapper->setRenderModes([]);
        $this->mapper->setSelfClosing(['img']);
        $this->mapper->setTextProps(['text']);
        $this->mapper->setAttrMap([]);
        $this->mapper->setAllowedAttrs(['href', 'src', 'alt']);
    }

    /**
     * query 에서 온 실행 스킴 href 가 # 로 무해화되는지 검증합니다.
     *
     * @scenario target_kind=script_scheme, sink=new_window_or_link
     *
     * @effects dangerous_href_neutralized
     *
     * @return void 반환값 없음
     */
    public function test_executable_href_is_neutralized_to_hash(): void
    {
        foreach (['javascript:alert(1)', ' JaVa'."\t".'ScRiPt:alert(1)', 'data:text/html,<b>x</b>'] as $payload) {
            $html = $this->mapper->render(
                [['name' => 'A', 'text' => 'go', 'props' => ['href' => '{{query.redirect_url}}']]],
                ['query' => ['redirect_url' => $payload]],
                $this->evaluator,
            );

            $this->assertStringContainsString('href="#"', $html);
            $this->assertStringNotContainsStringIgnoringCase('script:', $html);
        }
    }

    /**
     * 정상 외부 링크·mailto·data: 이미지가 그대로 렌더되는지(과차단 방지) 검증합니다.
     *
     * @scenario target_kind=same_origin, sink=new_window_or_link
     *
     * @effects external_link_preserved
     *
     * @return void 반환값 없음
     */
    public function test_regular_links_and_data_images_are_preserved(): void
    {
        $html = $this->mapper->render(
            [
                ['name' => 'A', 'text' => 'ext', 'props' => ['href' => 'https://sir.kr/']],
                ['name' => 'A', 'text' => 'mail', 'props' => ['href' => 'mailto:help@example.com']],
                ['name' => 'Img', 'props' => ['src' => 'data:image/png;base64,iVBORw0KGgo=', 'alt' => 'x']],
            ],
            [],
            $this->evaluator,
        );

        $this->assertStringContainsString('href="https://sir.kr/"', $html);
        $this->assertStringContainsString('href="mailto:help@example.com"', $html);
        $this->assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $html);
    }

    /**
     * 스크립트 스킴 src 가 속성째 제거되는지 검증합니다.
     *
     * @effects dangerous_href_neutralized
     *
     * @return void 반환값 없음
     */
    public function test_script_scheme_src_is_dropped(): void
    {
        $html = $this->mapper->render(
            [['name' => 'Img', 'props' => ['src' => 'javascript:alert(1)', 'alt' => 'x']]],
            [],
            $this->evaluator,
        );

        $this->assertStringNotContainsString('src=', $html);
    }

    /**
     * 실행 스킴 navigate 경로로는 링크를 주입하지 않는지 검증합니다.
     *
     * @scenario target_kind=script_scheme, sink=same_tab
     *
     * @effects nav_sink_rejects_script_scheme
     *
     * @return void 반환값 없음
     */
    public function test_navigate_link_is_not_injected_for_executable_path(): void
    {
        $html = $this->mapper->render(
            [[
                'name' => 'Button',
                'text' => '로그인',
                'actions' => [['handler' => 'navigate', 'params' => ['path' => '{{query.redirect}}']]],
            ]],
            ['query' => ['redirect' => 'javascript:alert(1)']],
            $this->evaluator,
        );

        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * same-origin navigate 경로는 종전대로 링크가 주입되는지 검증합니다.
     *
     * @scenario target_kind=same_origin, sink=same_tab
     *
     * @effects same_origin_redirect_preserved
     *
     * @return void 반환값 없음
     */
    public function test_navigate_link_is_injected_for_same_origin_path(): void
    {
        $html = $this->mapper->render(
            [[
                'name' => 'Button',
                'text' => '로그인',
                'actions' => [['handler' => 'navigate', 'params' => ['path' => '/login']]],
            ]],
            [],
            $this->evaluator,
        );

        $this->assertStringContainsString('<a href="/login"', $html);
    }
}
