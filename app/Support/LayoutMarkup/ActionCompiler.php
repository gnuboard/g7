<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * `<on>` 이벤트 블록과 그 안의 액션 트리를 JSON 액션으로 컴파일하는 층 (매핑 사전 E).
 *
 * 이 층이 확정하는 것은 셋이다.
 *
 * **① `<on>` 이 세 노드 키로 갈린다.** 작성자는 어떤 이벤트에 무엇을 할지를 `<on>` 하나로 쓰지만,
 * 엔진은 이를 세 자리에서 읽는다 — 컴포넌트 자기 이벤트는 `actions[]`, 마운트/언마운트는
 * `lifecycle`, 다른 곳에서 emit 된 이벤트 구독은 `onComponentEvent[]`. `<on>` 의 속성이 어느
 * 자리인지를 정한다: `type`/`event` 는 자기 이벤트, `event="mount|unmount"` 는 생명주기,
 * `listen` 은 구독. 한 이벤트에 여러 `<on>` 블록을 쓰면 fire-and-forget 병렬(각 항목이 별도
 * 액션), 한 `<on>` 안에 여러 자식을 쓰면 순차(암묵 sequence)다 — 병렬 3형태(계획 §201)를
 * 마크업 형태로 구분해 왕복이 시맨틱을 조용히 바꾸지 않게 한다.
 *
 * **② 키가 top-level 이냐 params 냐는 핸들러가 정한다.** ActionHandlerManifest 가 SSoT 다.
 * `target` 은 apiCall 이면 엔드포인트(top-level), setState 면 상태 스코프(params)라 핸들러마다
 * 다르다 — 넣는 위치가 틀리면 엔진이 조용히 무시하므로 매니페스트로 결정한다.
 *
 * **③ confirm 은 진입점에서만.** 엔진은 이벤트에 바인딩된 루트 액션에서만 confirm 을 발화하고
 * 중첩 실행(executeAction)에서는 읽지 않는다. 그래서 중첩 액션의 confirm 은 컴파일 에러다 —
 * 작성자는 확인창이 뜰 것으로 기대하지만 실제로는 아무 일도 없기 때문이다. init 루트는 한술 더 떠
 * confirm 을 포함한 여러 키가 통째로 탈락하므로 축소 화이트리스트로 더 좁게 잡는다.
 *
 * 표현식(`{{...}}`/`$t:`)은 어느 위치에서도 선평가하지 않는다 — 액션 `if` 의 혼합형 판정만이
 * 예외다(NodeCompiler 와 동일 규칙, MarkupValue 공유).
 */
final class ActionCompiler
{
    /**
     * `<on>` 이 자기 이벤트가 아니라 생명주기로 가는 예약 이벤트명.
     *
     * `lifecycle.onMount`/`onUnmount` 는 DOM 이벤트도 컴포넌트 콜백도 아니라 마운트 시점에 실행되는
     * 액션 배열이다. 실제 컴포넌트 콜백에 `mount`/`unmount` 라는 이름은 없으므로 예약어로 안전하다.
     *
     * @var array<string, string>
     */
    private const LIFECYCLE_EVENTS = ['mount' => 'onMount', 'unmount' => 'onUnmount'];

    /**
     * 제어 흐름 태그 → 핸들러명. 전용 자식 문법을 갖는다(자식이 액션이거나 케이스 맵).
     *
     * @var array<string, string>
     */
    private const CONTROL_TAGS = [
        'switch' => 'switch',
        'conditions' => 'conditions',
        'parallel' => 'parallel',
        'sequence' => 'sequence',
    ];

    /**
     * 단순 핸들러 태그의 자식으로 올 수 있는 부속 구조 태그.
     *
     * 이들은 액션이 아니라 액션의 부속 채널(성공/실패 후속·에러맵·요청 바디/쿼리)이다.
     *
     * @var array<int, string>
     */
    private const SUB_STRUCTURAL_TAGS = ['success', 'fail', 'error-handling', 'body', 'query', 'field', 'actions'];

    /**
     * 액션 객체 키의 결정적 직렬화 순서 (diff·골든 테스트 성립 조건).
     *
     * @var array<int, string>
     */
    private const KEY_ORDER = [
        'comment', 'type', 'event', 'key', 'handler', 'target', 'if',
        'auth_mode', 'auth_required', 'identity_target', 'params',
        'cases', 'conditions', 'actions', 'resultTo', 'debounce', 'actionRef',
        'render', 'onLoad', 'confirm', 'onSuccess', 'onError', 'errorHandling',
    ];

    private readonly ActionHandlerManifest $manifest;

