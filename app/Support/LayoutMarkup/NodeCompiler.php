<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 요소 트리 하나를 JSON 레이아웃 **노드**로 컴파일하는 층 (매핑 사전 C).
 *
 * 이 층이 확정하는 것은 세 가지다.
 *
 * **① 속성 3분할.** 작성자가 쓴 속성은 프리픽스만으로 갈린다 — 무접두는 컴포넌트 props,
 * `data-*` 예약어는 엔진 지시자(노드 키), `:attr` 은 타입 리터럴. 이 분할 덕에 "브라우저가
 * 아는 속성 / 우리 엔진이 아는 지시자 / 타입 강제" 가 눈으로 구분된다.
 *
 * **② props 는 검증하지 않고 원문 통과.** components.json 의 prop 선언은 화이트리스트가
 * 아니라 힌트다 — 실측상 선언 커버리지가 75~77% 이고 미선언 목록 상위가 `Button.type`·
 * `Input.value` 같은 정상 props 다. 화이트리스트로 강제하면 정상 마크업 7천여 건이 거부된다.
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
     * 달라지므로(실사용은 정수 31건), 타입이 하나로 정해지는 지시자는 작성자가 `:` 를 붙이지
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
        // 설치가 `<include src>` 조각을 **노드 자리에** 인라인하며 남긴 조각 이름(DB 행에만 있다).
        // 소스 파일에는 나타나지 않지만 편집기는 설치본을 열므로, 그 문서를 다시 소스로 되돌릴
        // 자리가 필요하다 — `<modal name>`·`<partial name>` 이 같은 키를 나르는 것과 같은 규율이다.
        'data-layout-name' => ['key' => 'layout_name', 'type' => 'string'],
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
     * `required="false"` 는 표준상 참이므로(값을 보지 않는다) 작성자의 의도와 정반대가 된다.
     * 리터럴 값을 정말 전달하려면 `:required="false"` 를 쓴다.
     *
     * G7 개념과 겹치는 표준 이름(`default`·`loop`)은 뺐다 — `<class-map default>` 처럼 문자열
     * 값을 받는 자리가 있어 불리언으로 단정하면 정상 마크업을 막는다.
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
     * Dropdown trigger/content 배치)이라, 막으면 정상 마크업이 거부된다. 노드 레벨 슬롯은
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
     * 옛 JSON 을 손으로 옮기는 작성자는 노드 키를 그대로 속성에 적기 쉽다(`condition="{{x}}"`).
     * 무접두는 props 규칙이라 조용히 통과하는데, 엔진은 **노드 키**만 읽으므로 조건이 아예 걸리지
     * 않은 노드가 나온다 — 결함 인벤토리의 "노드 condition bare 식 → 항상 렌더" 와 같은 지대다.
     *
     * 목록은 전 번들 레이아웃의 props 이름과 매니페스트 prop 선언을 전수 대조해, **props 로 쓰이는
     * 이름이 하나도 없는 노드 키**만 넣었다. `id`·`type`·`name`·`text`·`style`·`actions`·`slot`·
     * `responsive`·`sortable`·`children` 은 정상 props 로 실사용되므로 뺐다 — 막으면 D19 와 같은
     * 실수(정상 마크업 거부)가 된다. `dataKey`/`debounce` 는 props 위치 실사용이 있으나 그쪽이
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
        'layout_name' => 'data-layout-name',
    ];

    /**
     * G7 문법의 태그이지만 이 층이 아직 다루지 않는 것 → 담당 표기 안내.
     *
     * 이 목록이 없으면 오타 제안이 엉뚱한 컴포넌트를 짚는다 — `<on>` 은 편집 거리 1 인 `<ol>` 을,
     * `<cell>` 은 `<ul>` 을 "쓰려던 것 아닌가요?" 로 제안한다. G7 어휘를 오타로 취급하는 셈이라
     * 작성자를 더 헷갈리게 만든다.
     *
     * 상위 단계가 해당 태그를 구현하면 **여기서 지운다**. 잊더라도 그 단계의 컴파일 테스트가
     * 이 에러로 실패하므로 목록이 조용히 낡지 않는다.
     *
     * 값은 표기 갈래의 **다국어 키**다(`exceptions.layout_markup.tag_owner.{값}`). 한국어 낱말을
     * 그대로 두면 일본어·영어 메시지 한가운데에 한국어가 박힌다.
     *
     * 임베딩 태그(`<column>`·`<responsive>` 등)는 S5 에서 구현되어 여기 없다 — 자식 hoist 전용이라
     * 컴포넌트 자리(루트·잘못된 부모)에 오면 EmbeddingCompiler 가 부모 문맥 오류로 잡는다.
     * `<modal>`·`<option>`·`<card>`·`<footer>` 는 같은 이름의 컴포넌트가 있어, 같은 태그가 컴포넌트냐
     * 임베딩이냐를 **위치로** 가른다 — 옛 표기 `<card>`·`<footer>` 는 종전 부모(그리드·Modal) 아래에서만
     * hoist 이고 그 밖에서는 컴포넌트다. 부모와 무관한 hoist 는 `<prop-card>`·`<prop-footer>` 로 쓴다
     * (child 루프의 embedding 분기, EmbeddingCompiler::LEGACY_TAGS).
     *
     * 액션 태그(`<on>`·`<switch>` 등)는 S3 에서 구현되어 여기 없다 — 이벤트 컨텍스트 전용이므로
     * 노드 자리에 오면 ACTION_ONLY_TAGS 가 별도 에러로 잡는다. 문서 최상위 섹션(`<data-source>`
     * 등)도 S4 에서 구현되어 여기 없다 — 노드 자리에 오면 SECTION_ONLY_TAGS 가 잡는다.
     *
     * @var array<string, string>
     */
    private const DEFERRED_TAGS = [
        // 현재 이월 태그 없음 — include(조각 참조)는 S7 에서 구현됨(compileInclude).
    ];

    /**
     * 문서 최상위·중첩 섹션 태그 — 컴포넌트(노드) 자리에 오면 문서 구조를 잘못 이해한 것 (S4 소관).
     *
     * `<data-source>`·`<meta>` 등은 문서 봉투 직속(또는 그 하위 섹션)에만 오는 메타데이터라, 노드
     * 자리에서는 이월(TagNotYetSupported)이 아니라 자리 오류로 안내한다 — 이미 구현됐으므로
     * "아직 다루지 않음" 이 아니라 "여기 쓸 수 없음" 이다(ACTION_ONLY_TAGS 와 같은 규율).
     *
     * `<slot>` 은 뺐다 — 노드 위치의 `<slot name>` 은 베이스 레이아웃의 자리표시 노드(`type: slot`)라
     * 정상 마크업이다(아래 compileSlotNode). `<modal>` 도 뺐다 — 노드 자리에서는 Modal 컴포넌트다.
     * `<permissions>`·`<meta>` 도 뺐다(D36) — 노드 자리의 `<permissions>` 는 그 노드의 조건 렌더 권한,
     * `<meta>` 는 그 노드의 편집기 메타(editor_label 등)다(STRUCTURAL_TAGS 에서 처리). 문서 직속이면 각각
     * 레이아웃 권한/문서 메타 섹션 — 위치로 갈린다(modal/slot 과 동일). `<seo>` 는 문서 `<meta>` 전용 존치.
     *
     * @var array<int, string>
     */
    private const SECTION_ONLY_TAGS = [
        'seo', 'data-source', 'computed', 'init', 'init-local', 'init-global', 'init-isolated',
        'from-source', 'defines', 'error-handling', 'global-headers', 'transition-overlay',
        'script', 'inject', 'override', 'permission', 'define', 'match', 'wait-for', 'skeleton', 'spinner',
        'og', 'vars', 'extensions', 'structured-data', 'data-sources',
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
        'action-ref',
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
    private const STRUCTURAL_TAGS = ['class-map', 'isolated-state', 'blur-until-loaded', 'prop', 'all-of', 'any-of', 'sortable', 'permissions', 'meta', 'options'];

    /**
     * `<prop>` 안에서 병합 지시자로 해석되는 태그 (D42 G5) — `<append>` → `_append`.
     *
     * 확장 주입 props 는 대상 레이아웃 props 에 병합되므로, "덧붙인다" 는 의도를 키 이름(`_append`)이
     * 아니라 태그로 적는다. 지시자 안의 값은 다시 `<item>`/`<field>` 문법을 그대로 쓴다.
     *
     * @var array<int, string>
     */
    private const MERGE_DIRECTIVE_TAGS = ['append'];

    /**
     * `<sortable>` 속성명(kebab) → sortable 구조체 키(camel).
     *
     * sortable 은 노드 키 객체(`{source, itemKey, strategy, handle, itemVar, indexVar}`)이며 짝인
     * itemTemplate 은 `<item-template>` 이 맡는다(EmbeddingCompiler). 마크업 태그명은 kebab 이므로
     * 실측 키(camelCase)로 되돌린다 — 왕복 시 이 표를 역으로 적용하면 원문이 복원된다.
     *
     * @var array<string, string>
     */
    private const SORTABLE_KEYS = [
        'source' => 'source', 'item-key' => 'itemKey', 'strategy' => 'strategy',
        'handle' => 'handle', 'item-var' => 'itemVar', 'index-var' => 'indexVar',
        'group' => 'group', 'disabled' => 'disabled', 'wrapper-element' => 'wrapperElement',
    ];

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
        'comment', 'layout_name', 'id', 'type', 'name', 'if', 'conditions', 'iteration', 'slot', 'slotOrder',
        'dataKey', 'trackChanges', 'debounce', 'classMap', 'blur_until_loaded',
        'isolatedState', 'isolatedScopeId', 'sortable', 'itemTemplate', 'component_layout',
        'responsive', 'lifecycle', 'onComponentEvent', 'actions',
        'props', 'text', 'children', 'default', 'callbacks',
    ];

    /** 액션 컴파일러 — `<on>` 블록을 노드의 actions/lifecycle/onComponentEvent 키로 흡수 (S3) */
    private readonly ActionCompiler $actions;

    /** 임베딩 컴파일러 — `<column>`·`<responsive>` 등 자식 서브트리를 props/노드 키로 hoist (S5) */
    private readonly EmbeddingCompiler $embedding;

    /** 구조값 컴파일러 — `<prop>` 안의 `<field>`/`<item>` 트리 (D42) */
    private readonly StructuredValueCompiler $values;

    /** 컴포넌트 종류 예약 속성 — 생략하면 basic (JSON 의 `type` 키와 1:1) */
    public const TYPE_ATTRIBUTE = 'data-type';

    /** `data-type` 이 받는 값 */
    public const COMPONENT_TYPES = ['basic', 'composite', 'layout'];

    /** `data-type` 생략 시 종류 */
    public const DEFAULT_COMPONENT_TYPE = 'basic';

    /** 소스 위치 표식 켜짐 여부 — {@see markSourcePositions()} */
    private bool $markSource = false;

    /**
     * 노드 컴파일러를 만듭니다.
     *
     * 산출물은 템플릿과 무관하다 — 태그 → 이름은 표기 규칙(`ComponentRegistry::fromTag`), 종류는
     * `data-type` 속성으로 정한다(JSON 시절 작성자가 `name`·`type` 을 직접 적던 것과 같다). 레지스트리는
     * 산출에 쓰지 않고, 편집기·개발 커맨드가 특정 템플릿 기준 검사를 원할 때만 받는다.
     *
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리 (없으면 검사 생략)
     */
    public function __construct(private readonly ?ComponentRegistry $registry = null)
    {
        $this->actions = new ActionCompiler;
        $this->embedding = new EmbeddingCompiler($this);
        $this->values = new StructuredValueCompiler(LayoutMarkupErrorCode::UnexpectedStructuralChild);
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
        $node = $this->compileNode($element, $path);

        if ($this->markSource && ! array_is_list($node)) {
            $node[LayoutLinter::SOURCE_POSITION_KEY] = [$element->line, $element->column];
        }

        return $node;
    }

    /**
     * 소스 위치 표식을 켜거나 끕니다 (7.1.0 P16).
     *
     * 켜면 노드 배열마다 `LayoutLinter::SOURCE_POSITION_KEY => [줄, 칸]` 을 싣는다. 위치 없는 lint 경고의
     * 자리를 찾는 재컴파일에서만 켠다 — 저장·반환 JSON 에는 싣지 않는다.
     *
     * @param  bool  $on  켤지 여부
     * @return void 반환값 없음
     */
    public function markSourcePositions(bool $on): void
    {
        $this->markSource = $on;
        $this->actions->markSourcePositions($on);
    }

    /**
     * 요소 하나를 JSON 노드로 컴파일합니다 (표식 없는 본체 — {@see compile()}).
     *
     * @param  MarkupElement  $element  트리 빌더가 만든 요소
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return array<string, mixed> JSON 노드
     *
     * @throws CompileError 문법·표기 규칙을 위반한 경우
     */
    private function compileNode(MarkupElement $element, ?string $path = null): array
    {
        // 베이스 레이아웃의 슬롯 자리표시 — `<slot name="content"/>` → `{type: slot, name: content}`.
        // 자식 레이아웃이 채우는 `<slot name>` 섹션은 문서 층(S4)이 직속에서 먼저 가로챈다.
        if ($element->name === 'slot') {
            return $this->compileSlotNode($element, $path);
        }

        // 확장 주입 지점 — `<extension-point name="html_editor">` → `{type: extension_point, name}`.
        // 자식 `<default>`/`<callback>` 을 폴백 콘텐츠·콜백 채널로 흡수한다 (S5).
        if ($element->name === 'extension-point') {
            return $this->compileExtensionPoint($element, $path);
        }

        // 조각 참조 — `<include src="path"/>` → `{partial: "path"}` (S7). 설치 시
        // resolveAllPartials 가 참조 지점에 조각을 인라인 병합하며, 그때 이 노드는 소멸한다.
        // 참조 노드는 `partial` 키 하나뿐이므로(형제 키는 병합이 버린다) 다른 자식·속성을 받지 않는다.
        if ($element->name === 'include') {
            return $this->compileInclude($element, $path);
        }

        // 노드 자리의 `<json>JSON</json>`(무키) — 표기 탈출구(S9). 노드 자체가 표현 불가한 shape
        // (결함 컴포넌트·void 자식·비정규 노드)일 때 역변환기가 노드 통째로 방출한 것을 되읽는다.
        if ($element->name === 'json' && ! $element->hasAttribute('key')) {
            return $this->decodeJsonBody($element, $path);
        }

        $definition = $this->resolveComponent($element, $path);

        $node = ['type' => $definition->type, 'name' => $definition->name];
        $props = [];

        foreach ($element->attributes as $attribute) {
            $this->applyAttribute($attribute, $element, $node, $props, $path);
        }

        $children = [];
        $hoist = [];

        foreach ($element->children as $child) {
            if ($child->name === 'on') {
                $this->actions->applyOn($child, $node, $path);

                continue;
            }

            // `<json>` — 표기 탈출구(S9). 첫 급부 문법이 표현하지 못하는 것을 JSON 본문 그대로 실어
            // 100% 왕복을 보장한다. `key` 가 있으면 이 노드의 한 키(결함 키·비정규 shape)를, `key` 가
            // 없으면 자식 노드 하나 전체(void 자식 등 노드 자리에서 표현 불가한 경우)를 방출한다.
            // 역변환기가 자기검증에서 왕복이 깨지는 자리에만 쓰며, 그 사용량은 예산 리포트로 봉인된다.
            if ($child->name === 'json') {
                $keyAttr = $child->attribute('key');

                if ($keyAttr === null) {
                    $children[] = $this->decodeJsonBody($child, $path);
                } else {
                    $node[(string) $keyAttr->value] = $this->decodeJsonBody($child, $path);
                }

                continue;
            }

            if (in_array($child->name, self::STRUCTURAL_TAGS, true)) {
                $this->applyStructuralChild($child, $node, $props, $path);

                continue;
            }

            if ($this->embedding->handlesChild($child->name, $definition)) {
                $this->embedding->applyChild($child, $definition, $node, $props, $hoist, $path);

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

        // props 배열 hoist(columns/footerCells/…)를 옮긴다 — `:attr`·`<prop>` 으로 이미 찬 자리와
        // 충돌하면 여기서 잡힌다(같은 키를 두 표기가 다투는 침묵 덮어쓰기 차단).
        foreach ($hoist as $key => $value) {
            $this->assignProp($props, $key, $value, $element->name, $element->line, $element->column, $path);
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
     * `<include src="path"/>` 를 조각 참조 노드 `{partial: "path"}` 로 컴파일합니다 (S7).
     *
     * 참조 노드는 설치 시 resolveAllPartials 가 그 자리에 조각을 인라인하며 통째로 치환하므로,
     * `partial`(+왕복용 `comment`) 외의 형제 키를 남기면 침묵 소실된다. 그래서 `src` 만 받고
     * 자식·다른 속성은 두지 않는다(있으면 표기 오류).
     *
     * @param  MarkupElement  $element  `<include>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> `{partial: ...}` 노드
     *
     * @throws CompileError src 누락·자식/추가 속성 존재 시
     */
    private function compileInclude(MarkupElement $element, ?string $path): array
    {
        $src = $this->requiredAttribute($element, 'src', $path);

        // 참조 노드는 partial 하나로 치환되므로 다른 속성/자식은 조용히 버려진다 — 표기 오류로 세운다.
        foreach ($element->attributes as $attribute) {
            if ($attribute->name !== 'src') {
                throw $this->error($element, LayoutMarkupErrorCode::NodeKeyAsAttribute, $path, [
                    'attr' => $attribute->name,
                    'suggestion' => 'src',
                ]);
            }
        }

        if ($element->children !== []) {
            throw $this->error($element, LayoutMarkupErrorCode::MixedTextAndChildren, $path, [
                'tag' => $element->name,
                'found' => $src,
            ]);
        }

        // 소스는 소스를 가리키고, 산출 JSON 은 `.json` 표기를 유지한다 — 확장자를 바꾸는 일은
        // 오직 이 경계에서만 일어난다(PartialReferenceNotation). 규약 밖 표기를 통과시키면 그 값이
        // 산출물 안에서 정상처럼 보이므로 세운다.
        if (! PartialReferenceNotation::isAuthoringNotation($src)) {
            throw $this->error($element, LayoutMarkupErrorCode::PartialSrcNotAuthoring, $path, [
                'tag' => $element->name,
                'src' => $src,
                'suggestion' => PartialReferenceNotation::toMarkup($src),
            ]);
        }

        $partial = PartialReferenceNotation::toJson($src);
        $node = ['partial' => $partial];

        if ($element->comment !== null) {
            $node = ['comment' => $element->comment, 'partial' => $partial];
        }

        return $node;
    }

    /**
     * `<slot name="content"/>` 를 슬롯 자리표시 노드로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<slot>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> `{type: slot, name}` 노드
     */
    private function compileSlotNode(MarkupElement $element, ?string $path): array
    {
        $node = ['type' => 'slot', 'name' => $this->requiredAttribute($element, 'name', $path)];

        $children = array_map(fn (MarkupElement $child): array => $this->compile($child, $path), $element->children);

        if ($children !== []) {
            $node['children'] = $children;
        }

        if ($element->comment !== null) {
            $node['comment'] = $element->comment;
        }

        return $node;
    }

    /**
     * `<extension-point>` 을 확장 주입 지점 노드로 컴파일합니다.
     *
     * `name` 은 주입 지점 이름(노드 `name`)이라 컴포넌트 props 의 `name` 과 자리가 다르다 — props 에
     * `name` 이 필요하면 `<prop name="name">` 으로 쓴다. 자식 `<default>` 는 확장 미설치 시 폴백
     * 콘텐츠(node[]), `<callback name>` 은 확장에 넘길 콜백 채널(`{이름: 액션}`)이다.
     *
     * @param  MarkupElement  $element  `<extension-point>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> extension_point 노드
     *
     * @throws CompileError name 이 없거나 자식이 `<default>`/`<callback>` 가 아닌 경우
     */
    private function compileExtensionPoint(MarkupElement $element, ?string $path): array
    {
        $node = ['type' => 'extension_point', 'name' => $this->requiredAttribute($element, 'name', $path)];
        $props = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute->name === 'name') {
                continue;
            }

            $this->applyAttribute($attribute, $element, $node, $props, $path);
        }

        $default = null;
        $callbacks = [];

        foreach ($element->children as $child) {
            switch ($child->name) {
                case 'prop':
                    // props 의 `name` 은 노드 `name`(주입 지점 이름)과 자리가 겹치므로 `<prop>` 으로 쓴다.
                    $name = $this->requiredAttribute($child, 'name', $path);
                    $this->assignProp(
                        $props,
                        self::ATTRIBUTE_ALIASES[$name] ?? $name,
                        $this->compilePropValue($child, $path),
                        $child->name,
                        $child->line,
                        $child->column,
                        $path,
                    );
                    break;

                case 'default':
                    if ($default !== null) {
                        throw $this->error($child, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => 'default']);
                    }

                    $default = array_map(fn (MarkupElement $node): array => $this->compile($node, $path), $child->children);
                    break;

                case 'callback':
                    $name = $this->requiredAttribute($child, 'name', $path);

                    if (array_key_exists($name, $callbacks)) {
                        throw $this->error($child, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => 'callbacks.'.$name]);
                    }

                    $callbacks[$name] = $this->actions->compileNamedAction($child, $path);
                    break;

                default:
                    throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                        'tag' => $child->name,
                        'parent' => 'extension-point',
                    ]);
            }
        }

        if ($props !== []) {
            $node['props'] = $props;
        }

        if ($default !== null) {
            $node['default'] = $default;
        }

        if ($callbacks !== []) {
            $node['callbacks'] = $callbacks;
        }

        if ($element->comment !== null) {
            $node['comment'] = $element->comment;
        }

        return $this->order($node);
    }

    /**
     * `<responsive on="{bp}">` 본문을 브레이크포인트 부분 노드로 컴파일합니다 (EmbeddingCompiler 호출).
     *
     * 브레이크포인트 값은 `{props?, children?, text?, if?, iteration?}` — props 는 얕은 머지, 나머지는
     * 완전 교체다(엔진). `on` 은 브레이크포인트 선택자라 부모가 소비하므로 여기서는 건너뛴다. 그 밖
     * 속성은 일반 노드처럼 3분할(props/`data-if`→if/`data-each`→iteration)한다.
     *
     * @param  MarkupElement  $element  `<responsive>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 브레이크포인트 부분 노드
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    public function compileResponsiveOverride(MarkupElement $element, ?string $path): array
    {
        $override = [];
        $props = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute->name === 'on') {
                continue;
            }

            $this->applyAttribute($attribute, $element, $override, $props, $path);
        }

        // 오버라이드도 노드와 같은 구조 태그를 받는다 — `<class-map>` 등이 브레이크포인트별로 달라지는
        // 실사용이 있다(portable 에서만 다른 classMap). 구조 태그를 노드 자식으로 컴파일하면 존재하지
        // 않는 컴포넌트를 찾다 실패한다.
        $children = [];

        foreach ($element->children as $child) {
            if (in_array($child->name, self::STRUCTURAL_TAGS, true)) {
                $this->applyStructuralChild($child, $override, $props, $path);

                continue;
            }

            $children[] = $this->compile($child, $path);
        }

        if ($props !== []) {
            $override['props'] = $props;
        }

        if ($children !== []) {
            $override['children'] = $children;
        } elseif ($element->text !== null) {
            $override['text'] = $element->text;
        }

        // 오버라이드 주석 — `comment` 는 전 위치 횡단 키다(서빙 strip, 편집 모드 보존).
        if ($element->comment !== null) {
            $override['comment'] = $element->comment;
        }

        return $this->order($override);
    }

    /**
     * `<options>` 안의 `<option>` 들을 `{value, label, disabled?}` options 배열로 컴파일합니다 (G13).
     *
     * `value` 는 문자열(`value=`) 또는 타입 리터럴(`:value=`), `label` 은 본문 텍스트, `disabled` 는
     * 불리언 속성이다. 표현식·`$t:` 는 원문 그대로 싣는다(선평가 금지).
     *
     * **래퍼가 판별자다.** Select 는 `props.options` 유무로 렌더 컴포넌트 자체가 갈리므로
     * (있으면 커스텀 드롭다운, 없으면 네이티브 `<select>`+children — 런타임 동치가 아님)
     * 어느 쪽인지를 표기가 결정적으로 말해야 한다. 종전에는 `<select>` 직속 `<option>` 을 두고
     * "전 자식이 정적 option 이면 접는다" 는 휴리스틱으로 갈랐는데, 그 판정은 양방향 모두
     * 틀렸다 — 형제 `<on>` 하나에 리프트가 통째로 불발했고(원본 options → children), 반대로
     * 진짜 Option 노드를 접어버렸다(원본 children → options). 이제 래퍼 안이면 언제나 데이터,
     * 밖이면 언제나 컴포넌트 노드다.
     *
     * @param  MarkupElement  $element  `<options>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> options 배열
     *
     * @throws CompileError `<option>` 이외 자식이 있거나 value 가 없는 경우
     */
    private function compileOptions(MarkupElement $element, ?string $path): array
    {
        $options = [];

        foreach ($element->children as $child) {
            // 데이터 자리이므로 `<option>` 만 온다. 그 밖 태그는 조용히 버리지 않고 자리 오류로 잡는다.
            if ($child->name !== 'option' || $child->children !== []) {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'options',
                ]);
            }

            $typed = $child->attribute(':value');
            $plain = $child->attribute('value');

            if ($typed !== null) {
                $option = ['value' => $this->decodeTypeLiteral($typed, $path)];
            } elseif ($plain !== null && $plain->value !== null) {
                $option = ['value' => $plain->value];
            } else {
                throw $this->error($child, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                    'tag' => $child->name,
                    'attr' => 'value',
                ]);
            }

            $option['label'] = (string) ($child->text ?? '');
            $disabled = $child->attribute('disabled');

            if ($disabled !== null) {
                $option['disabled'] = true;
            }

            $options[] = $option;
        }

        return $options;
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

        if (in_array($element->name, self::SECTION_ONLY_TAGS, true)) {
            throw $this->error($element, LayoutMarkupErrorCode::SectionTagInNodePosition, $path, ['tag' => $element->name]);
        }

        // `<modal>` 자체는 노드 자리에서 정상(Modal 컴포넌트)이지만 **`src` 만은 아니다** — 조각 참조는
        // 문서의 modals 선언 자리 전용이고(DocumentCompiler::compileModal), 노드 자리에 오면 `src` 가
        // 아무도 읽지 않는 prop 으로 산출에 남아 창이 뜨지 않는다. 표기 검사는 선언 자리에만 걸려
        // 있으므로 규약에 맞는 표기여도 여기서 세운다 — 결함은 표기가 아니라 위치다.
        if ($element->name === 'modal' && $element->attribute('src') !== null) {
            throw $this->error($element, LayoutMarkupErrorCode::ModalSrcInNodePosition, $path, [
                'src' => (string) $element->attribute('src')->value,
            ]);
        }

        if (in_array($element->name, self::STRUCTURAL_TAGS, true) || isset(self::NESTED_ONLY_TAGS[$element->name])) {
            throw $this->error($element, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                'tag' => $element->name,
                'parent' => self::NESTED_ONLY_TAGS[$element->name] ?? 'component',
            ]);
        }

        if (isset(self::DEFERRED_TAGS[$element->name])) {
            throw $this->error($element, LayoutMarkupErrorCode::TagNotYetSupported, $path, [
                'tag' => $element->name,
                'owner' => __('exceptions.layout_markup.tag_owner.'.self::DEFERRED_TAGS[$element->name]),
            ]);
        }

        // 이름은 표기 규칙으로, 종류는 `data-type` 으로 정한다 — 어떤 템플릿의 컴포넌트 목록도 보지 않는다.
        // 컴포넌트가 실제로 있는지는 JSON 과 같이 런타임(활성 템플릿)이 판정한다. 검사용 레지스트리가
        // 주어지면 그 템플릿에 없는 이름을 강검증(`LayoutValidator`)이 세운다(편집기·개발 커맨드).
        $name = ComponentRegistry::fromTag($element->name);
        $known = $this->registry?->findByName($name);

        return new ComponentDefinition(
            name: $name,
            tag: $element->name,
            type: $this->componentType($element, $path),
            propTypes: $known !== null ? $known->propTypes : [],
            skipBindingKeys: $known !== null ? $known->skipBindingKeys : ComponentRegistry::DEFAULT_SKIP_BINDING_KEYS,
        );
    }

    /**
     * 요소의 `data-type` 속성으로 컴포넌트 종류를 정합니다 (생략하면 basic).
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return string 컴포넌트 종류 (basic·composite·layout)
     *
     * @throws CompileError 허용되지 않은 값이거나 바인딩·타입 속성으로 적은 경우
     */
    private function componentType(MarkupElement $element, ?string $path): string
    {
        if ($element->hasAttribute(':'.self::TYPE_ATTRIBUTE)) {
            throw $this->attributeError($element->attribute(':'.self::TYPE_ATTRIBUTE), LayoutMarkupErrorCode::InvalidComponentType, $path, [
                'value' => (string) $element->attribute(':'.self::TYPE_ATTRIBUTE)->value,
                'allowed' => implode(', ', self::COMPONENT_TYPES),
            ]);
        }

        $attribute = $element->attribute(self::TYPE_ATTRIBUTE);

        if ($attribute === null) {
            return self::DEFAULT_COMPONENT_TYPE;
        }

        $value = (string) $attribute->value;

        if (! in_array($value, self::COMPONENT_TYPES, true)) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidComponentType, $path, [
                'value' => $value,
                'allowed' => implode(', ', self::COMPONENT_TYPES),
            ]);
        }

        return $value;
    }

    /**
     * 속성 하나를 노드 키 또는 props 로 배치합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  MarkupElement  $element  속성이 실린 요소
     * @param  array<string, mixed>  $node  노드 (참조)
     * @param  array<string, mixed>  $props  props (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
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

        // 컴포넌트 종류는 노드 `type` 키다 — 컴포넌트 해석(`componentType`)이 이미 소비했다.
        if ($name === self::TYPE_ATTRIBUTE) {
            return;
        }

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
     * 막지 않으면 나중 것이 앞엣것을 조용히 지우는데, 작성자는 두 값이 다 반영될 것으로 기대한다.
     *
     * @param  array<string, mixed>  $props  props (참조)
     * @param  string  $key  배치할 props 키
     * @param  mixed  $value  값
     * @param  string  $tag  값이 실린 태그명 (오류 메시지용)
     * @param  int  $line  오류 좌표 라인
     * @param  int  $column  오류 좌표 컬럼
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
                    $this->compilePropValue($child, $path),
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

            case 'sortable':
                $this->assertNotDuplicated($child, $node, 'sortable', $path);
                $node['sortable'] = $this->compileSortable($child, $path);
                break;

            case 'permissions':
                // 노드 레벨 조건 렌더 권한 (D36) — 문서 최상위 `<permissions>` 와 같은 문법(공용 컴파일러).
                $this->assertNotDuplicated($child, $node, 'permissions', $path);
                $node['permissions'] = (new PermissionsCompiler)->compile($child, $path);
                break;

            case 'meta':
                // 노드 레벨 편집기 메타 (D36) — editor_label 등 평면 스칼라. 문서 `<meta>`(+`<seo>`)와 위치 분기.
                $this->assertNotDuplicated($child, $node, 'meta', $path);
                $node['meta'] = $this->compileNodeMeta($child, $path);
                break;

            case 'options':
                // Select 옵션 **데이터** (D42 G13) — `props.options` 배열이 된다.
                $this->assignProp(
                    $props,
                    'options',
                    $this->compileOptions($child, $path),
                    $child->name,
                    $child->line,
                    $child->column,
                    $path,
                );
                break;
        }
    }

    /**
     * 노드 레벨 `<meta>` 를 평면 메타 객체로 컴파일합니다 (속성 → 키, 자식 불가).
     *
     * 노드 메타는 편집기 메타데이터(editor_label 등)라 평면 스칼라다 — `<seo>` 같은 중첩 자식은 문서
     * `<meta>` 전용이므로 노드 자리에서는 받지 않는다. `:attr` 은 타입 리터럴(불리언·숫자)로 읽는다.
     *
     * @param  MarkupElement  $element  `<meta>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 메타 객체
     *
     * @throws CompileError 자식이 있거나 속성명이 비었거나 중복인 경우
     */
    private function compileNodeMeta(MarkupElement $element, ?string $path): array
    {
        if ($element->children !== []) {
            throw $this->error($element->children[0], LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                'tag' => $element->children[0]->name,
                'parent' => 'meta',
            ]);
        }

        $meta = [];

        foreach ($element->attributes as $attribute) {
            $typed = str_starts_with($attribute->name, ':');
            $key = $typed ? substr($attribute->name, 1) : $attribute->name;

            if ($key === '') {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, ['found' => $attribute->name]);
            }

            if (array_key_exists($key, $meta)) {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, ['tag' => 'meta', 'attr' => $key]);
            }

            $meta[$key] = $typed
                ? MarkupValue::decodeTypeLiteral($attribute, $key, $path)
                : ($attribute->isBoolean() ? true : $attribute->value);
        }

        return $meta;
    }

    /**
     * `<sortable>` 을 sortable 구조체로 컴파일합니다.
     *
     * 속성은 kebab → camel 로 되돌려 실측 키(itemKey/itemVar/indexVar 등)로 방출한다. 짝인
     * itemTemplate 은 `<item-template>` 이 별도 노드 키로 배치한다(EmbeddingCompiler).
     *
     * @param  MarkupElement  $element  `<sortable>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> sortable 구조체
     *
     * @throws CompileError source 가 없거나 알 수 없는 속성인 경우
     */
    private function compileSortable(MarkupElement $element, ?string $path): array
    {
        $sortable = [];

        foreach ($element->attributes as $attribute) {
            $key = self::SORTABLE_KEYS[$attribute->name] ?? null;

            if ($key === null) {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, [
                    'found' => $attribute->name,
                ]);
            }

            $sortable[$key] = $attribute->isBoolean() ? true : (string) $attribute->value;
        }

        if (! array_key_exists('source', $sortable)) {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => $element->name,
                'attr' => 'source',
            ]);
        }

        return $sortable;
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

        // `<variant>` 이 없으면 `variants` 를 만들지 않는다 — 작성하지 않은 것을 방출하지 않는다는
        // 빈 컨테이너 규율과 같다. 엔진도 동치로 읽는다(`classMap.variants && key in variants` 가
        // 거짓 → default 폴백 — RenderHelpers::resolveClassMap). 실사용: default 만 두는 responsive
        // 오버라이드용 classMap.
        if ($variants !== []) {
            $classMap['variants'] = $variants;
        }

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
     * 객체형 전용이다. 데이터소스가 하나면 `sources="a"`, 여럿이면 `<item>` 리스트로 쓴다
     * (D51 G16 — `:sources='["a","b"]'` 는 속성에 박힌 통짜 JSON 이라 D2 를 어긴다). 실사용은
     * 문자열 18건·배열 6건이라 두 shape 를 모두 보존해야 한다. `:sources` 는 표기를 주지 않은
     * 형태(빈 리스트·표현식 배열)의 탈출구로 남는다.
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
        $items = $element->children !== [];

        // 셋 중 둘 이상을 쓰면 어느 쪽이 데이터소스인지 알 수 없다. 하나를 골라 나머지를 버리면
        // 작성자가 쓴 값이 조용히 사라진다.
        if (count(array_filter([$plain !== null, $typed !== null, $items])) > 1) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                'tag' => $element->name,
                'attr' => 'sources',
            ]);
        }

        if ($plain === null && $typed === null && ! $items) {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => $element->name,
                'attr' => 'sources',
            ]);
        }

        return [
            'enabled' => $enabled === null ? true : $this->decodeTypeLiteral($enabled, $path),
            'data_sources' => match (true) {
                $items => $this->values->compileChildren($element, $path),
                $plain !== null => (string) $plain->value,
                default => $this->decodeTypeLiteral($typed, $path),
            },
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
     * 되는 함정이라(비어 있지 않은 문자열은 truthy) 작성 의도와 정반대로 동작한다.
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
     * `<prop>` 값을 네 표기 중 하나로 컴파일합니다 (D42 G3·G4·G7).
     *
     *  1. **`<field name>` 자식** — 객체(중첩 가능). `<prop name="uploadParams"><field name="temp_key" value="{{…}}"/></prop>`
     *  2. **`<item>` 자식** — 리스트. `<prop name="rowActions"><item id="view" label="$t:…"/></prop>`
     *  3. **`<column>` 자식** — DataGrid 컬럼 트리. **확장 주입 `<props>` 의 `<prop name="columns">` 안에서만** 허용한다 (G7).
     *  4. **JSON 본문** — 종전 표기. 하위호환으로 계속 받는다.
     *
     * 넷은 같은 값을 만들며, 역변환기(`EmitsMarkup::propElement`)가 값 형태를 보고 하나를 고른다.
     *
     * 3 은 소유자 게이트의 **좁은 확장**이다 — 노드 자리에서 `<column>` 은 여전히 DataGrid 부모
     * 아래에서만 뜻이 있고(EmbeddingCompiler), 여기서는 `<prop name="columns">` 컨테이너가 소유자를
     * 대신한다. 주입 fragment 에는 부모 컴포넌트가 없어(대상 노드는 다른 레이아웃에 있다) 소유자로
     * 판정할 수단이 아예 없기 때문이다. **게이트는 prop 이름까지 본다** — 방출기가 `columns` 키에만
     * `<column>` 을 내므로(`LayoutMarkupDecompiler::injectionPropsElement`), 컴파일이 이름을 안 보면
     * 수용 범위가 방출보다 넓어져 오타 이름(`colums`)이 조용히 컬럼 트리로 통과한다.
     *
     * @param  MarkupElement  $element  `<prop>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @param  bool  $isColumnsContainer  이 요소가 **주입 `<props>` 의 `<prop name="columns">`** 인지
     * @return mixed props 값
     *
     * @throws CompileError 자식 표기가 규칙을 벗어난 경우
     */
    public function compilePropElementValue(MarkupElement $element, ?string $path, bool $isColumnsContainer = false): mixed
    {
        return $this->compilePropValue($element, $path, $isColumnsContainer);
    }

    /**
     * `<prop>` 값을 컴파일합니다 (내부 구현 — 위 공개 메서드가 DocumentCompiler 에 위임한다).
     *
     * @param  MarkupElement  $element  `<prop>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @param  bool  $isColumnsContainer  이 요소가 **주입 `<props>` 의 `<prop name="columns">`** 인지
     * @return mixed props 값
     *
     * @throws CompileError 자식 표기가 규칙을 벗어난 경우
     */
    private function compilePropValue(MarkupElement $element, ?string $path, bool $isColumnsContainer = false): mixed
    {
        if ($element->children === []) {
            return $this->decodeJsonBody($element, $path);
        }

        $first = $element->children[0]->name;

        // 병합 지시자 — 확장 주입 props 가 대상 props 에 덧붙는 형태 (D42 G5).
        // `<append>` 안은 **항목 리스트**이지 컬럼 컨테이너가 아니다 — 방출기도 그 안에 `<column>` 을
        // 내지 않으므로, 컬럼 게이트를 물려주면 컴파일이 방출보다 넓어져 비대칭이 된다.
        if (in_array($first, self::MERGE_DIRECTIVE_TAGS, true)) {
            $merge = [];

            foreach ($element->children as $child) {
                if (! in_array($child->name, self::MERGE_DIRECTIVE_TAGS, true)) {
                    throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                        'tag' => $child->name,
                        'parent' => $element->name,
                    ]);
                }

                $merge['_'.$child->name] = $this->compilePropValue($child, $path);
            }

            return $merge;
        }

        if ($isColumnsContainer && $first === 'column') {
            return $this->embedding->compileColumnList($element, $path);
        }

        return $this->values->compileChildren($element, $path);
    }

    /**
     * `<prop>` 본문을 JSON 값으로 디코드합니다 (종전 표기 — 하위호환).
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
