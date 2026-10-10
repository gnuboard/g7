<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutCorpus;
use Illuminate\Support\Facades\File;

/**
 * S16 결함 대장(F1~F22)의 **기계 판본**.
 *
 * 계획서의 결함 대장은 서술이라 세 가지 방식으로 낡는다 — 건수를 잘못 세고(F4 가 9 로 적혔으나 실측 15),
 * 웨이브가 바뀌면 새 사례가 나오며, 고친 뒤 표를 갱신하는 것을 잊는다. 이 테스트가 그 표를 대신한다:
 * 각 결함 계열이 코퍼스 전량에서 **0건**임을 단언하고, 남아 있으면 파일·경로를 찍어 준다.
 *
 * 왕복 게이트({@see LayoutMarkupRoundTripCorpusTest})와 역할이 다르다. 저쪽은 "이관된 `.g7.html` 이
 * 원본과 같은가" 를 보므로 **아직 이관하지 않은 `.json` 의 결함은 보지 못한다**. 이쪽은 이관 여부와
 * 무관하게 **소스 JSON 자체**를 본다. 그래서 F2·F12 처럼 escape 를 만들지 않는 결함도 잡힌다.
 *
 * 판정은 **노드 위치를 지켜서** 한다 — `props.size`·`classMap.key` 같은 정상 표면을 결함으로 세면
 * 대장이 부풀고, 그 부푼 수치가 다시 계획서로 들어간다.
 */
class LayoutDefectLedgerTest extends LayoutMarkupTestCase
{
    /**
     * 노드 자식이 **위치로** 확정되는 키 — 이 배열의 원소는 이름이 없어도 노드 자리다.
     *
     * @var list<string>
     */
    private const NODE_POSITION_LISTS = ['children', 'components'];

    /**
     * 액션 배열이 중첩되는 키.
     *
     * @var list<string>
     */
    private const ACTION_NESTS = [
        'actions', 'then', 'else', 'success', 'fail', 'onSuccess', 'onError', 'cases', 'conditions', 'default',
    ];

    /**
     * 노드가 액션을 다는 키.
     *
     * @var list<string>
     */
    private const ACTION_SLOTS = ['actions', 'init_actions', 'named_actions', 'error_handling'];