    /** 구조값 컴파일러 — `<field name>` 파라미터 안의 `<field>`/`<item>` 트리 (D42 G6) */
    private readonly StructuredValueCompiler $values;

    /** 소스 위치 표식 켜짐 여부 — {@see markSourcePositions()} */
    private bool $markSource = false;

    /**
     * 핸들러 표(ActionHandlerManifest)와 구조값 컴파일러를 준비합니다.
     */
    public function __construct()
    {
        $this->manifest = new ActionHandlerManifest;
        $this->values = new StructuredValueCompiler(LayoutMarkupErrorCode::UnexpectedActionChild);
    }

    /**
     * `<on>` 블록 하나를 노드의 actions/lifecycle/onComponentEvent 키로 흡수합니다.
     *
     * @param  MarkupElement  $on  `<on>` 요소
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError `<on>` 표기가 규칙을 벗어난 경우
     */
    public function applyOn(MarkupElement $on, array &$node, ?string $path): void
    {
        $event = $on->attribute('event');
        $type = $on->attribute('type');
        $listen = $on->attribute('listen');

        $eventValue = $event?->value;
        $typeValue = $type?->value;
        $listenValue = $listen?->value;

        if ($listenValue !== null) {
            if ($eventValue !== null || $typeValue !== null) {
                throw $this->error($on, LayoutMarkupErrorCode::ActionTriggerConflict, $path);
            }

            $this->applySubscription($on, $listenValue, $node, $path);

            return;
        }

        // 생명주기 — event 가 예약어이고 type 과 함께 쓸 수 없다(마운트는 DOM 이벤트가 아니다).
        if ($eventValue !== null && isset(self::LIFECYCLE_EVENTS[$eventValue])) {
            if ($typeValue !== null) {
                throw $this->error($on, LayoutMarkupErrorCode::ActionTriggerConflict, $path);
            }

            $this->applyLifecycle($on, self::LIFECYCLE_EVENTS[$eventValue], $node, $path);

            return;
        }

        if ($eventValue === null && $typeValue === null) {
            throw $this->error($on, LayoutMarkupErrorCode::ActionTriggerMissing, $path);
        }

        // 자기 이벤트 — actions[] 에 항목 하나로 붙인다. 트리거(type/event/key)를 루트 액션에 병합.
        $trigger = [];

        if ($typeValue !== null) {
            $trigger['type'] = $typeValue;
        }

        if ($eventValue !== null) {
            $trigger['event'] = $eventValue;
        }

        // 키 한정자 — keydown/keypress 에서 특정 키만 통과시키는 트리거 필터(ActionDispatcher:7079).
        // 핸들러 인자가 아니라 트리거의 일부라 `<on>` 속성으로 받는다.
        $keyValue = $on->attribute('key')?->value;

        if ($keyValue !== null) {
            $trigger['key'] = $keyValue;
        }

        $action = $this->compileBlockBody($on, $trigger, true, $path);
        $node['actions'][] = $action;
    }

    /**
     * init_actions 루트 액션 하나를 컴파일합니다 (문서 섹션 층 S4 가 `<init>` 에서 호출).
     *
     * init 루트는 executeInitActions 가 축소 키만 전달하므로(§199) 화이트리스트 밖 키를 거부한다.
     *
     * @param  MarkupElement  $element  핸들러 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 표기 규칙 위반 또는 화이트리스트 밖 키인 경우
     */
    public function compileInitAction(MarkupElement $element, ?string $path): array
    {
        // 진입점으로 컴파일해(entry=true) confirm 등 화이트리스트 밖 키가 액션에 실리게 둔다 —
        // 그래야 아래 화이트리스트 검사가 ConfirmNotAtRoot 가 아니라 init 전용 에러로 일관되게 잡는다.
        $action = $this->compileAction($element, true, $path);

        foreach (array_keys($action) as $key) {
            // 위치를 찾는 재컴파일의 소스 위치 표식은 작성자가 쓴 키가 아니다(7.1.0 P16)
            if ($key === LayoutLinter::SOURCE_POSITION_KEY) {
                continue;
            }

            if (! $this->manifest->isInitRootKey($key)) {
                throw $this->error($element, LayoutMarkupErrorCode::InitActionKeyForbidden, $path, ['key' => (string) $key]);
            }
        }

        return $action;
    }

