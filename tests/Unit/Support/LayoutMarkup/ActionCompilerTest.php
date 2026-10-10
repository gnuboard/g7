<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\ActionCompiler;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ActionCompiler 단위 테스트 (`.g7.html` 마크업 형식 S3 — 매핑 사전 E).
 *
 * 검증 축은 넷이다:
 *
 *  1. **fixture 쌍** — `<on>` 을 품은 컴포넌트가 컴파일된 결과가 짝 `.json` 과 **키 순서까지** 같은가.
 *     액션은 노드에 붙으므로 NodeCompiler 파이프라인 전체를 태워 실측 shape 를 검증한다.
 *  2. **`<on>` 세 갈래** — type/event 는 actions[], mount/unmount 는 lifecycle, listen 은
 *     onComponentEvent 로 갈리는가. 병렬 3형태(fire-and-forget/암묵 sequence/parallel)가 구분되는가.
 *  3. **키 배치** — 핸들러별 top-level(apiCall.target) vs params(setState.target) 가 매니페스트대로인가.
 *  4. **진입점 규칙** — confirm 은 루트에서만, init 루트는 축소 화이트리스트인가.
 *
 * 레지스트리는 번들 `sirsoft-admin_basic/components.json` 실물을 쓴다 — 노드 태그 해석이 실제
 * 템플릿과 어긋나면 액션이 붙을 노드 자체가 틀리기 때문이다.
 */
