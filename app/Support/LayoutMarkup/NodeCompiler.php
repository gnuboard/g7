<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 요소 트리 하나를 JSON 레이아웃 **노드**로 컴파일하는 층 (매핑 사전 C).
 *
 * 이 층이 확정하는 것은 세 가지다.
 *
 * **① 속성 3분할.** 저작자가 쓴 속성은 프리픽스만으로 갈린다 — 무접두는 컴포넌트 props,
 * `data-*` 예약어는 엔진 지시자(노드 키), `:attr` 은 타입 리터럴. 이 분할 덕에 "브라우저가
 * 아는 속성 / 우리 엔진이 아는 지시자 / 타입 강제" 가 눈으로 구분된다.
 *
 * **② props 는 검증하지 않고 원문 통과.** components.json 의 prop 선언은 화이트리스트가
 * 아니라 힌트다 — 실측상 선언 커버리지가 75~77% 이고 미선언 목록 상위가 `Button.type`·
 * `Input.value` 같은 정상 props 다. 화이트리스트로 강제하면 정상 저작 7천여 건이 거부된다.
 * 런타임도 props 를 검증하지 않고 그대로 넘긴다(레지스트리는 `name` 만 해석).
 *
 * **③ 표현식은 건드리지 않는다.** `{{...}}`/`$t:` 는 전 위치 무변환 통과다(계획 D3). 컴파일러가
 * 표현식에 관여하는 지점은 `data-if` 혼합형 판정 하나뿐이다. 선평가를 하지 않으므로
 * skipBindingKeys 패리티는 자동으로 성립한다 — 애초에 풀지 않는 값은 잘못 풀릴 수 없다.
 *
 * 액션(`<on>`)·임베딩 hoist(`<cell>`·`<responsive>` 등)·문서 루트(`<layout>`·`<partial>`)는
 * 이 층의 책임이 아니다(각각 S3·S5·S4). 노드 위치에서 만나면 그 사실을 알려 주고 거부한다.
 */
final class NodeCompiler
{
    /**
     * 엔진 지시 속성 — 여러 컴포넌트에 공통으로 얹히는 노드 지시자만 `data-` 를 쓴다.
     *
     * 값 타입을 키별로 **고정**한다. `data-slot-order="2"` 가 문자열 `"2"` 로 방출되면 JSON 이
     * 달라지므로(실사용은 정수 31건), 타입이 하나로 정해지는 지시자는 저작자가 `:` 를 붙이지
     * 않아도 그 타입으로 읽는다. 반대로 값 타입이 진짜 둘인 지시자(`data-if` 는 표현식 문자열
     * 또는 불리언)만 `:` 타입 리터럴을 허용한다.
     *
     * 목적 전용 태그의 고유 속성(`<variant when>`·`<prop name>`)은 태그명이 이미 네임스페이스를
     * 확정하므로 `data-` 를 붙이지 않는다.
     *
     * @var array<string, array{key: string, type: string}>
     */
    private const DIRECTIVES = [
        'data-ref' => ['key' => 'id', 'type' => 'string'],
        'data-if' => ['key' => 'if', 'type' => 'expression'],
        'data-each' => ['key' => 'iteration', 'type' => 'each'],
        'data-slot' => ['key' => 'slot', 'type' => 'string'],
        'data-slot-order' => ['key' => 'slotOrder', 'type' => 'integer'],
        'data-key' => ['key' => 'dataKey', 'type' => 'string'],
        'data-track-changes' => ['key' => 'trackChanges', 'type' => 'boolean'],
        'data-debounce' => ['key' => 'debounce', 'type' => 'integer'],
        'data-blur-until-loaded' => ['key' => 'blur_until_loaded', 'type' => 'expression'],
    ];

    /**
     * HTML 과 React 의 예약어 충돌을 피하려고 이름이 갈린 두 속성.
     *
     * 이 둘 말고는 속성명을 손대지 않는다 — G7 props 는 camelCase·snake_case 가 섞여 있어
     * 규칙적 변환을 걸면 어느 쪽이든 원문을 잃는다.
     *
     * @var array<string, string>
     */
    private const ATTRIBUTE_ALIASES = ['class' => 'className', 'for' => 'htmlFor'];

