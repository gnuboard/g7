<?php

namespace Tests\Unit\Support;

use App\Support\CssLayerFlattener;
use Tests\TestCase;

/**
 * `CssLayerFlattener` — `@layer` 래퍼 평탄화.
 *
 * 확장 CSS 서빙은 의미 클래스 층(`g7-extension`)과 Tailwind 폴백 층(`properties`)만 남기고 나머지
 * 층 래퍼·순서 선언을 걷어낸다. 남기지 않은 층의 규칙이 층 없이 템플릿 뒤에 실려야 확장 유틸리티가
 * 템플릿 CSS 라이브러리와 무관하게 이긴다. 층을 잘못 남기거나 잘못 벗기면 화면 전체 우선순위가 바뀐다.
 * 주석·문자열·이스케이프 안의 `@layer` 를 층으로 읽던 결함의 회귀 그물이다 — 트러블슈팅 `[case:backend-60]`.
 */
class CssLayerFlattenerTest extends TestCase
{
    /**
     * 남길 층이 없으면 모든 래퍼를 벗기고 순서 선언은 그대로 둔다(편집기 프리뷰 경로와 같은 동작).
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c01
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_flatten_without_keep_unwraps_all_and_preserves_statements(): void
    {
        $css = '@layer base, utilities;@layer base{a{color:red}}@layer utilities{.x{top:0}}';

        $out = CssLayerFlattener::flatten($css);

        $this->assertSame('@layer base, utilities;a{color:red}.x{top:0}', $out);
    }

    /**
     * 남길 층은 래퍼째 남고, 그 밖의 층은 벗겨진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c02
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_keep_list_preserves_named_layer_wrapper(): void
    {
        $css = '@layer theme{:root{--p-color:red}}@layer g7-extension{.p-card{padding:1rem}}@layer utilities{.p\\:flex{display:flex}}';

        $out = CssLayerFlattener::flatten($css, ['g7-extension']);

        $this->assertSame(':root{--p-color:red}@layer g7-extension{.p-card{padding:1rem}}.p\\:flex{display:flex}', $out);
    }

    /**
     * 중첩 층 — 바깥을 벗기면 안쪽도 다시 판정한다. 남기는 층 안의 다른 층은 벗긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c03
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_nested_layers_are_processed_recursively(): void
    {
        $css = '@layer outer{.a{top:0}@layer g7-extension{.b{left:0}@layer inner{.c{right:0}}}}';

        $out = CssLayerFlattener::flatten($css, ['g7-extension']);

        $this->assertSame('.a{top:0}@layer g7-extension{.b{left:0}.c{right:0}}', $out);
    }

    /**
     * 확장 서빙 — 순서 선언에서 남기는 층 이름만 남기고, 하나도 없으면 문장을 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c04
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_for_extension_filters_statements_and_keeps_properties_layer(): void
    {
        $css = '@layer properties;@layer theme, base, components, utilities;'
            .'@layer properties{@supports (x:y){*,::before{--tw-shadow:0 0 #0000}}}'
            .'@layer utilities{.p\\:shadow{--tw-shadow:0 1px red}}'
            .'@layer g7-extension{.p-title{font-weight:600}}';

        $out = CssLayerFlattener::forExtension($css);

        $this->assertSame(
            '@layer properties;'
            .'@layer properties{@supports (x:y){*,::before{--tw-shadow:0 0 #0000}}}'
            .'.p\\:shadow{--tw-shadow:0 1px red}'
            .'@layer g7-extension{.p-title{font-weight:600}}',
            $out
        );
    }

    /**
     * 여러 이름을 함께 묶은 블록(`@layer a, b { }` 는 문법상 없지만 방어) 과 익명 블록은 벗긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c05
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_anonymous_block_is_unwrapped(): void
    {
        $this->assertSame('.a{top:0}', CssLayerFlattener::flatten('@layer{.a{top:0}}', ['g7-extension']));
    }

    /**
     * `@supports`·`@media` 는 그대로 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c06
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_supports_and_media_are_preserved(): void
    {
        $css = '@layer utilities{@media (width>=48rem){.p\\:md\\:flex{display:flex}}@supports (display:grid){.g{display:grid}}}';

        $out = CssLayerFlattener::forExtension($css);

        $this->assertSame('@media (width>=48rem){.p\\:md\\:flex{display:flex}}@supports (display:grid){.g{display:grid}}', $out);
    }

    /**
     * 중괄호 짝이 맞지 않으면 그 지점부터 원문을 그대로 낸다(서빙이 깨지지 않게).
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c07
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_unbalanced_brace_falls_back_to_original_tail(): void
    {
        $css = '.z{top:0}@layer base{.a{top:0}';

        $this->assertSame($css, CssLayerFlattener::forExtension($css));
    }

    /**
     * 주석 안의 `@layer` 글자는 층 블록이 아니다 — 주석과 그 뒤 규칙을 그대로 둔다.
     * 실측(issue816 S2 T8-b): 덮기 파일 머리 주석의 「기본값 칸(@layer g7-extension)과」 를 층으로 읽어 다음 `{` 까지
     * 벗겨 내 주석 닫힘이 사라졌고, 서빙 본문 1,337 B → 762 B 로 규칙이 0개가 됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c08
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_layer_text_inside_comment_is_not_a_layer(): void
    {
        $css = "/* 확장의 기본값 칸(@layer g7-extension)과 일반 칸을 덮는다. */\n.hellomodule-page__title {\n    color: #b45309;\n}\n";

        $this->assertSame($css, CssLayerFlattener::forExtension($css));
        $this->assertSame($css, CssLayerFlattener::flatten($css));
    }

    /**
     * 주석·문자열 안의 중괄호는 짝 맞춤에 세지 않는다 — 층 블록 안에 `content:"}"` 나 `/* } *\/` 가 있어도
     * 블록 끝을 제자리에서 찾는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c09
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_braces_inside_comments_and_strings_do_not_close_layer(): void
    {
        $css = '@layer utilities{.a::after{content:"}"}/* } */.b{top:0}}.c{left:0}';

        $this->assertSame('.a::after{content:"}"}/* } */.b{top:0}.c{left:0}', CssLayerFlattener::forExtension($css));
    }

    /**
     * 선택자 이스케이프의 따옴표(`\'`)는 문자열 시작이 아니다 — 그 뒤 층 블록도 벗겨진다.
     * 실측: sirsoft-basic 빌드의 `.after\:content-\[\'\'\]` 를 문자열로 읽어 뒤의 `@layer utilities{` 가 남았다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c10
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_escaped_quote_in_selector_is_not_a_string(): void
    {
        $css = ".after\\:content-\\[\\'\\'\\]:after{content:''}@layer utilities{.x{top:0}}";

        $this->assertSame(".after\\:content-\\[\\'\\'\\]:after{content:''}.x{top:0}", CssLayerFlattener::flatten($css));
    }

    /**
     * 주석 안의 순서 선언 모양(`@layer a;`)도 지우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c11
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_statement_text_inside_comment_is_kept(): void
    {
        $css = '/* 예: @layer theme, base; */@layer theme, base;.x{top:0}';

        $this->assertSame('/* 예: @layer theme, base; */.x{top:0}', CssLayerFlattener::forExtension($css));
    }

    /**
     * `@layer` 가 없으면 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layer_flatten_c12
     *
     * @effects extension_css_layers_flattened_keeping_named_layers
     */
    public function test_noop_without_layers(): void
    {
        $css = '.btn{color:blue}[data-x=dark] .btn{color:white}';

        $this->assertSame($css, CssLayerFlattener::forExtension($css));
    }
}