class ActionCompilerTest extends LayoutMarkupTestCase
{
    private NodeCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new NodeCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
    }

    // ---------------------------------------------------------------------
    // fixture 쌍 (HTML ↔ JSON)
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects on_type_or_event_becomes_an_actions_entry,
     *          handler_attributes_split_into_top_level_and_params,
     *          switch_case_conditions_parallel_sequence_compile_to_flow_handlers,
     *          action_json_key_order_is_deterministic
     */
    #[DataProvider('fixtureProvider')]
    public function test_fixture_pair_compiles_to_expected_json(string $fixture): void
    {
        $directory = __DIR__.'/fixtures/actions';

        $node = $this->compile(file_get_contents("{$directory}/{$fixture}.g7.html"));
        $expected = json_decode(file_get_contents("{$directory}/{$fixture}.json"), true);

        $this->assertSame($this->encode($expected), $this->encode($node));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            '이벤트 액션·switch·confirm' => ['event_actions'],
            '생명주기·구독·conditions' => ['lifecycle_subscription'],
            '병렬 3형태·에러맵·범용 핸들러' => ['flow_and_generic'],
        ];
    }

    // ---------------------------------------------------------------------
    // <on> 세 갈래
    // ---------------------------------------------------------------------

    /**
     * @effects on_type_or_event_becomes_an_actions_entry
     */
    public function test_type_is_a_dom_event_and_event_is_a_component_callback(): void
    {
        // 엔진은 action.event 를 콜백 prop 이름으로, action.type 을 DOM 이벤트로 읽는다
        // (bindActionsToProps: event || getEventHandlerName(type)). 둘 다 실릴 수 있다.
        $both = $this->compile('<div data-ref="d"><on type="change" event="onPageChange"><navigate path="/x"/></on></div>')['actions'][0];

        $this->assertSame('change', $both['type']);
        $this->assertSame('onPageChange', $both['event']);
        $this->assertSame('navigate', $both['handler']);
    }

    /**
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects mount_and_unmount_become_lifecycle
     */
    public function test_mount_and_unmount_route_to_lifecycle_as_arrays(): void
    {
        // lifecycle.onMount 는 배열이므로 여러 자식을 sequence 로 감싸지 않고 항목으로 편다.
        $node = $this->compile('<div data-ref="d"><on event="mount"><toast message="a"/><toast message="b"/></on><on event="unmount"><stop-interval/></on></div>');

        $this->assertCount(2, $node['lifecycle']['onMount']);
        $this->assertSame('toast', $node['lifecycle']['onMount'][0]['handler']);
        $this->assertSame('toast', $node['lifecycle']['onMount'][1]['handler']);
        $this->assertSame('stopInterval', $node['lifecycle']['onUnmount'][0]['handler']);
        $this->assertArrayNotHasKey('actions', $node);
    }

    /**
     * @effects listen_becomes_on_component_event
     */
    public function test_listen_routes_to_on_component_event_with_event_name(): void
    {
        // 다른 곳에서 emit 된 이벤트 구독은 actions 가 아니라 onComponentEvent 다(구독/해제 대상).
        $node = $this->compile('<div data-ref="d"><on listen="upload:site_logo"><refetch-data-source dataSourceId="s"/></on></div>');

        $this->assertSame('upload:site_logo', $node['onComponentEvent'][0]['event']);
        $this->assertSame('refetchDataSource', $node['onComponentEvent'][0]['handler']);
        $this->assertArrayNotHasKey('actions', $node);
    }

    /**
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects parallel_forms_are_distinguished_for_roundtrip
     */
    public function test_the_three_parallel_forms_are_distinct(): void
    {
        // ① 같은 이벤트 여러 <on> 블록 = fire-and-forget(별도 항목)
        $fireForget = $this->compile('<div data-ref="d"><on type="click"><navigate path="/a"/></on><on type="click"><navigate path="/b"/></on></div>')['actions'];
        $this->assertCount(2, $fireForget);
        $this->assertSame('navigate', $fireForget[0]['handler']);

        // ② 한 <on> 여러 자식 = 암묵 sequence
        $implicit = $this->compile('<div data-ref="d"><on type="click"><navigate path="/a"/><navigate path="/b"/></on></div>')['actions'][0];
        $this->assertSame('sequence', $implicit['handler']);
        $this->assertCount(2, $implicit['actions']);

        // ③ <parallel> = 명시 병렬
        $explicit = $this->compile('<div data-ref="d"><on type="click"><parallel><navigate path="/a"/><navigate path="/b"/></parallel></on></div>')['actions'][0];
        $this->assertSame('parallel', $explicit['handler']);
        $this->assertCount(2, $explicit['actions']);
    }

    // ---------------------------------------------------------------------
    // 키 배치 (매니페스트)
    // ---------------------------------------------------------------------

    /**
     * @effects handler_attributes_split_into_top_level_and_params
     */
    public function test_target_placement_is_handler_specific(): void
    {
        // apiCall.target = 엔드포인트(top-level), setState.target = 상태 스코프(params).
        // 넣는 위치가 틀리면 엔진이 조용히 무시한다.
        $apiCall = $this->compile('<div data-ref="d"><on type="click"><api-call target="/api/x" method="GET"/></on></div>')['actions'][0];
        $this->assertSame('/api/x', $apiCall['target']);
        $this->assertArrayNotHasKey('target', $apiCall['params']);

        $setState = $this->compile('<div data-ref="d"><on type="click"><set-state target="_local" x="1"/></on></div>')['actions'][0];
        $this->assertArrayNotHasKey('target', $setState);
        $this->assertSame('_local', $setState['params']['target']);
    }

    /**
     * `key` 는 핸들러 인자가 아니라 트리거 한정자 — `<on>` 에 실리고, 핸들러의 `key` 는 params 로 간다.
     *
     * 둘을 한 자리에 두면 `params.key` 를 쓰는 핸들러(saveToLocalStorage)의 인자가 트리거 키로
     * 오해석돼 조용히 소실된다 (U-ESCAPE 실측 32건).
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects trigger_key_qualifier_is_distinct_from_handler_params
     */
    public function test_trigger_key_is_on_level_and_handler_key_stays_in_params(): void
    {
        $trigger = $this->compile('<div data-ref="d"><on type="keypress" key="Enter"><action-ref name="searchX"/></on></div>')['actions'][0];
        $this->assertSame('keypress', $trigger['type']);
        $this->assertSame('Enter', $trigger['key']);

        $handler = $this->compile('<div data-ref="d"><on type="click"><save-to-local-storage key="g7_filter" value="v"/></on></div>')['actions'][0];
        $this->assertArrayNotHasKey('key', $handler);
        $this->assertSame('g7_filter', $handler['params']['key']);
        $this->assertSame('v', $handler['params']['value']);
    }

    /**
     * `<action-ref>` — 핸들러 없이 named_actions 를 참조하는 유일한 액션 형태.
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects action_ref_compiles_without_handler
     */
    public function test_action_ref_compiles_to_action_ref_key(): void
    {
        $action = $this->compile('<div data-ref="d"><on type="click"><action-ref name="searchOrders"/></on></div>')['actions'][0];

        $this->assertSame('searchOrders', $action['actionRef']);
        $this->assertArrayNotHasKey('handler', $action);
    }

    /**
     * `<action-ref>` 는 name 이 필수이고 부속 채널을 가질 수 없다.
     *
     * @scenario compile_unit=action,source_health=invalid
     *
     * @effects action_ref_requires_name
     */
    public function test_action_ref_rejects_missing_name_and_children(): void
    {
        $this->expectException(CompileError::class);
        $this->compile('<div data-ref="d"><on type="click"><action-ref/></on></div>');
    }

    /**
     * `conditions` 분기 주석 — `comment` 는 전 위치 횡단 키라 분기에서도 왕복해야 한다.
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects condition_branch_comment_round_trips
     */
    public function test_condition_branch_comment_is_preserved(): void
    {
        $action = $this->compile(
            '<div data-ref="d"><on type="click"><conditions>'
            .'<!-- 수정 모드 --><when if="{{a}}"><toast message="x"/></when>'
            .'<!-- 등록 모드 --><else><toast message="y"/></else>'
            .'</conditions></on></div>'
        )['actions'][0];

        $this->assertSame('수정 모드', $action['conditions'][0]['comment']);
        $this->assertSame('등록 모드', $action['conditions'][1]['comment']);
    }

    /**
     * @effects handler_attributes_split_into_top_level_and_params
     */
    public function test_universal_keys_are_top_level_and_typed_literals_decode(): void
    {
        $action = $this->compile('<div data-ref="d"><on type="click"><api-call target="/x" :auth_required="true" resultTo="userList" :debounce="300" field="v"/></on></div>')['actions'][0];

        $this->assertTrue($action['auth_required']);
        $this->assertSame('userList', $action['resultTo']);
        $this->assertSame(300, $action['debounce']);
        // 매니페스트 밖 키는 params 로.
        $this->assertSame('v', $action['params']['field']);
    }

    /**
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects action_if_follows_the_single_binding_rule
     */
    public function test_action_if_is_wrapped_and_mixed_form_is_rejected(): void
    {
        // 액션 top-level if 도 노드 data-if 와 같은 규칙 — 감싸지 않은 식은 감싸고 혼합형은 거부.
        $wrapped = $this->compile('<div data-ref="d"><on type="click"><navigate if="_local.canGo" path="/x"/></on></div>')['actions'][0];
        $this->assertSame('{{_local.canGo}}', $wrapped['if']);

        $this->assertSame(
            LayoutMarkupErrorCode::MixedIfExpression,
            $this->compileError('<div data-ref="d"><on type="click"><navigate if="{{a}} === 1" path="/x"/></on></div>')->getErrorCode(),
        );
    }

    /**
     * @effects generic_action_carries_extension_or_dynamic_handler
     */
    public function test_generic_action_tag_carries_verbatim_handler_name(): void
    {
        // 확장 핸들러는 점을 품어 태그명이 될 수 없다 — 범용 <action handler> 로 이름을 그대로 싣는다.
        $ext = $this->compile('<div data-ref="d"><on type="change"><action handler="sirsoft-ecommerce.toggleArrayValue" field="opts"/></on></div>')['actions'][0];
        $this->assertSame('sirsoft-ecommerce.toggleArrayValue', $ext['handler']);
        $this->assertSame('opts', $ext['params']['field']);

        // 동적 핸들러명은 원문 통과.
        $dyn = $this->compile('<div data-ref="d"><on type="click"><action handler="{{_local.pgHandler}}"/></on></div>')['actions'][0];
        $this->assertSame('{{_local.pgHandler}}', $dyn['handler']);
    }

    // ---------------------------------------------------------------------
    // confirm / init 진입점 규칙
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects confirm_is_allowed_only_at_the_entry_action
     */
    public function test_confirm_is_allowed_at_the_root_action_only(): void
    {
        // 이벤트에 직접 연결된 루트 액션은 confirm 허용.
        $root = $this->compile('<div data-ref="d"><on type="click"><api-call target="/x" confirm="정말?"/></on></div>')['actions'][0];
        $this->assertSame('정말?', $root['confirm']);
    }

    /**
     * @scenario compile_unit=action,source_health=invalid
     *
     * @effects confirm_is_allowed_only_at_the_entry_action
     */
    #[DataProvider('nestedConfirmProvider')]
    public function test_confirm_on_a_nested_action_is_rejected(string $source): void
    {
        // 엔진은 중첩 실행에서 confirm 을 읽지 않는다 — 확인창이 뜰 것으로 기대하지만 아무 일도 없다.
        $this->assertSame(
            LayoutMarkupErrorCode::ConfirmNotAtRoot,
            $this->compileError($source)->getErrorCode(),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nestedConfirmProvider(): array
    {
        return [
            '암묵 sequence 안' => ['<div data-ref="d"><on type="click"><api-call target="/a"/><api-call target="/b" confirm="?"/></on></div>'],
            '<success> 안' => ['<div data-ref="d"><on type="click"><api-call target="/a"><success><api-call target="/b" confirm="?"/></success></api-call></on></div>'],
            '<case> 안' => ['<div data-ref="d"><on event="onAct"><switch value="{{$args[0]}}"><case when="x"><api-call target="/b" confirm="?"/></case></switch></on></div>'],
        ];
    }

    /**
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects init_root_action_is_limited_to_the_reduced_whitelist
     */
    public function test_init_action_allows_whitelisted_keys(): void
    {
        $action = $this->compileInitAction('<api-call target="/api/boot" method="GET"><success><toast message="ok"/></success></api-call>');

        $this->assertSame('apiCall', $action['handler']);
        $this->assertSame('/api/boot', $action['target']);
        $this->assertArrayHasKey('onSuccess', $action);
    }

    /**
     * @scenario compile_unit=action,source_health=invalid
     *
     * @effects init_root_action_is_limited_to_the_reduced_whitelist
     */
    public function test_init_action_rejects_keys_dropped_by_the_engine(): void
    {
        // executeInitActions 는 confirm·debounce 등을 자식 actionDef 에 전달하지 않아 침묵 탈락한다.
        $this->assertSame(
            LayoutMarkupErrorCode::InitActionKeyForbidden,
            $this->compileInitActionError('<api-call target="/x" confirm="정말?"/>')->getErrorCode(),
        );

        $this->assertSame(
            LayoutMarkupErrorCode::InitActionKeyForbidden,
            $this->compileInitActionError('<api-call target="/x" :debounce="300"/>')->getErrorCode(),
        );
    }

    // ---------------------------------------------------------------------
    // 에러 케이스
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=action,source_health=invalid
     *
     * @effects malformed_on_block_is_rejected
     */
    #[DataProvider('onErrorProvider')]
    public function test_malformed_on_block_is_rejected(string $source, LayoutMarkupErrorCode $code): void
    {
        $this->assertSame($code, $this->compileError($source)->getErrorCode());
    }

    /**
     * @return array<string, array{0: string, 1: LayoutMarkupErrorCode}>
     */
    public static function onErrorProvider(): array
    {
        return [
            '트리거 없음' => ['<div data-ref="d"><on><navigate path="/x"/></on></div>', LayoutMarkupErrorCode::ActionTriggerMissing],
            'listen+type 충돌' => ['<div data-ref="d"><on type="click" listen="x"><toast message="y"/></on></div>', LayoutMarkupErrorCode::ActionTriggerConflict],
            'mount+type 충돌' => ['<div data-ref="d"><on event="mount" type="click"><toast message="y"/></on></div>', LayoutMarkupErrorCode::ActionTriggerConflict],
            '빈 블록' => ['<div data-ref="d"><on type="click"/></div>', LayoutMarkupErrorCode::EmptyActionBlock],
            '미등록 핸들러(제안)' => ['<div data-ref="d"><on type="click"><naviage path="/x"/></on></div>', LayoutMarkupErrorCode::UnknownActionTagWithSuggestion],
            '미등록 핸들러(무제안)' => ['<div data-ref="d"><on type="click"><zzzzzz/></on></div>', LayoutMarkupErrorCode::UnknownActionTag],
            '<action> handler 누락' => ['<div data-ref="d"><on type="click"><action field="x"/></on></div>', LayoutMarkupErrorCode::MissingRequiredAttribute],
            '<switch> 잘못된 자식' => ['<div data-ref="d"><on event="a"><switch value="{{v}}"><navigate path="/x"/></switch></on></div>', LayoutMarkupErrorCode::UnexpectedActionChild],
            '<case> when 누락' => ['<div data-ref="d"><on event="a"><switch value="{{v}}"><case><toast message="x"/></case></switch></on></div>', LayoutMarkupErrorCode::MissingRequiredAttribute],
            '케이스 중복' => ['<div data-ref="d"><on event="a"><switch value="{{v}}"><case when="x"><toast message="1"/></case><case when="x"><toast message="2"/></case></switch></on></div>', LayoutMarkupErrorCode::DuplicateCaseKey],
            '핸들러 자식에 액션' => ['<div data-ref="d"><on type="click"><toast message="x"><navigate path="/y"/></toast></on></div>', LayoutMarkupErrorCode::UnexpectedActionChild],
            '<success> 중복' => ['<div data-ref="d"><on type="click"><api-call target="/x"><success><toast message="a"/></success><success><toast message="b"/></success></api-call></on></div>', LayoutMarkupErrorCode::DuplicateActionKey],
        ];
    }

    /**
     * @effects on_component_event_and_success_carry_multiple_actions_as_array
     */
    public function test_success_with_one_child_is_object_and_many_is_array(): void
    {
        $one = $this->compile('<div data-ref="d"><on type="click"><api-call target="/x"><success><toast message="a"/></success></api-call></on></div>')['actions'][0];
        $this->assertArrayHasKey('handler', $one['onSuccess']);

        $many = $this->compile('<div data-ref="d"><on type="click"><api-call target="/x"><success><toast message="a"/><navigate path="/y"/></success></api-call></on></div>')['actions'][0];
        $this->assertCount(2, $many['onSuccess']);
    }

    /**
     * `<switch on>` 은 엔진이 읽는 `params.value` 로, `<switch value>` 는 top-level `value` 로 간다 (D42 G8).
     *
     * 두 배치가 코퍼스에 실물로 공존하고 뜻이 다르므로(엔진은 params 만 소비) 표기가 갈려야 한다 —
     * 하나로 합치면 이관이 배치를 바꾸거나(D38 위반) 액션 트리가 통째로 `<json>` 탈출한다(D42 위반).
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects switch_subject_placement_is_expressible_in_both_forms
     */
    public function test_switch_subject_attribute_splits_two_placements(): void
    {
        $params = $this->compile('<div data-ref="d"><on event="a"><switch on="{{$args[0]}}"><case when="x"><toast message="1"/></case></switch></on></div>')['actions'][0];

        $this->assertSame(['value' => '{{$args[0]}}'], $params['params']);
        $this->assertArrayNotHasKey('value', $params);

        $topLevel = $this->compile('<div data-ref="d"><on event="a"><switch value="{{$args[0]}}"><case when="x"><toast message="1"/></case></switch></on></div>')['actions'][0];

        $this->assertSame('{{$args[0]}}', $topLevel['value']);
        $this->assertArrayNotHasKey('params', $topLevel);
    }

    /**
     * 액션 구조값 파라미터가 중첩 객체·문자열 리스트까지 받는다 (D42 G6 — `StructuredValueCompiler`).
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects structured_param_accepts_nested_field_tree_and_string_items
     */
    public function test_structured_param_accepts_nested_tree_and_string_items(): void
    {
        $action = $this->compile(
            '<div data-ref="d"><on type="click"><set-state target="local">'
            .'<field name="editingChannel">'
            .'<field name="key" value=""/>'
            .'<field name="label"><field name="ko" value="한글"/><field name="en" value="English"/></field>'
            .'<field name="enabled" :value="true"/>'
            .'<field name="tags" :value="[]"/>'
            .'</field>'
            .'<field name="errors"><field name="slug"><item>$t:required</item><item>$t:duplicated</item></field></field>'
            .'</set-state></on></div>'
        )['actions'][0];

        $this->assertSame([
            'key' => '',
            'label' => ['ko' => '한글', 'en' => 'English'],
            'enabled' => true,
            'tags' => [],
        ], $action['params']['editingChannel']);

        $this->assertSame(['slug' => ['$t:required', '$t:duplicated']], $action['params']['errors']);
    }

    /**
     * top-level 구조값 키(`resultTo`·`identity_target`)도 `<field name>` 으로 적고, 배치는 매니페스트가
     * 정한다 (D51 G15 — params 별 자리를 새로 만들지 않고 D30 ④ 배치 규칙을 그대로 탄다).
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects structured_field_placement_follows_handler_manifest
     */
    public function test_structured_field_lands_top_level_when_manifest_says_so(): void
    {
        $action = $this->compile(
            '<div data-ref="d"><on type="click"><load-from-local-storage key="recentProductIds" :defaultValue="[]">'
            .'<field name="resultTo"><field name="target" value="_global"/><field name="key" value="recentProductIds"/></field>'
            .'</load-from-local-storage></on></div>'
        )['actions'][0];

        $this->assertSame(['target' => '_global', 'key' => 'recentProductIds'], $action['resultTo']);
        $this->assertArrayNotHasKey('resultTo', $action['params']);
    }

    /**
     * 매니페스트가 top-level 로 모르는 이름은 종전대로 params 로 간다 (배치 규칙의 대조군).
     *
     * @scenario compile_unit=action,source_health=valid
     *
     * @effects structured_field_placement_follows_handler_manifest
     */
    public function test_structured_field_stays_in_params_when_not_top_level(): void
    {
        $action = $this->compile(
            '<div data-ref="d"><on type="click"><set-state target="local">'
            .'<field name="resultToLike"><field name="a" value="b"/></field>'
            .'</set-state></on></div>'
        )['actions'][0];

        $this->assertSame(['a' => 'b'], $action['params']['resultToLike']);
        $this->assertArrayNotHasKey('resultToLike', $action);
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 소스를 컴파일해 첫 루트 노드를 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> JSON 노드
     */
    private function compile(string $source): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source));

        return $this->compiler->compile($roots[0]);
    }

    /**
     * 컴파일이 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return CompileError 발생한 오류
     */
    private function compileError(string $source): CompileError
    {
        try {
            $this->compile($source);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("컴파일 오류를 기대했으나 통과했습니다: {$source}");
    }

    /**
     * 핸들러 요소 하나를 init 루트 액션으로 컴파일합니다.
     *
     * @param  string  $source  핸들러 태그 `.g7.html` 소스
     * @return array<string, mixed> 액션 객체
     */
    private function compileInitAction(string $source): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source));

        return (new ActionCompiler)->compileInitAction($roots[0], null);
    }

    /**
     * init 루트 컴파일이 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  string  $source  핸들러 태그 `.g7.html` 소스
     * @return CompileError 발생한 오류
     */
    private function compileInitActionError(string $source): CompileError
    {
        try {
            $this->compileInitAction($source);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("컴파일 오류를 기대했으나 통과했습니다: {$source}");
    }

    /**
     * 키 순서까지 비교하기 위한 정규 인코딩을 만듭니다.
     *
     * @param  array<string, mixed>  $node  노드
     * @return string 인코딩 결과
     */
    private function encode(array $node): string
    {
        return json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