    /**
     * HTML5 불리언 속성 — 참은 **존재**, 거짓은 **생략**으로 표기한다(W3C).
     *
     * `required="false"` 는 표준상 참이므로(값을 보지 않는다) 저작자의 의도와 정반대가 된다.
     * 리터럴 값을 정말 전달하려면 `:required="false"` 를 쓴다.
     *
     * G7 개념과 겹치는 표준 이름(`default`·`loop`)은 뺐다 — `<class-map default>` 처럼 문자열
     * 값을 받는 자리가 있어 불리언으로 단정하면 정상 저작을 막는다.
     *
     * @var array<int, string>
     */
    private const BOOLEAN_ATTRIBUTES = [
        'allowfullscreen', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'defer',
        'disabled', 'formnovalidate', 'inert', 'ismap', 'itemscope', 'multiple', 'muted',
        'nomodule', 'novalidate', 'open', 'playsinline', 'readonly', 'required', 'reversed',
        'selected',
    ];

    /**
     * 다른 프레임워크·과거 표기 → G7 문법 안내.
     *
     * HTML 유사 문법은 학습 분포상 Vue/JSX 관용구를 반사적으로 쓰게 되는 지대다. 조용히
     * props 로 통과시키면 `v-if` 가 화면에 아무 영향도 주지 않은 채 사라진다.
     *
     * `slot` 은 일부러 뺐다 — HTML5 전역 속성이면서 **props 로 실사용 4건**(sirsoft-board 의
     * Dropdown trigger/content 배치)이라, 막으면 정상 저작이 거부된다. 노드 레벨 슬롯은
     * `data-slot` 이고 `slot=` 은 props 다 — 속성 3분할이 정확히 이 구분을 위해 있다.
     *
     * @var array<string, string>
     */
    private const FOREIGN_IDIOMS = [
        'v-if' => 'data-if', 'v-show' => 'data-if', 'v-else' => 'data-if', 'v-else-if' => 'data-if',
        'v-for' => 'data-each', 'v-model' => 'data-key', 'v-bind' => ':attr', 'v-html' => 'text',
        'x-if' => 'data-if', 'x-for' => 'data-each', 'x-show' => 'data-if', 'x-data' => 'data-key',
        '*ngif' => 'data-if', '*ngfor' => 'data-each',
        'if' => 'data-if', 'each' => 'data-each', 'ref' => 'data-ref',
    ];

    /**
     * JSON 노드 키 이름을 무접두 속성으로 쓴 표기 → 올바른 표기 안내.
     *
     * 옛 JSON 을 손으로 옮기는 저작자는 노드 키를 그대로 속성에 적기 쉽다(`condition="{{x}}"`).
     * 무접두는 props 규칙이라 조용히 통과하는데, 엔진은 **노드 키**만 읽으므로 조건이 아예 걸리지
     * 않은 노드가 나온다 — 결함 인벤토리의 "노드 condition bare 식 → 항상 렌더" 와 같은 지대다.
     *
     * 목록은 전 번들 레이아웃의 props 이름과 매니페스트 prop 선언을 전수 대조해, **props 로 쓰이는
     * 이름이 하나도 없는 노드 키**만 넣었다. `id`·`type`·`name`·`text`·`style`·`actions`·`slot`·
     * `responsive`·`sortable`·`children` 은 정상 props 로 실사용되므로 뺐다 — 막으면 D19 와 같은
     * 실수(정상 저작 거부)가 된다. `dataKey`/`debounce` 는 props 위치 실사용이 있으나 그쪽이
     * 정규화 대상 이형이므로(계획 "입력만 받고 정규화") 노드 키 표기로 안내한다.
     *
     * @var array<string, string>
     */
    private const NODE_KEY_ATTRIBUTES = [
        'condition' => 'data-if',
        'conditions' => '<all-of>/<any-of>',
        'iteration' => 'data-each',
        'comment' => '<!-- -->',
        'classMap' => '<class-map>',
        'blur_until_loaded' => 'data-blur-until-loaded',
        'isolatedState' => '<isolated-state>',
        'isolatedScopeId' => '<isolated-state scope-id="...">',
        'dataKey' => 'data-key',
        'trackChanges' => 'data-track-changes',
        'debounce' => 'data-debounce',
        'slotOrder' => 'data-slot-order',
    ];

