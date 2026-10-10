<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Unit\Layouts;

use Modules\Gnuboard7\HelloModule\Tests\ModuleTestCase;

/**
 * 마크업 형식 예시 화면(admin_markup_demo)의 글자 표시 테스트.
 *
 * 7.1.0 전수 실측 G3: 예시가 `<h2 text="Hello Markup Demo"/>` 처럼 글자를 `text` 속성으로 적어
 * 컴파일 결과가 `props.text` 가 됐다. 기본 컴포넌트(H2, Span)는 `props.text` 를 그리지 않아
 * 미리보기 화면의 제목이 빈 칸(`<h2 text="Hello Markup Demo"></h2>`)으로 나왔다. 기본 태그의 글자는
 * 본문 텍스트로 적어 노드 `text` 로 컴파일되게 한다.
 *
 * @scenario-manifest tests/scenarios/markup-demo-text.yaml
 */
class MarkupDemoTextTest extends ModuleTestCase
{
    /**
     * 예시 레이아웃의 짝 JSON 을 읽습니다.
     *
     * @return array<string, mixed> 레이아웃 JSON
     */
    private function demoLayout(): array
    {
        $path = dirname(__DIR__, 3).'/resources/layouts/admin/admin_markup_demo.json';

        return json_decode((string) file_get_contents($path), true);
    }

    /**
     * 노드 트리를 펼쳐 기본 컴포넌트 노드만 돌려줍니다.
     *
     * @param  array<int, array<string, mixed>>  $nodes  노드 목록
     * @return array<int, array<string, mixed>> 기본 컴포넌트 노드
     */
    private function basicNodes(array $nodes): array
    {
        $found = [];
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'basic') {
                $found[] = $node;
            }
            $found = array_merge($found, $this->basicNodes($node['children'] ?? []));
        }

        return $found;
    }

    /**
     * 기본 컴포넌트 노드는 글자를 props.text 가 아니라 노드 text 로 가진다.
     *
     * @scenario node=heading
     *
     * @effects basic_text_rendered
     *
     * @return void 반환값 없음
     */
    public function test_heading_text_is_node_text(): void
    {
        $nodes = $this->basicNodes($this->demoLayout()['components']);
        $heading = collect($nodes)->firstWhere('name', 'H2');

        $this->assertNotNull($heading);
        $this->assertArrayNotHasKey('text', $heading['props'] ?? []);
        $this->assertSame('Hello Markup Demo', $heading['text'] ?? null);
    }

    /**
     * 바인딩 글자를 담는 Span 도 노드 text 로 가진다.
     *
     * @scenario node=binding_span
     *
     * @effects basic_text_rendered
     *
     * @return void 반환값 없음
     */
    public function test_binding_span_text_is_node_text(): void
    {
        $nodes = $this->basicNodes($this->demoLayout()['components']);
        $span = collect($nodes)->firstWhere('name', 'Span');

        $this->assertNotNull($span);
        $this->assertArrayNotHasKey('text', $span['props'] ?? []);
        $this->assertSame("{{message ?? ''}}", $span['text'] ?? null);
    }
}