    /**
     * named_actions 항목 하나를 컴파일합니다 (문서 섹션 층 S4 가 `<action name>` 에서 호출).
     *
     * 이름으로 참조돼 `actionRef`/`resultTo` 로 실행되는 재사용 액션이다. `<action name>` 은 본문을
     * 감싸는 봉투이므로 그 본문을 `<on>` 블록과 같은 규칙으로 컴파일한다 — 자식이 하나면 그 액션,
     * 여럿이면 암묵 sequence. 진입점처럼 컴파일해(entry=true) confirm 을 허용한다 — named action 도
     * 이벤트에 직접 연결될 수 있고, init 루트와 달리 축소 화이트리스트를 적용하지 않는다
     * (executeInitActions 를 거치지 않으므로).
     *
     * @param  MarkupElement  $wrapper  `<action name>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 액션이 없거나 표기 규칙을 벗어난 경우
     */
    public function compileNamedAction(MarkupElement $wrapper, ?string $path): array
    {
        return $this->compileBlockBody($wrapper, [], true, $path);
    }

    /**
     * `<error-handling>` 섹션을 에러맵으로 컴파일합니다 (문서·데이터소스 층 S4 가 호출).
     *
     * 노드 층의 액션 부속 `<error-handling>` 과 같은 구조(`{코드: 액션, default: 액션}`)를 최상위
     * `errorHandling` 키·데이터소스 `errorHandling` 키에도 쓴다. 규칙을 복제하지 않고 재사용한다.
     *
     * @param  MarkupElement  $element  `<error-handling>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> `{코드: 액션, default: 액션}` 에러맵
     *
     * @throws CompileError 자식이 `<on-code>`/`<default>` 가 아닌 경우
     */
    public function compileErrorHandlingSection(MarkupElement $element, ?string $path): array
    {
        return $this->compileErrorHandling($element, $path);
    }

    /**
     * `<on listen>` 을 onComponentEvent 항목으로 붙입니다.
     *
     * @param  MarkupElement  $on  `<on>` 요소
     * @param  string  $eventName  구독할 이벤트 이름
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applySubscription(MarkupElement $on, string $eventName, array &$node, ?string $path): void
    {
        $action = $this->compileBlockBody($on, ['event' => $eventName], false, $path);
        $node['onComponentEvent'][] = $action;
    }

    /**
     * `<on event="mount|unmount">` 을 lifecycle 배열로 붙입니다.
     *
     * lifecycle.onMount 는 액션 **배열**이므로 여러 자식을 sequence 로 감싸지 않고 항목으로 편다.
     *
     * @param  MarkupElement  $on  `<on>` 요소
     * @param  string  $phase  `onMount` 또는 `onUnmount`
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyLifecycle(MarkupElement $on, string $phase, array &$node, ?string $path): void
    {
        $actions = $this->compileChildActions($on, false, $path);

        if ($actions === []) {
            throw $this->error($on, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => 'on']);
        }

        foreach ($actions as $action) {
            $node['lifecycle'][$phase][] = $action;
        }
    }

    /**
     * `<on>`/`<case>`/`<when>` 처럼 액션을 담는 블록의 본문을 액션 하나로 만듭니다.
     *
     * 자식이 하나면 그 액션에 트리거를 병합하고, 여럿이면 암묵 sequence 로 감싼다. 이 규칙이
     * 병렬 3형태 중 "암묵 sequence"(같은 이벤트 한 블록 여러 자식)를 표현한다 — fire-and-forget
     * 병렬은 `<on>` 블록 자체를 반복해서 얻는다.
     *
     * @param  MarkupElement  $block  블록 요소
     * @param  array<string, mixed>  $trigger  루트 액션에 병합할 트리거 키(type/event)
     * @param  bool  $rootIsEntry  단일 자식이 이벤트 진입점(confirm 허용)인지
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 액션이 없거나 표기 규칙을 벗어난 경우
     */
    private function compileBlockBody(MarkupElement $block, array $trigger, bool $rootIsEntry, ?string $path): array
    {
        if ($block->children === []) {
            throw $this->error($block, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => $block->name]);
        }

        // 부속 구조 태그(success/body 등)는 핸들러 태그 안에서만 소비된다. 블록 자식으로 오면
        // 액션으로 컴파일을 시도하다 UnknownActionTag 로 걸리므로 조용히 사라지지 않는다.
        $actions = $this->compileChildActions($block, $rootIsEntry && count($block->children) === 1, $path);

        if (count($actions) === 1) {
            return $this->order($trigger + $actions[0]);
        }