    /**
     * G7 문법의 태그이지만 이 층이 아직 다루지 않는 것 → 담당 표기 안내.
     *
     * 이 목록이 없으면 오타 제안이 엉뚱한 컴포넌트를 짚는다 — `<on>` 은 편집 거리 1 인 `<ol>` 을,
     * `<cell>` 은 `<ul>` 을 "쓰려던 것 아닌가요?" 로 제안한다. G7 어휘를 오타로 취급하는 셈이라
     * 저작자를 더 헷갈리게 만든다.
     *
     * 상위 단계가 해당 태그를 구현하면 **여기서 지운다**. 잊더라도 그 단계의 컴파일 테스트가
     * 이 에러로 실패하므로 목록이 조용히 낡지 않는다.
     *
     * 값은 표기 갈래의 **다국어 키**다(`exceptions.layout_markup.tag_owner.{값}`). 한국어 낱말을
     * 그대로 두면 일본어·영어 메시지 한가운데에 한국어가 박힌다.
     *
     * 여기 없는 이월 태그가 셋 있다 — `<modal>`·`<option>`·`<card>`. 레지스트리에 같은 이름의
     * 컴포넌트(Modal/Option/Card)가 있어 태그 해석이 **먼저** 성공하므로 이 목록에 넣어도 죽은
     * 항목이 된다. 이름이 겹친다는 것 자체가 상위 단계의 숙제다: 같은 태그가 문서 섹션(`<modal src>`)
     * 이냐 컴포넌트냐, CardGrid 셀 hoist 냐 Card 컴포넌트냐를 **위치로** 갈라야 한다.
     *
     * 액션 태그(`<on>`·`<switch>` 등)는 S3 에서 구현되어 여기 없다 — 이벤트 컨텍스트 전용이므로
     * 노드 자리에 오면 ACTION_ONLY_TAGS 가 별도 에러로 잡는다.
     *
     * @var array<string, string>
     */
    private const DEFERRED_TAGS = [
        'cell' => 'embedding', 'column' => 'embedding', 'expand' => 'embedding', 'sub-row' => 'embedding',
        'footer-cell' => 'embedding', 'footer-card' => 'embedding', 'title-prefix' => 'embedding',
        'responsive' => 'embedding', 'item-template' => 'embedding', 'default' => 'embedding',
        'callback' => 'embedding', 'switch-value' => 'embedding',
        'from-source' => 'document_section', 'seo' => 'document_section', 'data-source' => 'document_section',
        'computed' => 'document_section', 'init' => 'document_section', 'init-local' => 'document_section',
        'init-global' => 'document_section', 'init-isolated' => 'document_section',
        'include' => 'document_section', 'inject' => 'document_section', 'extension-point' => 'document_section',
    ];

    /**
     * 이벤트 컨텍스트(`<on>` 안) 전용 태그 — 컴포넌트(노드) 자리에 오면 문서 구조를 잘못 이해한 것.
     *
     * `<on>` 은 부모의 child 루프가 먼저 가로채 액션 컴파일러로 넘기므로 여기 목록은 그 태그들이
     * **잘못된 자리**(직속 컴포넌트 자식이거나 루트)에 왔을 때의 안내를 담당한다. `<on>` 자신도
     * 루트로 직접 컴파일되면 이 목록에 걸린다.
     *
     * @var array<int, string>
     */
    private const ACTION_ONLY_TAGS = [
        'on', 'switch', 'case', 'conditions', 'when', 'else', 'parallel', 'sequence',
        'success', 'fail', 'error-handling', 'on-code', 'body', 'query', 'action',
    ];

    /**
     * 표준 속성이지만 G7 이 쓰지 않는 것.
     *
     * `is` 는 customized built-in element(`<button is="my-button">`) 용이라 커스텀 엘리먼트로
     * 태그를 해석하는 이 포맷의 모델과 충돌한다(계획 D9 의 유일한 통과 예외).
     *
     * @var array<int, string>
     */
    private const REJECTED_ATTRIBUTES = ['is'];

    /** 문서 루트 전용 태그 — 노드 위치에 오면 문서 구조를 잘못 이해한 것이다 (S4 소관) */
    private const ROOT_TAGS = ['layout', 'partial', 'extension', 'override'];

    /** 노드 키를 나르는 구조 태그 — 컴포넌트 자식이 아니라 부모 노드의 키가 된다 */
    private const STRUCTURAL_TAGS = ['class-map', 'isolated-state', 'blur-until-loaded', 'prop', 'all-of', 'any-of'];

    /** 구조 태그 안에서만 유효한 태그 → 부모 태그명 */
    private const NESTED_ONLY_TAGS = [
        'variant' => 'class-map',
        'field' => 'isolated-state',
        'expr' => 'all-of',
    ];

    /**
     * JSON 노드의 키 순서 — 결정적 직렬화(diff·골든 테스트 성립 조건)를 위해 고정한다.
     *
     * @var array<int, string>
     */
    private const KEY_ORDER = [
        'comment', 'id', 'type', 'name', 'if', 'conditions', 'iteration', 'slot', 'slotOrder',
        'dataKey', 'trackChanges', 'debounce', 'classMap', 'blur_until_loaded',
        'isolatedState', 'isolatedScopeId', 'lifecycle', 'onComponentEvent', 'actions',
        'props', 'text', 'children',
    ];

