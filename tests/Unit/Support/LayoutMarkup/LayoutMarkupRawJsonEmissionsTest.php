<?php

namespace Tests\Unit\Support\LayoutMarkup;

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
     * PO 지적(3회차)으로 폐기됐다 — 컴파일러가 받아 준다는 사실과 저작 파일에 JSON 이 통짜로
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
     * 확장 주입 `<props>` 의 병합 지시자 blob 도 센다 (PO 가 직접 지적한 형태).
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
}
