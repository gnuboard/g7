<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;

/**
 * navigate 대체 경로 마크업 계약 — `<navigate path="…" fallbackPath="/"/>` 는 `params.fallbackPath` 로 굽고
 * JSON → 마크업 → JSON 이 같은 값으로 돌아온다. 기존 `fallback`(미등록 라우트 핸들러)과 섞이지 않는다.
 */
class NavigateFallbackPathMarkupTest extends LayoutMarkupTestCase
{
    /**
     * `fallbackPath` 속성이 `params.fallbackPath` 로 굽히고 `fallback` 과 따로 남는지 검증합니다.
     *
     * @scenario path_kind=script_scheme, fallback_path=valid
     *
     * @effects markup_fallback_path_compiles_to_params
     *
     * @return void 반환값 없음
     */
    public function test_fallback_path_attribute_compiles_to_params(): void
    {
        $node = $this->compile('<button><on type="click"><navigate path="{{query.redirect}}" fallbackPath="/" :fallback="false"/></on>로그인</button>');
        $action = $node['actions'][0];

        $this->assertSame('navigate', $action['handler']);
        $this->assertSame('{{query.redirect}}', $action['params']['path']);
        $this->assertSame('/', $action['params']['fallbackPath']);
        $this->assertFalse($action['params']['fallback']);
        $this->assertArrayNotHasKey('fallbackPath', $action);
    }

    /**
     * `params.fallbackPath` 를 가진 레이아웃이 마크업을 거쳐 같은 JSON 으로 돌아오는지 검증합니다.
     *
     * @scenario path_kind=empty, fallback_path=valid
     *
     * @effects markup_fallback_path_compiles_to_params
     *
     * @return void 반환값 없음
     */
    public function test_fallback_path_round_trips(): void
    {
        $layout = [
            'version' => '1.0.0',
            'layout_name' => 'fallback_path_round_trip',
            'components' => [[
                'id' => 'login_button',
                'type' => 'basic',
                'name' => 'Button',
                'text' => '로그인',
                'actions' => [[
                    'type' => 'click',
                    'handler' => 'navigate',
                    'params' => ['path' => "{{query.redirect ?? ''}}", 'fallbackPath' => '/'],
                ]],
            ]],
        ];

        $result = (new LayoutMarkupRoundTrip)->verify($layout, $this->registry());

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertStringContainsString('fallbackPath="/"', (string) $result['markup']);
        $this->assertSame('/', $result['recompiled']['components'][0]['actions'][0]['params']['fallbackPath']);
    }

    /**
     * 관리자 템플릿 컴포넌트 레지스트리를 읽습니다.
     *
     * @return ComponentRegistry 레지스트리
     */
    private function registry(): ComponentRegistry
    {
        return ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
    }

    /**
     * 소스를 컴파일해 첫 루트 노드를 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> JSON 노드
     */
    private function compile(string $source): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source));

        return (new NodeCompiler($this->registry()))->compile($roots[0]);
    }
}