    /** 액션 컴파일러 — `<on>` 블록을 노드의 actions/lifecycle/onComponentEvent 키로 흡수 (S3) */
    private readonly ActionCompiler $actions;

    /**
     * @param  ComponentRegistry  $registry  대상 템플릿의 컴포넌트 레지스트리
     */
    public function __construct(private readonly ComponentRegistry $registry)
    {
        $this->actions = new ActionCompiler;
    }

    /**
     * 요소 하나를 JSON 노드로 컴파일합니다.
     *
     * @param  MarkupElement  $element  트리 빌더가 만든 요소
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return array<string, mixed> JSON 노드
     *
     * @throws CompileError 문법·표기 규칙을 위반한 경우
     */
    public function compile(MarkupElement $element, ?string $path = null): array
    {
        $definition = $this->resolveComponent($element, $path);

        $node = ['type' => $definition->type, 'name' => $definition->name];
        $props = [];

        foreach ($element->attributes as $attribute) {
            $this->applyAttribute($attribute, $element, $node, $props, $path);
        }

        $children = [];

        foreach ($element->children as $child) {
            if ($child->name === 'on') {
                $this->actions->applyOn($child, $node, $path);

                continue;
            }

            if (in_array($child->name, self::STRUCTURAL_TAGS, true)) {
                $this->applyStructuralChild($child, $node, $props, $path);

                continue;
            }

            if (isset(self::NESTED_ONLY_TAGS[$child->name])) {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => self::NESTED_ONLY_TAGS[$child->name],
                ]);
            }