        // 여러 자식 — 암묵 sequence. 감싸는 sequence 는 합성이라 트리거만 얹는다.
        return $this->order($trigger + ['handler' => 'sequence', 'actions' => $actions]);
    }

    /**
     * 요소의 자식들을 각각 액션으로 컴파일합니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  bool  $entry  자식이 이벤트 진입점(confirm 허용)인지
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> 컴파일된 액션 배열
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileChildActions(MarkupElement $element, bool $entry, ?string $path): array
    {
        $actions = [];

        foreach ($element->children as $child) {
            $actions[] = $this->compileAction($child, $entry, $path);
        }

        return $actions;
    }

    /**
     * 소스 위치 표식을 켜거나 끕니다 (7.1.0 P16).
     *
     * 켜면 액션 배열마다 `LayoutLinter::SOURCE_POSITION_KEY => [줄, 칸]` 을 싣는다. 위치 없는 lint 경고의
     * 자리를 찾는 재컴파일에서만 켠다 — 저장·반환 JSON 에는 싣지 않는다.
     *
     * @param  bool  $on  켤지 여부
     * @return void 반환값 없음
     */
    public function markSourcePositions(bool $on): void
    {
        $this->markSource = $on;
    }

    /**
     * 액션 요소 하나를 컴파일하고, 표식이 켜졌으면 소스 위치를 싣습니다.
     *
     * @param  MarkupElement  $element  액션 요소
     * @param  bool  $entry  진입점 여부
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileAction(MarkupElement $element, bool $entry, ?string $path): array
    {
        $action = $this->compileActionBody($element, $entry, $path);

        if ($this->markSource && ! array_is_list($action)) {
            $action[LayoutLinter::SOURCE_POSITION_KEY] = [$element->line, $element->column];
        }

        return $action;
    }

    /**
     * 액션 요소 하나를 컴파일합니다 (표식 없는 본체 — {@see compileAction()}).
     *
     * @param  MarkupElement  $element  액션 요소
     * @param  bool  $entry  진입점 여부
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileActionBody(MarkupElement $element, bool $entry, ?string $path): array
    {
        if (isset(self::CONTROL_TAGS[$element->name])) {
            return $this->compileControl($element, $entry, $path);
        }

        if ($element->name === 'action') {
            return $this->compileGenericHandler($element, $entry, $path);
        }

        if ($element->name === 'action-ref') {
            return $this->compileActionRef($element, $path);
        }

        $handler = $this->manifest->resolveTag($element->name);

        if ($handler === null) {
            $suggestion = $this->manifest->suggestTag($element->name);

            throw $suggestion === null
                ? $this->error($element, LayoutMarkupErrorCode::UnknownActionTag, $path, ['tag' => $element->name])
                : $this->error($element, LayoutMarkupErrorCode::UnknownActionTagWithSuggestion, $path, [
                    'tag' => $element->name,
                    'suggestion' => $suggestion,
                ]);
        }

        return $this->compileHandler($element, $handler, [], $entry, $path);
    }

    /**
     * 범용 `<action handler="...">` 를 컴파일합니다 (확장·템플릿·동적 핸들러).
     *
     * @param  MarkupElement  $element  `<action>` 요소
     * @param  bool  $entry  이벤트 진입점인지
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError handler 속성이 없거나 표기 규칙을 벗어난 경우
     */
    /**
     * `<action-ref name="x"/>` 를 named_actions 참조 액션으로 컴파일합니다.
     *
     * `actionRef` 는 핸들러 없이 단독으로 성립하는 유일한 액션 형태다 — 엔진이 이름으로 named_actions
     * 를 찾아 그 액션을 대신 실행한다(ActionDispatcher::resolveActionRef). `<action>` 은 handler 를
     * 필수로 요구하므로 이 형태를 지을 수 없었고, U-ESCAPE 실측에서 38건이 `<json>` 으로 탈출했다.
     *
     * @param  MarkupElement  $element  `<action-ref>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError name 누락·자식 존재 시
     */
    private function compileActionRef(MarkupElement $element, ?string $path): array
    {
        $name = $element->attribute('name')?->value;

        if ($name === null || $name === '') {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => 'action-ref',
                'attr' => 'name',
            ]);
        }

        // 참조는 대상 액션을 그대로 실행할 뿐이라 부속 채널(success/fail 등)을 가질 수 없다.
        foreach ($element->children as $child) {
            throw $this->error($child, LayoutMarkupErrorCode::UnexpectedActionChild, $path, [
                'tag' => $child->name,
                'parent' => 'action-ref',
            ]);
        }

        $action = ['actionRef' => $name];

        if ($element->comment !== null) {
            $action['comment'] = $element->comment;
        }

        return $this->order($action);
    }

    /**
     * `<action handler="이름">` 일반 표기를 액션 객체로 컴파일합니다(확장·동적 핸들러용).
     *
     * @param  MarkupElement  $element  `<action>` 요소
     * @param  bool  $entry  진입점(이벤트에 바로 묶이는 액션) 여부
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileGenericHandler(MarkupElement $element, bool $entry, ?string $path): array
    {
        $handlerAttr = $element->attribute('handler');

        if ($handlerAttr === null || $handlerAttr->value === null || $handlerAttr->value === '') {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => 'action',
                'attr' => 'handler',
            ]);
        }

        return $this->compileHandler($element, $handlerAttr->value, ['handler'], $entry, $path);
    }

    /**
     * 단순 핸들러 액션을 컴파일합니다 (속성 → top-level/params, 부속 구조 태그 → 후속 채널).
     *
     * @param  MarkupElement  $element  핸들러 요소
     * @param  string  $handler  camel 핸들러명
     * @param  array<int, string>  $consumedAttrs  이미 소비한 속성명(범용 `handler` 등)
     * @param  bool  $entry  이벤트 진입점(confirm 허용)인지
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileHandler(
        MarkupElement $element,
        string $handler,
        array $consumedAttrs,
        bool $entry,
        ?string $path,
    ): array {
        $action = ['handler' => $handler];
        $params = [];

        foreach ($element->attributes as $attribute) {
            if (in_array($attribute->name, $consumedAttrs, true)) {
                continue;
            }

            $this->applyActionAttribute($attribute, $handler, $entry, $action, $params, $path);
        }

        foreach ($element->children as $child) {
            if (! in_array($child->name, self::SUB_STRUCTURAL_TAGS, true)) {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedActionChild, $path, [
                    'tag' => $child->name,
                    'parent' => $element->name,
                ]);
            }

            $this->applySubStructural($child, $handler, $action, $params, $path);
        }

        if ($params !== []) {
            $action['params'] = $params;
        }

        if ($element->comment !== null) {
            $action['comment'] = $element->comment;
        }

        return $this->order($action);
    }

    /**
     * 제어 흐름 태그(switch/conditions/sequence/parallel)를 액션으로 컴파일합니다.
     *
     * @param  MarkupElement  $element  제어 태그 요소
     * @param  bool  $entry  이벤트 진입점인지
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 액션 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileControl(MarkupElement $element, bool $entry, ?string $path): array
    {
        $handler = self::CONTROL_TAGS[$element->name];
        $action = ['handler' => $handler];
        $params = [];

        foreach ($element->attributes as $attribute) {
            $this->applyActionAttribute($attribute, $handler, $entry, $action, $params, $path);
        }

        match ($handler) {
            'switch' => $action['cases'] = $this->compileCases($element, $path),
            'conditions' => $action['conditions'] = $this->compileConditionBranches($element, $path),
            'sequence', 'parallel' => $action['actions'] = $this->compileNestedActions($element, $path),
        };

        if ($params !== []) {
            $action['params'] = $params;
        }

        if ($element->comment !== null) {
            $action['comment'] = $element->comment;
        }

        return $this->order($action);
    }

    /**
     * `<switch>` 의 `<case when>`/`<default>` 자식을 cases 맵으로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<switch>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> cases 맵
     *
     * @throws CompileError 자식이 `<case>`/`<default>` 가 아닌 경우
     */
    private function compileCases(MarkupElement $element, ?string $path): array
    {
        $cases = [];

        foreach ($element->children as $child) {
            $key = match ($child->name) {
                'case' => $this->requiredAttribute($child, 'when', $path),
                'default' => 'default',
                default => throw $this->error($child, LayoutMarkupErrorCode::UnexpectedActionChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'switch',
                ]),
            };

            if (array_key_exists($key, $cases)) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateCaseKey, $path, ['case' => $key]);
            }

            $cases[$key] = $this->compileBlockBody($child, [], false, $path);
        }

        if ($cases === []) {
            throw $this->error($element, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => 'switch']);
        }

        return $cases;
    }

    /**
     * `<conditions>` 의 `<when if>`/`<else>` 자식을 브랜치 배열로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<conditions>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> 브랜치 배열
     *
     * @throws CompileError 자식이 `<when>`/`<else>` 가 아닌 경우
     */
    private function compileConditionBranches(MarkupElement $element, ?string $path): array
    {
        $branches = [];

        foreach ($element->children as $child) {
            $branch = [];

            // 분기 주석 — `comment` 는 전 위치 횡단 키다(서빙 strip, 편집 모드 보존).
            if ($child->comment !== null) {
                $branch['comment'] = $child->comment;
            }

            if ($child->name === 'when') {
                $ifAttr = $this->requiredAttribute($child, 'if', $path);
                $ifRaw = $child->attribute('if');
                $branch['if'] = MarkupValue::normalizeCondition(
                    $ifAttr,
                    $ifRaw->line,
                    $ifRaw->column,
                    'if',
                    ':if',
                    $path,
                );
            } elseif ($child->name !== 'else') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedActionChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'conditions',
                ]);
            }

            // `then` 은 엔진이 객체·배열 둘 다 받는다 (ActionDispatcher::handleConditions — 배열이면
            // sequence 처럼 순차 실행, 객체면 단일 실행). 표기 규칙은 `<case>`/`<default>` 의 블록 본문
            // 관용과 같게 둔다 — **자식 하나면 객체, 둘 이상이면 배열**. 전 코퍼스 실측도 객체가 다수형
            // (객체 46 · 배열 16)이라 이 수렴 방향이 소스 파일의 형태 변화를 최소화한다.
            $actions = $this->compileNestedActions($child, $path);
            $branch['then'] = count($actions) === 1 ? $actions[0] : $actions;
            $branches[] = $branch;
        }

        if ($branches === []) {
            throw $this->error($element, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => 'conditions']);
        }

        return $branches;
    }

    /**
     * 요소의 자식 액션들을 배열로 컴파일합니다 (sequence/parallel/then 본문).
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> 액션 배열
     *
     * @throws CompileError 액션이 없거나 표기 규칙을 벗어난 경우
     */
    private function compileNestedActions(MarkupElement $element, ?string $path): array
    {
        $actions = $this->compileChildActions($element, false, $path);

        if ($actions === []) {
            throw $this->error($element, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => $element->name]);
        }

        return $actions;
    }

    /**
     * 부속 구조 태그(success/fail/error-handling/body/query)를 액션에 흡수합니다.
     *
     * @param  MarkupElement  $child  부속 구조 태그
     * @param  string  $handler  camel 핸들러명 (`<field>` 배치 판정용)
     * @param  array<string, mixed>  $action  액션 (참조)
     * @param  array<string, mixed>  $params  params (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applySubStructural(MarkupElement $child, string $handler, array &$action, array &$params, ?string $path): void
    {
        switch ($child->name) {
            case 'success':
                $this->assertActionKeyUnset($child, $action, 'onSuccess', $path);
                $action['onSuccess'] = $this->compileCallbackActions($child, $path);
                break;

            case 'fail':
                $this->assertActionKeyUnset($child, $action, 'onError', $path);
                $action['onError'] = $this->compileCallbackActions($child, $path);
                break;

            case 'error-handling':
                $this->assertActionKeyUnset($child, $action, 'errorHandling', $path);
                $action['errorHandling'] = $this->compileErrorHandling($child, $path);
                break;

            case 'body':
                $this->assertParamUnset($child, $params, 'body', $path);
                $params['body'] = $this->compileFieldMap($child, $path);
                break;

            case 'query':
                $this->assertParamUnset($child, $params, 'query', $path);
                $params['query'] = $this->compileFieldMap($child, $path);
                break;

            case 'actions':
                // `params.actions` 를 실행 목록으로 읽는 비-제어 핸들러 (startInterval — D42 G10).
                // 제어 태그(`<sequence>` 등)는 top-level `actions` 가 정본이라 이 자리에 오지 않는다.
                $this->assertParamUnset($child, $params, 'actions', $path);
                $params['actions'] = $this->compileNestedActions($child, $path);
                break;

            case 'field':
                // 임의 이름의 구조값 (D42 G6) — 본문은 중첩 `<field>` 또는 `<item>` 리스트.
                // `<param>` 은 HTML 표준 void 요소라 자식을 가질 수 없어 개념어 `<field>` 를 재사용한다.
                //
                // 배치는 **속성과 같은 규칙**(D30 ④ 핸들러별 매니페스트)을 탄다 — `resultTo`·
                // `identity_target` 처럼 값이 객체인 top-level 키가 실재하기 때문이다(D51 G15).
                // params 전용으로 두면 그 키들은 마크업 표기가 없어 `:resultTo='{…}'` 통짜 JSON 으로
                // 남는다(D42 가 없애려는 형태). 자리마다 배치 규칙이 갈리지 않도록 매니페스트 한 곳만
                // 본다 — 여기 목록을 따로 두면 속성 배치와 조용히 어긋난다.
                $name = (string) $this->requiredAttribute($child, 'name', $path);

                if ($this->manifest->keyIsTopLevel($handler, $name)) {
                    $this->assertActionKeyUnset($child, $action, $name, $path);
                    $action[$name] = $this->compileParamValue($child, $path);

                    break;
                }

                $this->assertParamUnset($child, $params, $name, $path);
                $params[$name] = $this->compileParamValue($child, $path);
                break;
        }
    }

    /**
     * `<field name>` 액션 파라미터 본문을 구조값으로 컴파일합니다 (D42 G6).
     *
     * 규칙은 `StructuredValueCompiler` 가 단일 SSoT 다 — `<prop>`·데이터소스 자식과 같은 문법을
     * 쓴다(중첩 `<field>` 객체 / `<item>` 리스트 / 문자열 `<item>` 본문).
     *
     * @param  MarkupElement  $element  `<field>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<array-key, mixed> 구조값
     *
     * @throws CompileError 자식 표기가 규칙을 벗어난 경우
     */
    private function compileParamValue(MarkupElement $element, ?string $path): array
    {
        return $this->values->compileChildren($element, $path);
    }

    /**
     * `<success>`/`<fail>` 본문을 onSuccess/onError 값으로 컴파일합니다 (단일 → 객체, 복수 → 배열).
     *
     * @param  MarkupElement  $element  `<success>` 또는 `<fail>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed>|array<int, array<string, mixed>> 액션 또는 액션 배열
     *
     * @throws CompileError 액션이 없거나 표기 규칙을 벗어난 경우
     */
    private function compileCallbackActions(MarkupElement $element, ?string $path): array
    {
        $actions = $this->compileNestedActions($element, $path);

        return count($actions) === 1 ? $actions[0] : $actions;
    }

    /**
     * `<error-handling>` 의 `<on-code code>`/`<default>` 를 에러맵으로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<error-handling>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> `{코드: 액션, default: 액션}` 에러맵
     *
     * @throws CompileError 자식이 `<on-code>`/`<default>` 가 아닌 경우
     */
    private function compileErrorHandling(MarkupElement $element, ?string $path): array
    {
        $map = [];

        foreach ($element->children as $child) {
            $key = match ($child->name) {
                'on-code' => $this->requiredAttribute($child, 'code', $path),
                'default' => 'default',
                default => throw $this->error($child, LayoutMarkupErrorCode::UnexpectedActionChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'error-handling',
                ]),
            };

            if (array_key_exists($key, $map)) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateCaseKey, $path, ['case' => $key]);
            }

            $map[$key] = $this->compileBlockBody($child, [], false, $path);
        }

        if ($map === []) {
            throw $this->error($element, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => 'error-handling']);
        }

        return $map;
    }

    /**
     * `<body>`/`<query>` 속성을 평면 키-값 객체로 컴파일합니다.
     *
     * 속성이 하나도 없으면 {@see EmptyMap} 표식을 돌려준다 — `<query/>`·`<body/>` 는 **정의상
     * 이름표 붙은 값 묶음**이고, 빈 PHP 배열로 두면 산출 JSON 이 `[]` 가 되어 서버측 검증이
     * 대상을 찾지 못한다(422 또는 침묵 미적용). 프론트도 `Object.entries` 대상과 목록을 구분한다.
     *
     * @param  MarkupElement  $element  `<body>` 또는 `<query>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed>|EmptyMap 키-값 객체 (속성이 없으면 빈 map 표식)
     *
     * @throws CompileError 자식이 있거나 값 표기가 규칙을 벗어난 경우
     */
    private function compileFieldMap(MarkupElement $element, ?string $path): array|EmptyMap
    {
        if ($element->children !== []) {
            throw $this->error($element, LayoutMarkupErrorCode::UnexpectedActionChild, $path, [
                'tag' => $element->children[0]->name,
                'parent' => $element->name,
            ]);
        }

        $map = [];

        foreach ($element->attributes as $attribute) {
            $key = $attribute->name;
            $value = str_starts_with($key, ':')
                ? MarkupValue::decodeTypeLiteral($attribute, substr($key, 1), $path)
                : ($attribute->isBoolean() ? true : $attribute->value);
            $key = ltrim($key, ':');

            if (array_key_exists($key, $map)) {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                    'tag' => $element->name,
                    'attr' => $key,
                ]);
            }

            $map[$key] = $value;
        }

        return $map === [] ? EmptyMap::instance() : $map;
    }

    /**
     * 핸들러 속성 하나를 액션 top-level 또는 params 로 배치합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $handler  camel 핸들러명
     * @param  bool  $entry  이벤트 진입점(confirm 허용)인지
     * @param  array<string, mixed>  $action  액션 (참조)
     * @param  array<string, mixed>  $params  params (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyActionAttribute(
        MarkupAttribute $attribute,
        string $handler,
        bool $entry,
        array &$action,
        array &$params,
        ?string $path,
    ): void {
        $typed = str_starts_with($attribute->name, ':');
        $key = ltrim($attribute->name, ':');

        if ($key === '') {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, ['found' => $attribute->name]);
        }

        $value = $typed
            ? MarkupValue::decodeTypeLiteral($attribute, $key, $path)
            : ($attribute->isBoolean() ? true : $attribute->value);

        // params 별칭(`<switch on>` → `params.value`) — 같은 이름의 top-level 키와 가르는 표기다 (D42 G8).
        $alias = $this->manifest->paramAliasFor($handler, $key);

        if ($alias !== null) {
            $key = $alias;
        } elseif ($this->manifest->keyIsTopLevel($handler, $key)) {
            $this->applyTopLevelKey($attribute, $key, $value, $typed, $entry, $action, $path);

            return;
        }

        if (array_key_exists($key, $params)) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                'tag' => $attribute->name,
                'attr' => $key,
            ]);
        }

        $params[$key] = $value;
    }

    /**
     * 액션 top-level 키를 배치합니다 (if 정규화·confirm 진입점 검사 포함).
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $key  top-level 키명
     * @param  mixed  $value  값
     * @param  bool  $typed  `:` 타입 리터럴 표기 여부
     * @param  bool  $entry  이벤트 진입점(confirm 허용)인지
     * @param  array<string, mixed>  $action  액션 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 중복·confirm 오배치·혼합 조건식인 경우
     */
    private function applyTopLevelKey(
        MarkupAttribute $attribute,
        string $key,
        mixed $value,
        bool $typed,
        bool $entry,
        array &$action,
        ?string $path,
    ): void {
        if (array_key_exists($key, $action)) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                'tag' => $attribute->name,
                'attr' => $key,
            ]);
        }

        if ($key === 'confirm' && ! $entry) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::ConfirmNotAtRoot, $path);
        }

        if ($key === 'if' && ! $typed) {
            $action['if'] = MarkupValue::normalizeCondition($attribute->value ?? '', $attribute->line, $attribute->column, 'if', ':if', $path);

            return;
        }

        $action[$key] = $value;
    }

    /**
     * 액션 top-level 키가 아직 비어 있는지 확인합니다.
     *
     * @param  MarkupElement  $element  부속 구조 태그
     * @param  array<string, mixed>  $action  액션
     * @param  string  $key  키명
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이미 지정된 키인 경우
     */
    private function assertActionKeyUnset(MarkupElement $element, array $action, string $key, ?string $path): void
    {
        if (array_key_exists($key, $action)) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateActionKey, $path, ['key' => $key]);
        }
    }

    /**
     * params 키가 아직 비어 있는지 확인합니다.
     *
     * @param  MarkupElement  $element  부속 구조 태그
     * @param  array<string, mixed>  $params  params
     * @param  string  $key  키명
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이미 지정된 키인 경우 (속성과 태그가 같은 자리를 다툼)
     */
    private function assertParamUnset(MarkupElement $element, array $params, string $key, ?string $path): void
    {
        if (array_key_exists($key, $params)) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateActionKey, $path, ['key' => $key]);
        }
    }

    /**
     * 필수 속성을 읽습니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string  $name  속성명
     * @param  string|null  $path  소스 파일 경로
     * @return string 속성값
     *
     * @throws CompileError 속성이 없는 경우
     */
    private function requiredAttribute(MarkupElement $element, string $name, ?string $path): string
    {
        $attribute = $element->attribute($name);

        if ($attribute === null || $attribute->value === null) {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => $element->name,
                'attr' => $name,
            ]);
        }

        return $attribute->value;
    }

    /**
     * 액션 키를 고정 순서로 정렬합니다.
     *
     * @param  array<string, mixed>  $action  액션
     * @return array<string, mixed> 정렬된 액션
     */
    private function order(array $action): array
    {
        $ordered = [];

        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $action)) {
                $ordered[$key] = $action[$key];
            }
        }

        return $ordered + $action;
    }

    /**
     * 요소 위치의 컴파일 오류를 만듭니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  LayoutMarkupErrorCode  $code  에러 코드
     * @param  string|null  $path  소스 파일 경로
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return CompileError 컴파일 오류
     */
    private function error(MarkupElement $element, LayoutMarkupErrorCode $code, ?string $path, array $params = []): CompileError
    {
        return new CompileError($code, $element->line, $element->column, $params, $path);
    }

    /**
     * 속성 위치의 컴파일 오류를 만듭니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  LayoutMarkupErrorCode  $code  에러 코드
     * @param  string|null  $path  소스 파일 경로
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return CompileError 컴파일 오류
     */
    private function attributeError(MarkupAttribute $attribute, LayoutMarkupErrorCode $code, ?string $path, array $params = []): CompileError
    {
        return new CompileError($code, $attribute->line, $attribute->column, $params, $path);
    }
}
