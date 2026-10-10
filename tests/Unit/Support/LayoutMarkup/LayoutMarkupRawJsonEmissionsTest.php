<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;

/**
 * 원시 JSON 계량기(`rawJsonEmissions`) 자체의 회귀 봉인 (D41 계량의 계량).
 *
 * 이 함수는 "생성 `.g7.html` 에 통짜 JSON 이 남았는가" 를 재는 **게이트의 눈**이다. 눈이 멀면
 * 게이트는 통과시키면서 아무것도 못 본다 — 실제로 그런 일이 두 번 있었다:
 *
 *  1. D41 이전 — `<json>` 태그만 세어 속성값·구조 태그 본문의 blob 을 통째로 놓쳤다.
 *  2. D41 이후 — 엔티티 이스케이프된 blob(`:filter="{&quot;…}"`)을 `json_decode` 가 해석하지
 *     못해 놓쳤다. **같은 파일 안에서 인용 형태만 다른 두 blob 중 하나만 세지는** 비대칭이었다.
 *
 * 그런데 이 함수에는 직접 테스트가 없어 코퍼스 게이트를 통해서만 간접 실행됐고, 코퍼스가 통과하는
 * 한 눈이 멀어도 드러나지 않았다. 그래서 계량기를 직접 겨눈다 — 검출해야 할 것(회귀)과 검출하면
 * 안 되는 것(대조군)을 함께 고정해, 넓히다 오검출로 번지는 반대 방향 회귀도 막는다.
 */