            $children[] = $this->compile($child, $path);
        }

        if ($props !== []) {
            $node['props'] = $props;
        }

        // 구조 태그를 걷어낸 뒤에야 진짜 혼재를 안다 — `<span><class-map/>글자</span>` 는 정상이고
        // `<div>글자<span/></div>` 만 혼재다. JSON 노드에는 text 와 children 이 함께 설 자리가 없다.
        if ($element->text !== null && $children !== []) {
            throw $this->error($element, LayoutMarkupErrorCode::MixedTextAndChildren, $path, [
                'tag' => $element->name,
                'found' => $this->preview($element->text),
            ]);
        }

        if ($children !== []) {
            $node['children'] = $children;
        } elseif ($element->text !== null) {
            $node['text'] = $element->text;
        }

        if ($element->comment !== null) {
            $node['comment'] = $element->comment;
        }

        return $this->order($node);
    }

    /**
     * 태그를 레지스트리 컴포넌트로 해석합니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return ComponentDefinition 컴포넌트 정의
     *
     * @throws CompileError 루트 전용 태그이거나 레지스트리에 없는 태그인 경우
     */
    private function resolveComponent(MarkupElement $element, ?string $path): ComponentDefinition
    {
        if (in_array($element->name, self::ROOT_TAGS, true)) {
            throw $this->error($element, LayoutMarkupErrorCode::RootTagInNodePosition, $path, ['tag' => $element->name]);
        }

        if (in_array($element->name, self::ACTION_ONLY_TAGS, true)) {
            throw $this->error($element, LayoutMarkupErrorCode::ActionTagInNodePosition, $path, ['tag' => $element->name]);
        }

        if (in_array($element->name, self::STRUCTURAL_TAGS, true) || isset(self::NESTED_ONLY_TAGS[$element->name])) {
            throw $this->error($element, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                'tag' => $element->name,
                'parent' => self::NESTED_ONLY_TAGS[$element->name] ?? 'component',
            ]);
        }

        $definition = $this->registry->resolveTag($element->name);

        if ($definition !== null) {
            return $definition;
        }

        // 오타 제안보다 먼저 본다 — G7 어휘를 오타로 취급해 엉뚱한 컴포넌트를 짚지 않도록.
        if (isset(self::DEFERRED_TAGS[$element->name])) {
            throw $this->error($element, LayoutMarkupErrorCode::TagNotYetSupported, $path, [
                'tag' => $element->name,
                'owner' => __('exceptions.layout_markup.tag_owner.'.self::DEFERRED_TAGS[$element->name]),
            ]);
        }

        $suggestion = $this->registry->suggestTag($element->name);

        throw $suggestion === null
            ? $this->error($element, LayoutMarkupErrorCode::UnknownTag, $path, [
                'tag' => $element->name,
                'template' => $this->registry->templateId,
            ])
            : $this->error($element, LayoutMarkupErrorCode::UnknownTagWithSuggestion, $path, [
                'tag' => $element->name,
                'suggestion' => $suggestion,
            ]);
    }

    /**
     * 속성 하나를 노드 키 또는 props 로 배치합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  MarkupElement  $element  속성이 실린 요소
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  array<string, mixed>  $props  props (참조)
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 표기 규칙을 위반한 경우
     */
    private function applyAttribute(
        MarkupAttribute $attribute,
        MarkupElement $element,
        array &$node,
        array &$props,
        ?string $path,
    ): void {
        $name = $attribute->name;

        if (str_starts_with($name, ':')) {
            $bare = substr($name, 1);

            if ($bare === '') {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, ['found' => $name]);
            }

            $value = $this->decodeTypeLiteral($attribute, $path);

            if (isset(self::DIRECTIVES[$bare])) {
                $this->applyDirective($attribute, $bare, $value, true, $node, $path);

                return;
            }

            $this->assignProp(
                $props,
                self::ATTRIBUTE_ALIASES[$bare] ?? $bare,
                $value,
                $element->name,
                $attribute->line,
                $attribute->column,
                $path,
            );

            return;
        }

        if (isset(self::DIRECTIVES[$name])) {
            $this->applyDirective($attribute, $name, $attribute->value, false, $node, $path);

            return;
        }

        $this->rejectForeignIdiom($attribute, $path);
        $this->rejectNodeKeyAttribute($attribute, $path);
        $this->rejectUnsupportedAttribute($attribute, $path);

        // 예약되지 않은 `data-*` 는 진짜 DOM data 속성이다(`data-testid`·`data-drag-handle` 등
        // 실사용 6종). 예약 목록과 겹치지 않으므로 props 로 그대로 넘긴다.
        $key = self::ATTRIBUTE_ALIASES[$name] ?? $name;

        if ($attribute->isBoolean()) {
            $this->assignProp($props, $key, true, $element->name, $attribute->line, $attribute->column, $path);

            return;
        }

        $this->rejectBooleanLiteral($attribute, $path);
        $this->assignProp($props, $key, $attribute->value, $element->name, $attribute->line, $attribute->column, $path);
    }

    /**
     * props 하나를 배치하되 이미 찬 자리를 덮어쓰지 않게 막습니다.
     *
     * 토크나이저의 중복 속성 검사는 **같은 이름**만 본다. 같은 자리를 이름이 다른 표기가 다투는
     * 경로가 셋이므로 여기서 한 번에 막는다 — 별칭(`class`+`className`, `for`+`htmlFor`),
     * 타입 리터럴(`data`+`:data`), 그리고 `<prop name="className">` 처럼 자식 태그로 오는 값.
     * 막지 않으면 나중 것이 앞엣것을 조용히 지우는데, 저작자는 두 값이 다 반영될 것으로 기대한다.
     *
     * @param  array<string, mixed>  $props  props (참조)
     * @param  string  $key  배치할 props 키
     * @param  mixed  $value  값
     * @param  string  $tag  값이 실린 태그명 (오류 메시지용)
     * @param  int  $line  오류 좌표 라인
     * @param  int  $column  오류 좌표 컬럼
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 같은 자리를 두 표기가 다투는 경우
     */
    private function assignProp(
        array &$props,
        string $key,
        mixed $value,
        string $tag,
        int $line,
        int $column,
        ?string $path,
    ): void {
        if (array_key_exists($key, $props)) {
            throw new CompileError(
                LayoutMarkupErrorCode::DuplicateAttribute,
                $line,
                $column,
                ['tag' => $tag, 'attr' => $key],
                $path,
            );
        }

        $props[$key] = $value;
    }

    /**
     * 지시 속성을 노드 키로 방출합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $name  지시 속성명
     * @param  mixed  $value  값 (타입 리터럴이면 디코드된 값, 아니면 원문 문자열 또는 null)
     * @param  bool  $typed  `:` 타입 리터럴 표기 여부
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 지시 속성의 값 표기가 규칙을 벗어난 경우
     */
    private function applyDirective(
        MarkupAttribute $attribute,
        string $name,
        mixed $value,
        bool $typed,
        array &$node,
        ?string $path,
    ): void {
        $spec = self::DIRECTIVES[$name];

        if (isset($node[$spec['key']])) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => $spec['key']]);
        }

        // 값 타입이 하나로 정해지는 지시자에 `:` 를 허용하면 같은 뜻을 두 가지로 쓰게 된다.
        if ($typed && $spec['type'] !== 'expression') {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DirectiveRejectsTypeLiteral, $path, [
                'attr' => $name,
                'suggestion' => $name,
            ]);
        }

        if ($spec['type'] === 'boolean') {
            if (! $attribute->isBoolean()) {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::BooleanAttributeFalse, $path, ['attr' => $name]);
            }

            $node[$spec['key']] = true;

            return;
        }

        if (! $typed && $attribute->isBoolean()) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DirectiveNeedsValue, $path, ['attr' => $name]);
        }

        $node[$spec['key']] = match ($spec['type']) {
            'string' => (string) $value,
            'integer' => $this->toInteger($attribute, $name, (string) $value, $path),
            'each' => $this->parseEach($attribute, (string) $value, $path),
            'expression' => $typed ? $value : $this->normalizeExpression($attribute, (string) $value, $name, $path),
        };
    }

    /**
     * 구조 태그 자식을 노드 키 또는 props 로 흡수합니다.
     *
     * @param  MarkupElement  $child  구조 태그 요소
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  array<string, mixed>  $props  props (참조)
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 구조 태그 표기가 규칙을 벗어난 경우
     */
    private function applyStructuralChild(MarkupElement $child, array &$node, array &$props, ?string $path): void
    {
        switch ($child->name) {
            case 'class-map':
                $this->assertNotDuplicated($child, $node, 'classMap', $path);
                $node['classMap'] = $this->compileClassMap($child, $path);
                break;

            case 'isolated-state':
                $this->assertNotDuplicated($child, $node, 'isolatedState', $path);
                $node['isolatedState'] = (new StateTreeCompiler)->compile($child, $path);
                $scopeId = $child->attribute('scope-id');

                if ($scopeId !== null) {
                    $node['isolatedScopeId'] = (string) $scopeId->value;
                }
                break;

            case 'blur-until-loaded':
                $this->assertNotDuplicated($child, $node, 'blur_until_loaded', $path);
                $node['blur_until_loaded'] = $this->compileBlur($child, $path);
                break;

            case 'prop':
                $name = $this->requiredAttribute($child, 'name', $path);
                $this->assignProp(
                    $props,
                    self::ATTRIBUTE_ALIASES[$name] ?? $name,
                    $this->decodeJsonBody($child, $path),
                    $child->name,
                    $child->line,
                    $child->column,
                    $path,
                );
                break;

            case 'all-of':
            case 'any-of':
                $this->assertNotDuplicated($child, $node, 'conditions', $path);
                $node['conditions'] = $this->compileConditions($child, $path);
                break;
        }
    }

    /**
     * `<class-map>` 을 classMap 구조체로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<class-map>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> classMap 구조체
     *
     * @throws CompileError 필수 속성이 없거나 자식이 `<variant>` 가 아닌 경우
     */
    private function compileClassMap(MarkupElement $element, ?string $path): array
    {
        $classMap = [];
        $base = $element->attribute('base');

        if ($base !== null) {
            $classMap['base'] = (string) $base->value;
        }

        $classMap['key'] = $this->requiredAttribute($element, 'key', $path);
        $variants = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'variant') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'class-map',
                ]);
            }

            $variants[$this->requiredAttribute($child, 'when', $path)] = (string) ($child->text ?? '');
        }

        $classMap['variants'] = $variants;
        $default = $element->attribute('default');

        if ($default !== null) {
            $classMap['default'] = (string) $default->value;
        }

        return $classMap;
    }

    /**
     * `<blur-until-loaded>` 을 객체형 blur_until_loaded 로 컴파일합니다.
     *
     * 불리언·표현식 축약은 `data-blur-until-loaded` 속성이 맡고, 이 태그는 `{enabled, data_sources}`
     * 객체형 전용이다. 데이터소스가 하나면 `sources="a"`, 여럿이면 `:sources='["a","b"]'` 로
     * 쓴다 — 실사용은 문자열 18건·배열 6건이라 두 shape 를 모두 보존해야 한다.
     *
     * @param  MarkupElement  $element  `<blur-until-loaded>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> blur_until_loaded 구조체
     *
     * @throws CompileError 필수 속성이 없는 경우
     */
    private function compileBlur(MarkupElement $element, ?string $path): array
    {
        $enabled = $element->attribute(':enabled');
        $plain = $element->attribute('sources');
        $typed = $element->attribute(':sources');

        // 둘 다 쓰면 어느 쪽이 데이터소스인지 알 수 없다. 하나를 골라 나머지를 버리면
        // 저작자가 쓴 값이 조용히 사라진다.
        if ($plain !== null && $typed !== null) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                'tag' => $element->name,
                'attr' => 'sources',
            ]);
        }

        if ($plain === null && $typed === null) {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => $element->name,
                'attr' => 'sources',
            ]);
        }

        return [
            'enabled' => $enabled === null ? true : $this->decodeTypeLiteral($enabled, $path),
            'data_sources' => $plain !== null
                ? (string) $plain->value
                : $this->decodeTypeLiteral($typed, $path),
        ];
    }

    /**
     * `<all-of>`/`<any-of>` 를 conditions 구조체로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<all-of>` 또는 `<any-of>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> conditions 구조체
     *
     * @throws CompileError 자식이 `<expr>`·`<all-of>`·`<any-of>` 가 아닌 경우
     */
    private function compileConditions(MarkupElement $element, ?string $path): array
    {
        $operator = $element->name === 'all-of' ? 'and' : 'or';
        $operands = [];

        foreach ($element->children as $child) {
            $operands[] = match ($child->name) {
                'expr' => (string) ($child->text ?? ''),
                'all-of', 'any-of' => $this->compileConditions($child, $path),
                default => throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => $element->name,
                ]),
            };
        }

        return [$operator => $operands];
    }

    /**
     * `data-each` DSL 을 iteration 구조체로 파싱합니다.
     *
     * `아이템[, 인덱스] in 소스` — 소스는 **원문 그대로** 싣는다. 엔진은 `{{}}` 래핑·bare 경로·
     * bare 복합식 세 표기를 동등하게 처리하고 번들 실사용도 반씩 갈리므로(래핑 252 / bare 254),
     * 한쪽으로 정규화하면 왕복이 그만큼 깨진다.
     *
     * @param  MarkupAttribute  $attribute  `data-each` 속성
     * @param  string  $value  DSL 원문
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, string> iteration 구조체
     *
     * @throws CompileError DSL 형식을 벗어난 경우
     */
    private function parseEach(MarkupAttribute $attribute, string $value, ?string $path): array
    {
        $pattern = '/^\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:,\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*)?\bin\s+(\S.*?)\s*$/s';

        if (preg_match($pattern, $value, $matches) !== 1) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidEachSyntax, $path, ['found' => $value]);
        }

        $iteration = ['source' => $matches[3], 'item_var' => $matches[1]];

        if (($matches[2] ?? '') !== '') {
            $iteration['index_var'] = $matches[2];
        }

        return $iteration;
    }

    /**
     * 조건 표현식을 정규화합니다.
     *
     * `{{}}` 가 없으면 전체를 감싼다 — 감싸지 않은 식은 엔진이 문자열 보간으로 처리해 **항상 참**이
     * 되는 함정이라(비어 있지 않은 문자열은 truthy) 저작 의도와 정반대로 동작한다.
     *
     * 반대로 `{{}}` 가 값의 **일부만** 감싼 혼합형(`{{x}} === 'y'`)은 거부한다(D4). 같은 함정을
     * 문법상 쓸 수 없게 만드는 것이 이 포맷의 존재 이유 중 하나이며, 번들 실사용도 0건이다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $value  원문 값
     * @param  string  $name  속성명 (오류 메시지용)
     * @param  string|null  $path  소스 파일 경로
     * @return string 정규화된 표현식
     *
     * @throws CompileError 혼합형인 경우
     */
    private function normalizeExpression(MarkupAttribute $attribute, string $value, string $name, ?string $path): string
    {
        return MarkupValue::normalizeCondition($value, $attribute->line, $attribute->column, $name, ':'.$name, $path);
    }

    /**
     * `:attr` 값을 JSON 리터럴로 디코드합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 디코드된 값
     *
     * @throws CompileError 값이 없거나 JSON 이 아닌 경우
     */
    private function decodeTypeLiteral(MarkupAttribute $attribute, ?string $path): mixed
    {
        return MarkupValue::decodeTypeLiteral($attribute, ltrim($attribute->name, ':'), $path);
    }

    /**
     * `<prop>` 본문을 JSON 값으로 디코드합니다.
     *
     * @param  MarkupElement  $element  `<prop>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 디코드된 값
     *
     * @throws CompileError 본문이 JSON 이 아닌 경우
     */
    private function decodeJsonBody(MarkupElement $element, ?string $path): mixed
    {
        $body = trim((string) ($element->text ?? ''));
        $value = json_decode($body, true);

        if ($value === null && strtolower($body) !== 'null') {
            throw $this->error($element, LayoutMarkupErrorCode::InvalidJsonBody, $path, [
                'tag' => $element->name,
                'reason' => json_last_error_msg(),
            ]);
        }

        return $value;
    }

    /**
     * 불리언 속성에 `"true"`/`"false"` 를 쓴 표기를 거부합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 표준 불리언 속성에 리터럴 값을 표기한 경우
     */
    private function rejectBooleanLiteral(MarkupAttribute $attribute, ?string $path): void
    {
        if (! in_array(strtolower($attribute->name), self::BOOLEAN_ATTRIBUTES, true)) {
            return;
        }

        if (! in_array(strtolower(trim((string) $attribute->value)), ['true', 'false'], true)) {
            return;
        }

        throw $this->attributeError($attribute, LayoutMarkupErrorCode::BooleanAttributeFalse, $path, [
            'attr' => $attribute->name,
        ]);
    }

    /**
     * 다른 프레임워크 관용구를 거부하고 G7 표기를 안내합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 외래 관용구인 경우
     */
    private function rejectForeignIdiom(MarkupAttribute $attribute, ?string $path): void
    {
        $name = strtolower($attribute->name);
        $suggestion = self::FOREIGN_IDIOMS[$name] ?? null;

        if ($suggestion === null && (str_starts_with($name, '@') || str_starts_with($name, 'v-on:'))) {
            $event = ltrim(strtolower(explode(':', $name, 2)[1] ?? substr($name, 1)), ':');

            throw $this->attributeError($attribute, LayoutMarkupErrorCode::ForeignIdiom, $path, [
                'found' => $attribute->name,
                'suggestion' => '<on event="'.$event.'">',
            ]);
        }

        if ($suggestion !== null) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::ForeignIdiom, $path, [
                'found' => $attribute->name,
                'suggestion' => $suggestion,
            ]);
        }
    }

    /**
     * JSON 노드 키를 무접두 속성으로 쓴 표기를 거부하고 올바른 표기를 안내합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 노드 키 이름을 속성으로 쓴 경우
     */
    private function rejectNodeKeyAttribute(MarkupAttribute $attribute, ?string $path): void
    {
        $suggestion = self::NODE_KEY_ATTRIBUTES[$attribute->name] ?? null;

        if ($suggestion !== null) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::NodeKeyAsAttribute, $path, [
                'attr' => $attribute->name,
                'suggestion' => $suggestion,
            ]);
        }
    }

    /**
     * G7 이 쓰지 않는 표준 속성을 거부합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 지원하지 않는 속성인 경우
     */
    private function rejectUnsupportedAttribute(MarkupAttribute $attribute, ?string $path): void
    {
        if (in_array(strtolower($attribute->name), self::REJECTED_ATTRIBUTES, true)) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::AttributeNotSupported, $path, [
                'attr' => $attribute->name,
            ]);
        }
    }

    /**
     * 정수 지시 속성 값을 변환합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $name  속성명 (오류 메시지용)
     * @param  string  $value  원문 값
     * @param  string|null  $path  소스 파일 경로
     * @return int 정수 값
     *
     * @throws CompileError 정수가 아닌 경우
     */
    private function toInteger(MarkupAttribute $attribute, string $name, string $value, ?string $path): int
    {
        if (preg_match('/^-?[0-9]+$/', trim($value)) !== 1) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DirectiveNeedsInteger, $path, [
                'attr' => $name,
                'found' => $value,
            ]);
        }

        return (int) trim($value);
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
     * 같은 노드 키가 두 번 지정되지 않았는지 확인합니다.
     *
     * @param  MarkupElement  $element  구조 태그 요소
     * @param  array<string, mixed>  $node  노드
     * @param  string  $key  노드 키
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 이미 지정된 키인 경우
     */
    private function assertNotDuplicated(MarkupElement $element, array $node, string $key, ?string $path): void
    {
        if (isset($node[$key])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => $key]);
        }
    }

    /**
     * 노드 키를 고정 순서로 정렬합니다.
     *
     * @param  array<string, mixed>  $node  노드
     * @return array<string, mixed> 정렬된 노드
     */
    private function order(array $node): array
    {
        $ordered = [];

        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $node)) {
                $ordered[$key] = $node[$key];
            }
        }

        // 순서 목록에 없는 키가 생기면 뒤에 붙인다 — 상위 단계(S3 액션 등)가 키를 얹을 때
        // 순서 목록 갱신을 잊어도 값이 사라지지는 않게 한다.
        return $ordered + $node;
    }

    /**
     * 오류 메시지에 실을 짧은 미리보기를 만듭니다.
     *
     * @param  string  $text  원문
     * @return string 미리보기
     */
    private function preview(string $text): string
    {
        $trimmed = trim($text);

        return mb_strlen($trimmed, 'UTF-8') > 20 ? mb_substr($trimmed, 0, 20, 'UTF-8').'…' : $trimmed;
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
