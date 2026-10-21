<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;

/**
 * 화면 이동 링크 마크업 계약 — `<a href>` + path 없는 navigate · 액션 키 `preventDefault`.
 *
 * 화면 이동을 링크로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되게 하는 형식(엔진 engine-v1.80.1)의
 * 서버 쪽 두 지점을 잠근다.
 *
 *  1. **컴파일러** — `:preventDefault` 는 `:stopPropagation` 과 같은 진입점 액션 top-level 키로 굽히고(params 아님),
 *     path 를 생략한 `<navigate/>` 는 `<a href>` 안에서 컴파일 에러 없이 굽힌다(엔진이 클릭한 링크의 href 를 쓴다).
 *  2. **봇 HTML** — path 없는 navigate 를 단 `<a href>` 는 원래 href 를 그대로 둔다(링크 하나, 덮어쓰기 없음).
 */
class NavigationLinkMarkupTest extends LayoutMarkupTestCase
{
    /**
     * `:preventDefault` 는 진입점 액션 top-level 키로, path 없는 navigate 는 params 에 path 없이 굽힌다.
     *
     * @scenario surface=markup_compile
     *
     * @effects prevent_default_key_is_top_level, navigate_without_path_compiles_inside_link
     *
     * @return void 반환값 없음
     */
    public function test_prevent_default_is_top_level_and_navigate_may_omit_path_inside_link(): void
    {
        $node = $this->compile('<a href="/admin/users/create" :mergeQuery="true"><on type="click"><navigate :preventDefault="false" :mergeQuery="true"/></on>등록</a>');
        $action = $node['actions'][0];

        $this->assertSame('A', $node['name']);
        $this->assertSame('/admin/users/create', $node['props']['href']);
        $this->assertSame('navigate', $action['handler']);
        $this->assertFalse($action['preventDefault']);
        $this->assertArrayNotHasKey('preventDefault', $action['params']);
        $this->assertTrue($action['params']['mergeQuery']);
        $this->assertArrayNotHasKey('path', $action['params']);

        $forced = $this->compile('<a href="/x"><on type="click"><navigate :preventDefault="true" path="/y"/></on>이동</a>')['actions'][0];
        $this->assertTrue($forced['preventDefault']);
        $this->assertSame('/y', $forced['params']['path']);
    }

    /**
     * path 를 생략한 navigate 를 단 `<a href>` 는 봇 HTML 에서 원래 href 를 그대로 둔다.
     *
     * @scenario surface=bot_html
     *
     * @effects bot_html_keeps_anchor_href
     *
     * @return void 반환값 없음
     */
    public function test_bot_html_keeps_anchor_href_for_navigate_without_path(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap(['A' => ['tag' => 'a']]);

        $html = $mapper->render([
            [
                'name' => 'A',
                'props' => ['href' => '/admin/users/create?page=2', 'className' => 'btn'],
                'text' => '등록',
                'actions' => [
                    ['type' => 'click', 'handler' => 'navigate', 'params' => ['mergeQuery' => true]],
                ],
            ],
        ], [], new ExpressionEvaluator);

        $this->assertStringContainsString('href="/admin/users/create?page=2"', $html);
        $this->assertSame(1, substr_count($html, 'href='));
        $this->assertStringContainsString('class="btn"', $html);
    }

    /**
     * 소스를 컴파일해 첫 루트 노드를 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> JSON 노드
     */
    private function compile(string $source): array
    {
        $compiler = new NodeCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source));

        return $compiler->compile($roots[0]);
    }
}