class LayoutMarkupRawJsonEmissionsTest extends LayoutMarkupTestCase
{
    /**
     * 엔티티 이스케이프된 객체 속성도 원시 JSON 으로 센다.
     *
     * 직렬화기는 값 안에 `'` 가 있으면 작은따옴표로 감쌀 수 없어 큰따옴표 + `&quot;` 를 쓴다.
     * 디코드 없이 `json_decode` 하면 실패해 **blob 인데도 clean 판정**을 받는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_counts_entity_escaped_blobs
     */
    public function test_entity_escaped_object_attribute_is_counted(): void
    {
        $markup = '<set-state target="local" :filter="{&quot;searchType&quot;:&quot;{{query.id ? \'a\' : \'b\'}}&quot;}"/>';

        $this->assertSame([':filter'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 같은 소스에 두 인용 형태가 섞여도 **양쪽 모두** 센다 (실제로 있었던 비대칭).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_counts_entity_escaped_blobs
     */
    public function test_both_quote_forms_in_one_source_are_counted(): void
    {
        $markup = <<<'HTML'
        <set-state target="local" :filter='{"searchType":"email"}'/>
        <set-state target="local" :filter="{&quot;searchType&quot;:&quot;{{q ? 'a' : 'b'}}&quot;}"/>
        HTML;

        $this->assertSame([':filter', ':filter'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 스칼라 타입 리터럴은 세지 않는다 (대조군 — 정상 문법을 blob 으로 오검출하면 안 된다).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_ignores_scalar_type_literals
     */
    public function test_scalar_type_literals_are_not_counted(): void
    {
        $markup = '<data-grid :async="true" :page-size="20" :ratio="1.5" :empty="null"/>';

        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * **태그 본문 JSON 은 태그 이름을 가리지 않고 센다 (D42).**
     *
     * D41 은 이 태그들을 "`decodeJsonBody` 가 받는 설계된 정본" 으로 보아 제외했다. 그 제외는
     * 설계 검토 지적(3회차)으로 폐기됐다 — 컴파일러가 받아 준다는 사실과 소스 파일에 JSON 이 통짜로
     * 드러난다는 사실은 별개이고, 이 포맷이 없애려던 것은 후자다. 라벨은 태그명으로 남겨
     * 게이트 메시지만 보고 어디를 고칠지 알 수 있게 한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_counts_every_tag_body
     */
    public function test_tag_body_json_is_counted_regardless_of_tag_name(): void
    {
        $markup = <<<'HTML'
        <api-call target="/api/x">
          <params>{"filter":{"a":1}}</params>
          <fallback>{"items":[]}</fallback>
        </api-call>
        HTML;

        $this->assertSame(['<params>', '<fallback>'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 확장 주입 `<props>` 의 병합 지시자 blob 도 센다 (설계 검토가 직접 지적한 형태).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_counts_every_tag_body
     */
    public function test_extension_props_merge_blob_is_counted(): void
    {
        $markup = '<props>{"tabs":{"_append":[{"id":"ext_marketing","label":"$t:x","iconName":"puzzle-piece"}]}}</props>';

        $this->assertSame(['<props>'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * JSON 이 아닌 본문은 세지 않는다 (대조군 — 텍스트·표현식 노드가 오검출되면 안 된다).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_ignores_non_json_bodies
     */
    public function test_non_json_bodies_are_not_counted(): void
    {
        $markup = <<<'HTML'
        <span>{{user.name}}</span>
        <div>일반 텍스트</div>
        <prop name="label">$t:admin.title</prop>
        HTML;

        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * `<prop>` 본문의 `$switch` 는 인용 형태와 무관하게 `$switch` 라벨로 센다 (`<switch-value>` 가 정본).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_counts_entity_escaped_blobs
     */
    public function test_switch_in_prop_body_is_counted_in_both_forms(): void
    {
        $plain = '<prop name="title">{"$switch":"{{x}}","$cases":{"a":"A"},"$default":"D"}</prop>';
        $escaped = '<prop name="title">{&quot;$switch&quot;:&quot;{{x}}&quot;,&quot;$cases&quot;:{&quot;a&quot;:&quot;A&quot;}}</prop>';

        $this->assertSame(['$switch'], LayoutMarkupRoundTrip::rawJsonEmissions($plain));
        $this->assertSame(['$switch'], LayoutMarkupRoundTrip::rawJsonEmissions($escaped));
    }

    /**
     * `$switch` 가 속성값으로 나간 경우는 속성명이 아니라 `$switch` 라벨로 센다 (방출기 지목 유지).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_labels_switch_by_its_own_name
     */
    public function test_switch_attribute_is_labelled_as_switch(): void
    {
        $markup = '<div :label="{&quot;$switch&quot;:&quot;{{t}}&quot;,&quot;$cases&quot;:{&quot;a&quot;:&quot;A&quot;}}"/>';

        $this->assertSame(['$switch'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 속성값에 `>` 가 있어도 그 태그의 JSON 본문을 센다 (D42 4차 — 종전 정규식의 최대 사각).
     *
     * 직렬화기는 인용값 안의 `>` 를 이스케이프하지 않는다(`escapeAttr` 는 `&` 와 구분 따옴표만
     * 건드린다). 그래서 종전 본문 패턴 `<tag[^>]*>([^<]*)</tag>` 은 여는 태그를 **속성값 안의 `>`**
     * 에서 끊어 읽고, 본문 매칭이 통째로 어긋나 blob 이 계량에서 사라졌다. `{{a > b}}` 같은 비교식은
     * 레이아웃에 흔하므로(코퍼스 실측 1,337곳) 이 사각은 언제든 발현할 수 있는 상태였다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_survives_gt_in_attribute_values
     */
    public function test_json_body_is_counted_when_an_attribute_value_contains_a_greater_than(): void
    {
        $markup = <<<'HTML'
        <prop name="cols" data-if="{{count > 0}}">{"columns":[{"key":"id"}]}</prop>
        HTML;

        $this->assertSame(['<prop>'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 형제 요소와 섞인 JSON 텍스트도 센다 (종전 `[^<]*` 본문 패턴이 못 보던 자리).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_survives_gt_in_attribute_values
     */
    public function test_json_text_beside_sibling_elements_is_counted(): void
    {
        $markup = <<<'HTML'
        <prop name="mixed">{"a":1}<item>x</item></prop>
        HTML;

        $this->assertSame(['<prop>'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 대조군 — 어휘 해석이 안 되는 문자열은 계량 대상이 아니다(계량기가 예외를 던지지 않는다).
     *
     * 컴파일 가능 여부는 컴파일 게이트가 판정한다. 계량기가 여기서 터지면 게이트 메시지가
     * "원시 JSON 몇 건" 이 아니라 파서 오류로 바뀌어 지목 대상이 흐려진다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_survives_gt_in_attribute_values
     */
    public function test_unparseable_markup_is_not_counted(): void
    {
        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions('<div :a="{"'));
    }

    /**
     * 컴포넌트 노드의 텍스트는 세지 않는다 — 화면에 그려질 콘텐츠다 (D53, 설계 결정).
     *
     * 입금 처리 이력 화면은 결제사 요청·응답 페이로드를 관리자에게 **그대로 보여준다**. 그 글자가
     * JSON 모양이라는 이유로 계량하면, 어떤 문법으로도 없앨 수 없는 항목이 대장에 영구히 남아
     * 진짜 잔여와 구분되지 않는다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_ignores_component_display_text
     */
    public function test_component_node_display_text_is_not_counted(): void
    {
        $registry = $this->registry();
        $markup = '<pre class="whitespace-pre-wrap">{"transactionId":"PG2024012700004"}</pre>';

        $this->assertSame([], LayoutMarkupRoundTrip::rawJsonEmissions($markup, $registry));
    }

    /**
     * 대조군 — 구조 태그 본문은 레지스트리를 줘도 그대로 센다 (D41 재발 방지).
     *
     * D41 의 실수는 태그 **이름 목록**으로 뺀 것이었고, 그 목록 안에 DataGrid 컬럼 트리가 통째로
     * 숨었다. 여기서 쓰는 판별자는 레지스트리이므로 `<prop>`·`<params>` 같은 구조 태그는 컴포넌트가
     * 아니라 애초에 제외 분기에 들어오지 못한다. 그 성질을 고정한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_ignores_component_display_text
     */
    public function test_structural_tag_body_is_still_counted_with_registry(): void
    {
        $registry = $this->registry();
        $markup = '<prop name="cols">{"columns":[{"key":"id"}]}</prop>';

        $this->assertSame(['<prop>'], LayoutMarkupRoundTrip::rawJsonEmissions($markup, $registry));
    }

    /**
     * 대조군 — 레지스트리를 주지 않으면 종전대로 전부 센다 (판별할 수 없으면 빼지 않는다).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects raw_json_metric_ignores_component_display_text
     */
    public function test_display_text_is_counted_without_a_registry(): void
    {
        $markup = '<pre class="whitespace-pre-wrap">{"transactionId":"PG2024012700004"}</pre>';

        $this->assertSame(['<pre>'], LayoutMarkupRoundTrip::rawJsonEmissions($markup));
    }

    /**
     * 대조에 쓸 컴포넌트 레지스트리 (admin_basic — `pre` 등 기본 컴포넌트 보유).
     *
     * @return ComponentRegistry 레지스트리
     */
    private function registry(): ComponentRegistry
    {
        return ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
    }
}
