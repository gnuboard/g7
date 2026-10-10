<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Unit\Layouts;

use Modules\Gnuboard7\HelloModule\Tests\ModuleTestCase;

/**
 * 사용자 메모 목록 화면의 마크업 테스트.
 *
 * 7.1.0 전수 실측 M3(sirsoft-basic `/memos`, 1440px): 제목이 관리자 템플릿 전용 의미 클래스
 * (`text-page-heading`, `section-heading`)를 써서 사용자 템플릿에서는 정의가 없어 쪽 제목 16px,
 * 메모 제목 굵기 400 으로 나왔다(#408 치환이 사용자 화면까지 바꿨다. 0.1.0 은 `text-2xl font-bold`,
 * `text-lg font-semibold`). 또 반복(`data-each`)이 `space-y-3` 묶음 쪽에 붙어 메모마다 묶음이 새로 생겨
 * 카드 사이 간격이 0px 이었다. Hello 사용자 템플릿을 켜면 메모 본문 `P` 가 「컴포넌트를 찾을 수 없습니다: P」
 * 콘솔 오류와 함께 빠졌다(그 템플릿의 기본 컴포넌트는 Div·Button·H1·H2·H3·A·Span·Img 8종). 사용자 화면은
 * 어느 사용자 템플릿에서나 있는 유틸리티 클래스와 기본 컴포넌트로 쓰고, 반복은 카드에 둔다.
 *
 * @scenario-manifest tests/scenarios/user-memo-list-markup.yaml
 */
class UserMemoListMarkupTest extends ModuleTestCase
{
    /**
     * 사용자 메모 목록 레이아웃 JSON 을 읽습니다.
     *
     * @return array<string, mixed> 레이아웃
     */
    private function layout(): array
    {
        $path = dirname(__DIR__, 3).'/resources/layouts/user/user_memo_list.json';

        return json_decode((string) file_get_contents($path), true);
    }

    /**
     * 노드를 깊이 우선으로 모읍니다(부모 className 포함).
     *
     * @param  mixed  $node  레이아웃 노드
     * @param  string|null  $parentClass  부모 className
     * @param  array<int, array{node: array, parent: string|null}>  $out  누적
     * @return array<int, array{node: array, parent: string|null}> 노드 목록
     */
    private function collect(mixed $node, ?string $parentClass = null, array $out = []): array
    {
        if (! is_array($node)) {
            return $out;
        }
        if (isset($node['name']) && isset($node['type'])) {
            $out[] = ['node' => $node, 'parent' => $parentClass];
            $parentClass = $node['props']['className'] ?? null;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $out = $this->collect($value, $parentClass, $out);
            }
        }

        return $out;
    }

    /**
     * 관리자 템플릿 전용 의미 클래스를 쓰지 않는다.
     *
     * @scenario part=headings
     *
     * @effects user_layout_uses_template_neutral_heading_classes
     *
     * @return void 반환값 없음
     */
    public function test_headings_do_not_use_admin_only_semantic_classes(): void
    {
        $classes = array_filter(array_map(
            fn (array $entry) => $entry['node']['props']['className'] ?? null,
            $this->collect($this->layout())
        ));

        foreach ($classes as $class) {
            $tokens = preg_split('/\s+/', $class);
            $this->assertNotContains('text-page-heading', $tokens, "사용자 화면에 관리자 전용 클래스: {$class}");
            $this->assertNotContains('section-heading', $tokens, "사용자 화면에 관리자 전용 클래스: {$class}");
        }

        $headings = array_values(array_filter(
            $this->collect($this->layout()),
            fn (array $entry) => in_array($entry['node']['name'], ['H1', 'H2'], true)
        ));
        $this->assertStringContainsString('font-bold', $headings[0]['node']['props']['className']);
        $this->assertStringContainsString('font-semibold', $headings[1]['node']['props']['className']);
    }

    /**
     * 반복은 카드에 붙고 카드 묶음(space-y-3)은 한 번만 그려진다.
     *
     * @scenario part=iteration
     *
     * @effects memo_cards_repeat_inside_single_spaced_list
     *
     * @return void 반환값 없음
     */
    public function test_iteration_repeats_cards_inside_single_spaced_list(): void
    {
        $iterated = array_values(array_filter(
            $this->collect($this->layout()),
            fn (array $entry) => isset($entry['node']['iteration'])
        ));

        $this->assertCount(1, $iterated);
        $this->assertStringContainsString('p-4', $iterated[0]['node']['props']['className']);
        $this->assertStringContainsString('space-y-3', (string) $iterated[0]['parent']);
    }

    /**
     * 가장 작은 사용자 템플릿(Hello 사용자 템플릿 8종)에도 있는 기본 컴포넌트만 쓴다.
     *
     * @scenario part=components
     *
     * @effects user_layout_uses_components_every_user_template_provides
     *
     * @return void 반환값 없음
     */
    public function test_uses_only_components_every_user_template_provides(): void
    {
        $minimal = ['Div', 'Button', 'H1', 'H2', 'H3', 'A', 'Span', 'Img'];
        $names = array_unique(array_map(
            fn (array $entry) => $entry['node']['name'],
            array_filter($this->collect($this->layout()), fn (array $entry) => ($entry['node']['type'] ?? null) === 'basic')
        ));

        $this->assertSame([], array_values(array_diff($names, $minimal)));
    }
}