    /**
     * 코퍼스 전량(레이아웃 + fragment)의 원본 JSON 을 읽습니다.
     *
     * @return array<string, array<mixed>> 상대경로 → 디코드된 레이아웃
     */
    private function corpus(): array
    {
        $files = array_unique(array_merge(LayoutCorpus::scan('.json'), LayoutCorpus::scanFragments('.json')));
        sort($files);

        // 결함 대장 19행은 전부 "코퍼스에 이 형태가 없다" 를 주장한다. 코퍼스가 0건이면 그 주장이
        // 전건 공허하게 참이 되므로, 모집단 실재를 여기서 한 번 못박는다 (31차 근본 D).
        $this->assertNotEmpty($files,
            '결함 대장의 검사 코퍼스가 0건이다 — 스캔 글롭이 좁아졌는지 먼저 확인해야 한다 '
            .'(모집단이 비면 대장 19행 전부가 검사 없이 통과한다)');

        $corpus = [];

        foreach ($files as $absolute) {
            $decoded = json_decode(File::get($absolute), true);

            if (is_array($decoded)) {
                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $absolute));
                $corpus[$relative] = $decoded;
            }
        }

        return $corpus;
    }

    /**
     * 트리 **전량**을 훑되, 노드로 판정된 것만 방문자에게 넘깁니다.
     *
     * 컨테이너 키 화이트리스트로 훑으면 목록에 없는 자리가 통째로 사각이 된다 — fragment 의
     * `injections[].components` 가 그랬고(F9·F10·F11 이 조용히 0건으로 통과했다), 그것은 ㉓ 이 겪은
     * "반쪽 코퍼스 위의 escape-0" 과 같은 계열이다. 그래서 **탐색은 전량**, **판정은 구조**로 가른다:
     * 노드는 ⓐ `type`+`name` 을 함께 가졌거나 ⓑ `partial` 을 가졌거나 ⓒ `children`/`components`
     * 배열의 원소인 것이다. `props: {name: "title"}` 처럼 이름만 있는 값은 노드가 아니다.
     *
     * @param  array<mixed>  $value  값
     * @param  callable(array<mixed>): void  $visit  방문자
     * @param  bool  $inNodePosition  노드 자리(children/components 원소)인지
     */
    private function walkNodes(array $value, callable $visit, bool $inNodePosition = true): void
    {
        if ($inNodePosition || $this->isNode($value)) {
            $visit($value);
        }

        foreach ($value as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $childIsNodeList = in_array($key, self::NODE_POSITION_LISTS, true) && array_is_list($child);

            if ($childIsNodeList) {
                foreach ($child as $element) {
                    if (is_array($element)) {
                        $this->walkNodes($element, $visit, true);
                    }
                }

                continue;
            }

            $this->walkNodes($child, $visit, false);
        }
    }

    /**
     * 구조로 노드인지 판정합니다.
     *
     * @param  array<mixed>  $value  값
     * @return bool 노드면 true
     */
    private function isNode(array $value): bool
    {
        return isset($value['partial'])
            || (isset($value['type']) && isset($value['name']))
            || (isset($value['name']) && isset($value['children']));
    }

    /**
     * 액션 트리를 훑습니다 (depth 0 = 진입점 액션).
     *
     * @param  mixed  $actions  액션 배열/객체
     * @param  callable(array<mixed>, int): void  $visit  방문자
     * @param  int  $depth  중첩 깊이
     */
    private function walkActions(mixed $actions, callable $visit, int $depth = 0): void
    {
        if (! is_array($actions)) {
            return;
        }

        foreach (array_is_list($actions) ? $actions : [$actions] as $action) {
            if (! is_array($action)) {
                continue;
            }

            if (isset($action['handler'])) {
                $visit($action, $depth);
            }

            foreach (self::ACTION_NESTS as $nest) {
                foreach ([$action[$nest] ?? null, $action['params'][$nest] ?? null] as $nested) {
                    if (! is_array($nested)) {
                        continue;
                    }

                    if (array_is_list($nested) || isset($nested['handler'])) {
                        $this->walkActions($nested, $visit, $depth + 1);

                        continue;
                    }

                    foreach ($nested as $branch) {
                        $this->walkActions($branch, $visit, $depth + 1);
                    }
                }
            }
        }
    }

    /**
     * 결함 발견 목록을 사람이 읽을 수 있는 실패 메시지로 만듭니다.
     *
     * @param  list<string>  $findings  발견 목록
     * @param  string  $label  결함 라벨
     * @return string 실패 메시지
     */
    private function message(array $findings, string $label): string
    {
        return sprintf("%s — %d건 잔존:\n  %s", $label, count($findings), implode("\n  ", $findings));
    }

    /**
     * 노드 레벨 `condition` 이 맨 문자열이면 항상 참이 됩니다 (F1 — 실버그).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects bare_string_node_condition_is_eliminated
     */
    public function test_f1_no_bare_string_node_condition(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (! array_key_exists('condition', $node) || isset($node['handler'])) {
                    return;
                }

                if (is_string($node['condition']) && ! str_contains($node['condition'], '{{')) {
                    $findings[] = "{$file} — condition: {$node['condition']}";
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F1 맨 문자열 노드 condition'));
    }

    /**
     * `switch` 액션의 분기 대상은 `params.value` 입니다 (F2 — top-level `value` 는 미소비).
     *
     * @scenario compile_unit=action,source_health=defective
     *
     * @effects switch_branch_value_lives_in_params
     */
    public function test_f2_no_switch_top_level_value(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->eachAction($layout, function (array $action) use (&$findings, $file): void {
                if (($action['handler'] ?? null) === 'switch' && array_key_exists('value', $action)) {
                    $findings[] = $file;
                }
            });
        }

        $this->assertSame([], array_unique($findings), $this->message($findings, 'F2 switch top-level value'));
    }

    /**
     * 액션의 조건 키는 `if` 입니다 (F3 — `condition` 은 액션에서 미소비).
     *
     * @scenario compile_unit=action,source_health=defective
     *
     * @effects action_condition_alias_is_normalized_to_if
     */
    public function test_f3_no_action_condition_key(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->eachAction($layout, function (array $action) use (&$findings, $file): void {
                if (array_key_exists('condition', $action)) {
                    $findings[] = $file.' — handler: '.($action['handler'] ?? '?');
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F3 액션 condition'));
    }

    /**
     * 액션의 죽은 키를 봉인합니다 (F4 — `trigger`·`stopPropagation`·custom `name`·setState top-level `target`).
     *
     * 대장 서술이 9건으로 적혀 있었으나 실측은 15건이었다 — 그래서 건수가 아니라 **0** 을 단언한다.
     *
     * @scenario compile_unit=action,source_health=defective
     *
     * @effects dead_action_keys_are_removed
     */
    public function test_f4_no_dead_action_keys(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->eachAction($layout, function (array $action) use (&$findings, $file): void {
                $handler = $action['handler'] ?? '?';

                // `stopPropagation` 은 더 이상 죽은 키가 아니다 — engine-v1.55.0 이 진입점 액션에서
                // 이 키를 읽어 `event.stopPropagation()` 을 호출한다. 클릭 가능한 행 안의 토글이
                // 행 선택까지 발화시키던 결함을 이 키가 막는다.
                if (array_key_exists('trigger', $action)) {
                    $findings[] = "{$file} — {$handler}.trigger";
                }

                if ($handler === 'custom' && array_key_exists('name', $action)) {
                    $findings[] = "{$file} — custom.name (params 로 이동)";
                }

                if ($handler === 'setState' && array_key_exists('target', $action)) {
                    $findings[] = "{$file} — setState.target (params.target 이 정본)";
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F4 액션 죽은 키'));
    }

    /**
     * 모달 푸터는 `props.footer` 입니다 (F5 — 노드 레벨 `footer` 는 렌더되지 않는다).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects modal_footer_moves_to_props
     */
    public function test_f5_no_node_level_footer(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (array_key_exists('footer', $node) && (isset($node['type']) || isset($node['name']))) {
                    $findings[] = $file.' — '.($node['name'] ?? '?');
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F5 노드 레벨 footer'));
    }

    /**
     * 상태 초기화는 `initLocal` 하나입니다 (F6 — `state` 병존은 죽은 키).
     *
     * @scenario compile_unit=document,source_health=defective
     *
     * @effects state_and_initlocal_do_not_coexist
     */
    public function test_f6_no_state_and_initlocal_coexistence(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            if (isset($layout['state']) && isset($layout['initLocal'])) {
                $findings[] = $file;
            }
        }

        $this->assertSame([], $findings, $this->message($findings, 'F6 state + initLocal 병존'));
    }

    /**
     * 생명주기 액션에는 DOM 이벤트가 없습니다 (F7 — `type:"click"` 은 죽은 키).
     *
     * @scenario compile_unit=action,source_health=defective
     *
     * @effects lifecycle_actions_carry_no_dom_event_type
     */
    public function test_f7_no_dead_lifecycle_event_type(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                foreach (['lifecycle', 'onComponentEvent'] as $slot) {
                    if (! isset($node[$slot]) || ! is_array($node[$slot])) {
                        continue;
                    }

                    foreach ($node[$slot] as $phase => $actions) {
                        $this->walkActions($actions, function (array $action) use (&$findings, $file, $slot, $phase): void {
                            if (isset($action['type'])) {
                                $findings[] = "{$file} — {$slot}.{$phase}.type={$action['type']}";
                            }
                        });
                    }
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F7 생명주기 죽은 type'));
    }

    /**
     * 노드의 죽은 키·사어를 봉인합니다 (F8).
     *
     * `_comment` 는 서빙 strip 대상이라 `comment` 와 동치이지만, 작성 채널은 `comment` 하나로 모은다 —
     * 방출기가 `<!-- -->` 로 내는 것이 `comment` 뿐이라 `_comment` 는 이관 때마다 탈출구를 쓰게 된다.
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects dead_node_keys_and_comment_aliases_are_removed
     */
    public function test_f8_no_dead_node_keys(): void
    {
        $dead = ['name_attr', 'loop', 'html', 'iterator', 'bindings', 'size', 'data_binding'];
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->assertNoCommentAlias($layout, $file, $findings);

            $this->walkNodes($layout, function (array $node) use (&$findings, $file, $dead): void {
                if (! isset($node['type']) && ! isset($node['name'])) {
                    return;
                }

                foreach ($dead as $key) {
                    if (array_key_exists($key, $node)) {
                        $findings[] = "{$file} — 노드 {$key}";
                    }
                }

                if (isset($node['iteration']) && is_array($node['iteration']) && isset($node['iteration']['data'])) {
                    $findings[] = "{$file} — iteration.data (source 가 정본)";
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F8 죽은 키·사어'));
    }

    /**
     * `{{}}` 안에 `{{}}` 를 중첩하면 항상 거짓이 됩니다 (F9 — 실버그).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects nested_interpolation_is_eliminated
     */
    public function test_f9_no_nested_interpolation(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                foreach (['if', 'condition'] as $key) {
                    $value = $node[$key] ?? null;

                    if (is_string($value) && preg_match('/\{\{[^{}]*\{\{/', $value)) {
                        $findings[] = "{$file} — {$key}: {$value}";
                    }
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F9 중첩 보간'));
    }

    /**
     * `bindLocal` 은 엔진 소비처가 0입니다 (F10 — 죽은 키).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects bindlocal_node_key_is_removed
     */
    public function test_f10_no_bindlocal_node_key(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (array_key_exists('bindLocal', $node)) {
                    $findings[] = $file;
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F10 bindLocal'));
    }

    /**
     * 레지스트리에 없는 컴포넌트는 렌더되지 않습니다 (F11·F21 — 실버그).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects unregistered_components_are_replaced
     */
    public function test_f11_and_f21_no_unregistered_components(): void
    {
        $unregistered = ['Dl', 'Dt', 'Dd', 'DropdownItem'];
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file, $unregistered): void {
                if (in_array($node['name'] ?? '', $unregistered, true)) {
                    $findings[] = "{$file} — {$node['name']}";
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F11·F21 미등록 컴포넌트'));
    }

    /**
     * 루트가 노드 하나인 조각은 `meta.is_partial` 을 선언합니다 (F12 — D57 ⓐ).
     *
     * @scenario compile_unit=document,source_health=defective
     *
     * @effects bare_node_partials_declare_is_partial
     */
    public function test_f12_bare_node_partials_declare_is_partial(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $isBareNode = (isset($layout['type']) || isset($layout['name']) || isset($layout['partial']))
                && ! isset($layout['components'])
                && ! isset($layout['extends']);

            if ($isBareNode && ! ($layout['meta']['is_partial'] ?? false)) {
                $findings[] = $file;
            }
        }

        $this->assertSame([], $findings, $this->message($findings, 'F12 is_partial 미선언 bare-node partial'));
    }

    /**
     * `partial` 노드의 형제 키는 설치 시점에 소실됩니다 (F13 죽은 키 · F16 조건 소실 = 실버그).
     *
     * `resolveAllPartials` 가 `partial` 키를 보면 그 노드를 조각 내용으로 **통째 치환**하므로,
     * 형제로 적은 `id`·`type`·`name` 은 물론 `if`·`condition` 까지 함께 사라진다.
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects partial_nodes_carry_no_sibling_keys
     */
    public function test_f13_and_f16_no_partial_sibling_keys(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (! isset($node['partial'])) {
                    return;
                }

                foreach (['type', 'name', 'id', 'if', 'condition'] as $sibling) {
                    if (array_key_exists($sibling, $node)) {
                        $findings[] = "{$file} — partial + {$sibling}";
                    }
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F13·F16 partial 형제 키'));
    }

    /**
     * `$partial` 은 어디에서도 해석되지 않습니다 (F14).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects dollar_partial_node_is_eliminated
     */
    public function test_f14_no_dollar_partial_node(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (isset($node['$partial'])) {
                    $findings[] = $file;
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F14 $partial 노드'));
    }

    /**
     * `if` 는 액션 키이지 핸들러가 아닙니다 (F15 — 실버그).
     *
     * @scenario compile_unit=action,source_health=defective
     *
     * @effects if_is_not_used_as_a_handler_name
     */
    public function test_f15_no_if_handler(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->eachAction($layout, function (array $action) use (&$findings, $file): void {
                if (($action['handler'] ?? null) === 'if') {
                    $findings[] = $file;
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F15 미등록 핸들러 if'));
    }

    /**
     * 반복 소스는 `iteration.source` 이고 `iteration` 은 객체입니다 (F17·F20 — 실버그).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects iteration_is_an_object_with_a_source_key
     */
    public function test_f17_and_f20_iteration_shape(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (! array_key_exists('iteration', $node)) {
                    return;
                }

                if (is_string($node['iteration'])) {
                    $findings[] = "{$file} — 문자열 iteration";

                    return;
                }

                if (is_array($node['iteration']) && ! isset($node['iteration']['source'])) {
                    $keys = implode(',', array_keys($node['iteration']));
                    $findings[] = "{$file} — iteration.source 부재 ({$keys})";
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F17·F20 iteration 형태'));
    }

    /**
     * `confirm` 은 진입점 액션에서만 읽힙니다 (F18 — 실버그: 확인창 없는 파괴적 작업).
     *
     * @scenario compile_unit=action,source_health=defective
     *
     * @effects confirm_only_appears_on_entry_actions
     */
    public function test_f18_no_nested_confirm(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->eachAction($layout, function (array $action, int $depth) use (&$findings, $file): void {
                if ($depth > 0 && array_key_exists('confirm', $action)) {
                    $findings[] = $file.' — depth '.$depth;
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F18 중첩 confirm'));
    }

    /**
     * 노드 레벨 `key` 는 소비처가 0입니다 (F19 — React key 는 `getRemountKey` 가 파생).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects node_level_key_is_removed
     */
    public function test_f19_no_node_level_key(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            $this->walkNodes($layout, function (array $node) use (&$findings, $file): void {
                if (array_key_exists('key', $node) && (isset($node['type']) || isset($node['name']))) {
                    $findings[] = $file;
                }
            });
        }

        $this->assertSame([], $findings, $this->message($findings, 'F19 노드 레벨 key'));
    }

    /**
     * 이름 없는 노드는 렌더되지 않습니다 (F22 — 실버그).
     *
     * @scenario compile_unit=node,source_health=defective
     *
     * @effects every_node_declares_a_component_name
     */
    public function test_f22_no_anonymous_nodes(): void
    {
        $findings = [];

        foreach ($this->corpus() as $file => $layout) {
            foreach ($this->nodeLists($layout) as $path => $nodes) {
                foreach ($nodes as $index => $node) {
                    if (! is_array($node)) {
                        continue;
                    }

                    $named = isset($node['name']) || isset($node['partial']) || isset($node['field']);

                    if (! $named && (isset($node['children']) || isset($node['iteration']))) {
                        $findings[] = "{$file} — {$path}[{$index}]";
                    }
                }
            }
        }

        $this->assertSame([], $findings, $this->message($findings, 'F22 이름 없는 익명 노드'));
    }

    /**
     * 노드 자식 목록을 경로와 함께 모읍니다 (F22 판정용 — 노드 위치를 확실히 가른다).
     *
     * @param  array<mixed>  $layout  레이아웃
     * @param  string  $path  현재 경로
     * @return array<string, array<mixed>> 경로 → 자식 목록
     */
    private function nodeLists(array $layout, string $path = ''): array
    {
        $lists = [];

        foreach ($layout as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";

            if (in_array($key, ['children', 'components'], true) && array_is_list($value)) {
                $lists[$childPath] = $value;
            }

            $lists += $this->nodeLists($value, $childPath);
        }

        return $lists;
    }

    /**
     * `_comment` 별칭과 사어 변형(`_comment_*`)이 남아 있는지 봅니다.
     *
     * @param  array<mixed>  $value  값
     * @param  string  $file  파일 경로
     * @param  list<string>  $findings  발견 목록 (참조)
     */
    private function assertNoCommentAlias(array $value, string $file, array &$findings): void
    {
        foreach ($value as $key => $child) {
            if (is_string($key) && ($key === '_comment' || str_starts_with($key, '_comment_'))) {
                $findings[] = "{$file} — {$key} (정본은 comment)";
            }

            if (is_array($child)) {
                $this->assertNoCommentAlias($child, $file, $findings);
            }
        }
    }

    /**
     * 레이아웃 안의 모든 액션을 방문합니다 (노드 액션 + 문서 섹션 + 데이터소스 콜백).
     *
     * @param  array<mixed>  $layout  레이아웃
     * @param  callable(array<mixed>, int): void  $visit  방문자
     */
    private function eachAction(array $layout, callable $visit): void
    {
        foreach (self::ACTION_SLOTS as $slot) {
            if (isset($layout[$slot])) {
                $this->walkActions($layout[$slot], $visit);
            }
        }

        foreach ((array) ($layout['data_sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach (['success', 'fail', 'init', 'onSuccess', 'onError', 'errorHandling', 'error_handling'] as $callback) {
                if (isset($source[$callback])) {
                    $this->walkActions($source[$callback], $visit);
                }
            }
        }

        $this->walkNodes($layout, function (array $node) use ($visit): void {
            foreach (self::ACTION_SLOTS as $slot) {
                if (isset($node[$slot])) {
                    $this->walkActions($node[$slot], $visit);
                }
            }

            // 생명주기·컴포넌트 이벤트 슬롯도 액션을 단다 — 여기만 빠지면 그 자리의 결함이 침묵한다.
            foreach (['lifecycle', 'onComponentEvent'] as $slot) {
                if (! isset($node[$slot]) || ! is_array($node[$slot])) {
                    continue;
                }

                foreach ($node[$slot] as $phaseActions) {
                    $this->walkActions($phaseActions, $visit);
                }
            }
        });
    }
}
