<?php

namespace App\Support\LayoutMarkup;

/**
 * JSON 노드를 `.g7.html` 요소로 되돌리는 역변환 층 (S9 — NodeCompiler + EmbeddingCompiler 의 역).
 *
 * 노드 키를 셋으로 되돌린다: **지시자**(id→`data-ref`·if→`data-if`·iteration→`data-each` 등)·
 * **구조 노드 키**(classMap·conditions·isolatedState·sortable·responsive·component_layout·itemTemplate —
 * props 가 아니라 노드 직속 키라 전용 태그로만 표현된다)·**props**(스칼라=속성, 객체/배열=`<prop>` 본문).
 *
 * 임베딩 hoist(columns·cell·expand·footer-cell 등)는 값이 props 배열이라 `<prop name>` JSON 으로도
 * 왕복하지만, 그 자리에 **읽히는 전용 태그**를 찍는다 — 역변환기는 이관(S10~)이 실제로 쓰는 작성
 * 파일을 만드는 도구이므로, DataGrid 컬럼이 거대한 JSON 덩어리가 아니라 `<column>` 태그로 나와야 한다.
 * 두 표기는 컴파일 산출이 동일해 왕복은 어느 쪽이든 성립한다(임베딩 태그가 못 잡는 형태만 `<prop>` 폴백).
 */
final class NodeMarkupDecompiler
{
    use EmitsMarkup;

    /** 지시자 노드 키 → `data-*` 속성 (문자열/정수 값은 무접두 속성) */
    private const DIRECTIVE_ATTRS = [
        'id' => 'data-ref', 'slot' => 'data-slot', 'slotOrder' => 'data-slot-order',
        'dataKey' => 'data-key', 'debounce' => 'data-debounce',
        // 설치가 조각을 노드 자리에 인라인하며 남긴 이름. 문서 루트(`<layout name>`)·조각 루트
        // (`<partial name>`)·모달(`<modal name>`)은 각자의 봉투가 먼저 이 키를 걷어 가므로,
        // 여기 닿는 것은 **인라인된 노드**뿐이다.
        'layout_name' => 'data-layout-name',
    ];

    /** props 키 별칭 (JSON props → 마크업 속성명) */
    private const PROPS_ALIASES = ['className' => 'class', 'htmlFor' => 'for'];

    /**
     * 무접두 속성으로 쓰면 컴파일러가 노드 키·외래 관용구로 거부하는 이름 — props 에 오면 `<prop>` 로 방출.
     *
     * @var array<int, string>
     */
    private const RESERVED_PROP_NAMES = [
        'condition', 'conditions', 'iteration', 'comment', 'classMap', 'blur_until_loaded',
        'isolatedState', 'isolatedScopeId', 'dataKey', 'trackChanges', 'debounce', 'slotOrder',
        'layout_name', 'is', 'if', 'each', 'ref',
    ];

    /** props 임베딩 hoist 키 → 노드 배열 래퍼 태그 (자식이 노드 배열) */
    private const NODE_ARRAY_HOISTS = [
        'expandChildren' => 'expand', 'subRowChildren' => 'sub-row', 'footerCardChildren' => 'footer-card',
        'titlePrefix' => 'title-prefix', 'titleSuffix' => 'title-suffix',
    ];

    /** `sortable` 노드 키 camel → 마크업 kebab 속성 */
    private const SORTABLE_ATTRS = [
        'source' => 'source', 'itemVar' => 'item-var', 'itemKey' => 'item-key', 'indexVar' => 'index-var',
        'strategy' => 'strategy', 'group' => 'group', 'handle' => 'handle', 'disabled' => 'disabled',
        'wrapperElement' => 'wrapper-element',
    ];

    /** void 태그 — `<json>` 자식을 실을 수 없어 노드 전체를 `<json>` 으로 방출 (직렬화기 VOID 와 동일 11종, img/input 제외) */
    private const VOID_TAGS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'link', 'param', 'source', 'track', 'wbr'];

    private readonly ActionMarkupDecompiler $actions;

    private readonly StateTreeMarkupDecompiler $state;

    private readonly NodeCompiler $compiler;

    /**
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리 (산출에는 쓰지 않는다)
     */
    public function __construct(private readonly ?ComponentRegistry $registry = null)
    {
        $this->actions = new ActionMarkupDecompiler;
        $this->state = new StateTreeMarkupDecompiler;
        $this->compiler = new NodeCompiler($registry);
    }

    /**
     * JSON 노드 하나를 `.g7.html` 요소로 되돌립니다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @return MarkupElement 요소
     */
    public function node(array $node): MarkupElement
    {
        if (array_key_exists('partial', $node)) {
            // 산출 `.json` 표기를 마크업 표기로 되돌린다 — `NodeCompiler::compileInclude` 의 정확한
            // 역함수여야 아래 `verified()` 의 재컴파일 대조가 성립한다(PartialReferenceNotation).
            $src = PartialReferenceNotation::toMarkup((string) $node['partial']);

            return $this->verified($this->el('include', [$this->attr('src', $src)], [], null, $node['comment'] ?? null), $node);
        }

        $type = $node['type'] ?? null;

        // 슬롯은 name + children 뿐이고 자식은 node() 가 개별 검증하므로 통째 재검증(coarse escape)하지 않는다.
        if ($type === 'slot') {
            return $this->slotNode($node);
        }

        if ($type === 'extension_point') {
            try {
                return $this->verified($this->extensionPoint($node), $node);
            } catch (\Throwable) {
                return $this->jsonNode($node);
            }
        }

        return $this->component($node);
    }

    /**
     * slot/extension-point/include 요소를 되컴파일로 검증하고, 어긋나면 노드 전체를 `<json>` 으로 방출합니다.
     *
     * 이 세 타입은 컴포넌트와 달리 키별 escape 경로가 없어(전용 컴파일 갈래), 미표현 shape 는 노드
     * 통째로 탈출한다. 흔한 케이스(콜백 onSuccess 배열·미지 키)를 이 한 기전으로 봉인한다.
     *
     * @param  MarkupElement  $element  첫 급부 요소
     * @param  array<string, mixed>  $source  원본 노드 JSON
     * @return MarkupElement 검증된 요소 또는 `<json>` 노드
     */
    private function verified(MarkupElement $element, array $source): MarkupElement
    {
        try {
            $recompiled = $this->compiler->compile($element);
        } catch (\Throwable) {
            return $this->jsonNode($source);
        }

        return $this->encodeCanonical($source) === $this->encodeCanonical($recompiled) ? $element : $this->jsonNode($source);
    }

    /**
     * 일반 컴포넌트 노드를 요소로 되돌립니다 (자기검증 + 미표현 키 `<json>` 탈출).
     *
     * 첫 급부 문법으로 짓고, 리스크가 있으면 그 노드를 되컴파일해 원본과 대조한다. 어긋나는(또는
     * 미표현) 키만 `<json>` 으로 재방출해 왕복을 보장한다 — 결함 키·비정규 shape·미지 키를 한 기전으로
     * 처리한다. 리스크 없는 평범한 노드(스칼라 props·표준 키)는 되컴파일을 건너뛴다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @return MarkupElement 요소
     */
    private function component(array $node): MarkupElement
    {
        // 표기 규칙으로 태그를 만들 수 없는 이름(빈 name·연속 대문자 등)이나 종류가 셋 중 하나가 아닌 노드는
        // 태그로 되돌릴 수 없다 — 노드 전체 `<json>`. 템플릿 컴포넌트 목록은 보지 않는다.
        $name = (string) ($node['name'] ?? '');

        if (! ComponentRegistry::isTagRoundTrippable($name)
            || ! in_array($node['type'] ?? null, NodeCompiler::COMPONENT_TYPES, true)) {
            return $this->jsonNode($node);
        }

        // 첫 급부 문법이 아예 짓지 못하는 shape(문자열 iteration·문자열 액션 등 결함)는 노드 전체 `<json>`.
        try {
            $element = $this->buildComponent($node, []);
        } catch (\Throwable) {
            return $this->jsonNode($node);
        }

        // void 태그(input/img 등)는 자식을 실을 수 없다 — `<on>`·구조 자식·일반 자식이 남으면
        // 노드 전체를 한 `<json>` 으로 방출한다(객체 props 는 buildComponent 가 이미 타입 속성으로 뺀다).
        if ($element->children !== [] && in_array($this->tagFor((string) ($node['name'] ?? '')), self::VOID_TAGS, true)) {
            return $this->jsonNode($node);
        }

        // 모든 노드를 되컴파일로 검증한다 — "안전" 키도 결함 값(빈 iteration source 등)을 담을 수 있어
        // 키 이름 게이팅으로는 놓친다. 되컴파일은 노드 단위라 대량 파일에서도 수 초 내에 끝난다.
        try {
            $recompiled = $this->compiler->compile($element);
            $escape = $this->differingKeys($node, $recompiled);
        } catch (\Throwable) {
            // 되컴파일이 예외로 실패하면 어긋나는 키를 계산할 수 없다 — 전 키를 통째 blob 하지 않고
            // 컴파일이 서는 **최소 키 집합**만 격리 escape 한다(정상 키 id/props 가 딸려 블롭되는 것 방지).
            $escape = $this->isolateThrowingKeys($node);
        }

        if ($escape === []) {
            return $element;
        }

        // void 태그는 `<json>` escape 자식을 실을 수 없다 — 노드 전체를 한 `<json>` 으로 방출.
        if (in_array($this->tagFor((string) ($node['name'] ?? '')), self::VOID_TAGS, true)) {
            return $this->jsonNode($node);
        }

        return $this->buildComponent($node, $escape);
    }

    /**
     * 컴포넌트 노드를 요소로 짓습니다 (escape 키는 `<json>` 으로, 나머지는 첫 급부 문법으로).
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @param  array<int, string>  $escape  `<json>` 으로 방출할 키
     * @return MarkupElement 요소
     */
    private function buildComponent(array $node, array $escape): MarkupElement
    {
        $filtered = $node;

        foreach ($escape as $key) {
            unset($filtered[$key]);
        }

        $tag = $this->tagFor((string) ($node['name'] ?? ''));
        $isVoid = in_array($tag, self::VOID_TAGS, true);
        $attributes = [];
        $children = [];

        // 종류는 basic 이 아니면 적는다 — 생략하면 basic 이다(NodeCompiler::componentType).
        $type = (string) ($node['type'] ?? NodeCompiler::DEFAULT_COMPONENT_TYPE);

        if ($type !== NodeCompiler::DEFAULT_COMPONENT_TYPE) {
            $attributes[] = $this->attr(NodeCompiler::TYPE_ATTRIBUTE, $type);
        }

        $this->applyDirectives($filtered, $attributes, $children);
        $this->applyStructural($filtered, $children);
        $this->applyProps($filtered['props'] ?? [], (string) ($node['name'] ?? ''), $isVoid, $attributes, $children);

        $children = array_merge($children, $this->actions->nodeActions($filtered));

        foreach ($filtered['children'] ?? [] as $child) {
            $children[] = $this->node($child);
        }

        foreach ($escape as $key) {
            $children[] = $this->jsonElement($key, $node[$key]);
        }

        $text = array_key_exists('text', $filtered) ? (string) $filtered['text'] : null;

        return $this->el($tag, $attributes, $children, $text, $filtered['comment'] ?? null);
    }

    /**
     * 원본 노드와 되컴파일 결과가 어긋나는(또는 미표현) 키를 찾습니다.
     *
     * @param  array<string, mixed>  $source  원본 노드
     * @param  array<string, mixed>  $recompiled  되컴파일 노드
     * @return array<int, string> 어긋나는 키 목록
     */
    private function differingKeys(array $source, array $recompiled): array
    {
        // 노드 단위 정규화 후 키 비교 — 컴파일러가 생략·파생하는 값(빈 props/children·derived type·
        // 콜백 단일배열·Select 옵션)을 escape 사유에서 뺀다(불필요한 `<json>` 방지).
        $source = LayoutMarkupRoundTrip::normalize($source);
        $recompiled = LayoutMarkupRoundTrip::normalize($recompiled);
        $diff = [];

        foreach ($source as $key => $value) {
            if ($key === 'type' || $key === 'name') {
                continue;
            }

            $got = $recompiled[$key] ?? null;

            if (! array_key_exists($key, $recompiled)
                || LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($value)) !== LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($got))) {
                $diff[] = (string) $key;
            }
        }

        return $diff;
    }

    /**
     * 되컴파일이 예외로 실패한 노드의 방출 가능(escape) 키 전량을 반환합니다 (type/name 제외).
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @return array<int, string> escape 키
     */
    private function escapableKeys(array $node): array
    {
        return array_values(array_filter(
            array_keys($node),
            static fn (int|string $key): bool => $key !== 'type' && $key !== 'name',
        ));
    }

    /**
     * 컴파일이 서게 하는 최소 노드 키 집합을 격리합니다 (되컴파일 throw 시 coarse blob 회피).
     *
     * 먼저 각 키를 단독 escape 해 서는지 본다(대개 한 키 결함) — 없으면 키를 하나씩 누적해 서는
     * 지점을 찾는다. 그래도 안 서면 전 키(최후 안전망). buildComponent 는 escape=[] 로 이미 성공했으므로
     * 부분집합 escape 도 성공한다 — 오직 되컴파일만 throw 대상이다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @return array<int, string> escape 할 최소 키 집합
     */
    private function isolateThrowingKeys(array $node): array
    {
        $keys = $this->escapableKeys($node);

        foreach ($keys as $key) {
            if ($this->nodeCompiles($node, [$key])) {
                return [$key];
            }
        }

        // 단일 키로 안 서면(결함 여럿) 전 키 escape 에서 시작해 불필요한 키를 하나씩 un-escape 한다 —
        // 키 순서와 무관하게 진짜 최소 집합을 찾는다(누적 방식은 늦은 결함 앞의 정상 키를 딸려 blob).
        $escape = $keys;

        foreach ($keys as $key) {
            $trial = array_values(array_diff($escape, [$key]));

            if ($this->nodeCompiles($node, $trial)) {
                $escape = $trial;
            }
        }

        return $escape;
    }

    /**
     * 주어진 escape 집합으로 노드를 지어 되컴파일이 서는지 판정합니다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @param  array<int, string>  $escape  escape 키
     * @return bool 컴파일이 서면 true
     */
    private function nodeCompiles(array $node, array $escape): bool
    {
        try {
            $this->compiler->compile($this->buildComponent($node, $escape));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 값을 정규화 후 결정적 JSON 문자열로 인코드합니다 (키 대조용).
     *
     * @param  mixed  $value  값
     * @return string JSON 문자열
     */
    private function encodeCanonical(mixed $value): string
    {
        return LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($value)));
    }

    /**
     * 노드 name 을 마크업 태그로 바꿉니다 (표기 규칙 — 템플릿과 무관).
     *
     * @param  string  $name  노드 name
     * @return string 태그명
     */
    private function tagFor(string $name): string
    {
        return ComponentRegistry::toTag($name);
    }

    /**
     * 지시자 노드 키(id/if/iteration/slot/… blur 문자열·불리언)를 속성으로 찍습니다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @param  array<int, MarkupAttribute>  $attributes  속성 (참조)
     * @param  array<int, MarkupElement>  $children  자식 (참조 — blur 객체형은 구조 자식)
     * @return void
     */
    private function applyDirectives(array $node, array &$attributes, array &$children): void
    {
        foreach (self::DIRECTIVE_ATTRS as $key => $directive) {
            if (array_key_exists($key, $node)) {
                $attributes[] = $this->attr($directive, (string) $node[$key]);
            }
        }

        if (array_key_exists('trackChanges', $node)) {
            $attributes[] = $this->attr('data-track-changes');
        }

        // `condition` 은 `if` 의 완전 별칭 — 정본 하나로 방출한다. 접는 조건(감싼 식·`if` 부재)은
        // LayoutMarkupRoundTrip::normalize 와 같다(맨 문자열은 접으면 동작이 바뀌므로 escape 유지).
        $condition = array_key_exists('if', $node)
            ? $node['if']
            : ((isset($node['condition']) && is_string($node['condition']) && str_contains($node['condition'], '{{'))
                ? $node['condition']
                : null);

        if ($condition !== null) {
            $attributes[] = $this->conditionAttr('data-if', $condition);
        }

        if (array_key_exists('iteration', $node)) {
            $attributes[] = $this->attr('data-each', $this->eachExpression($node['iteration']));
        }

        if (array_key_exists('blur_until_loaded', $node)) {
            $this->applyBlur($node['blur_until_loaded'], $attributes, $children);
        }
    }

    /**
     * `iteration` 객체를 `data-each` DSL 로 씁니다 (`item[, index] in source`).
     *
     * @param  array<string, mixed>  $iteration  반복 객체
     * @return string data-each 값
     */
    private function eachExpression(array $iteration): string
    {
        $vars = (string) ($iteration['item_var'] ?? 'item');

        if (isset($iteration['index_var'])) {
            $vars .= ', '.$iteration['index_var'];
        }

        return $vars.' in '.($iteration['source'] ?? '');
    }

    /**
     * `blur_until_loaded` 세 형태(객체/문자열/불리언)를 찍습니다.
     *
     * @param  mixed  $blur  blur 값
     * @param  array<int, MarkupAttribute>  $attributes  속성 (참조)
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return void
     */
    private function applyBlur(mixed $blur, array &$attributes, array &$children): void
    {
        if (is_array($blur)) {
            $attrs = [];
            $items = [];

            if (($blur['enabled'] ?? null) !== true) {
                $attrs[] = $this->typedAttr('enabled', $blur['enabled'] ?? false);
            }

            if (array_key_exists('data_sources', $blur)) {
                $sources = $blur['data_sources'];
                // 문자열 리스트는 `<item>` 자식으로 (D51 G16) — `:sources='["a","b"]'` 는 속성에 박힌
                // 통짜 JSON 이라 D2 를 어기고, `<json>` 태그 계량에도 안 잡혀 escape-0 인 채 남는다.
                $structured = is_string($sources) ? null : $this->structuredChildren($sources);

                match (true) {
                    is_string($sources) => $attrs[] = $this->attr('sources', $sources),
                    $structured !== null => $items = $structured,
                    default => $attrs[] = $this->typedAttr('sources', $sources),
                };
            }

            $children[] = $this->el('blur-until-loaded', $attrs, $items);

            return;
        }

        $attributes[] = is_bool($blur)
            ? $this->typedAttr('data-blur-until-loaded', $blur)
            : $this->attr('data-blur-until-loaded', (string) $blur);
    }

    /**
     * 구조 노드 키(classMap/conditions/isolatedState/sortable/itemTemplate/component_layout/responsive)를
     * 전용 태그 자식으로 찍습니다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return void
     */
    private function applyStructural(array $node, array &$children): void
    {
        if (isset($node['classMap'])) {
            $children[] = $this->classMap($node['classMap']);
        }

        if (isset($node['conditions'])) {
            $children[] = $this->conditions($node['conditions']);
        }

        if (isset($node['permissions']) && is_array($node['permissions'])) {
            $children[] = $this->permissionsElement($node['permissions']);
        }

        // 노드 레벨 편집기 메타(editor_label 등) → `<meta>` (평면 스칼라 속성).
        if (isset($node['meta']) && is_array($node['meta'])) {
            $attrs = [];

            foreach ($node['meta'] as $key => $value) {
                $attrs[] = $this->valueAttr((string) $key, $value);
            }

            $children[] = $this->el('meta', $attrs);
        }

        if (isset($node['isolatedState']) && is_array($node['isolatedState'])) {
            $attrs = isset($node['isolatedScopeId']) ? [$this->attr('scope-id', (string) $node['isolatedScopeId'])] : [];
            $children[] = $this->el('isolated-state', $attrs, $this->state->fields($node['isolatedState']));
        }

        if (isset($node['sortable'])) {
            $children[] = $this->sortable($node['sortable']);
        }

        if (isset($node['itemTemplate'])) {
            $children[] = $this->el('item-template', [], [$this->node($node['itemTemplate'])]);
        }

        if (isset($node['component_layout'])) {
            foreach ($node['component_layout'] as $slot => $nodes) {
                $children[] = $this->el('template', [$this->attr('slot', (string) $slot)], $this->nodes($nodes));
            }
        }

        if (isset($node['responsive'])) {
            foreach ($node['responsive'] as $breakpoint => $override) {
                $children[] = $this->responsiveOverride((string) $breakpoint, $override);
            }
        }
    }

    /**
     * `classMap` 를 `<class-map>` + `<variant>` 로 찍습니다.
     *
     * @param  array<string, mixed>  $classMap  classMap 객체
     * @return MarkupElement `<class-map>` 요소
     */
    private function classMap(array $classMap): MarkupElement
    {
        $attributes = [];

        if (array_key_exists('base', $classMap)) {
            $attributes[] = $this->attr('base', (string) $classMap['base']);
        }

        $attributes[] = $this->attr('key', (string) ($classMap['key'] ?? ''));

        if (array_key_exists('default', $classMap)) {
            $attributes[] = $this->attr('default', (string) $classMap['default']);
        }

        $variants = [];

        foreach ($classMap['variants'] ?? [] as $when => $classes) {
            $variants[] = $this->el('variant', [$this->attr('when', (string) $when)], [], (string) $classes);
        }

        return $this->el('class-map', $attributes, $variants);
    }

    /**
     * `conditions` 를 `<all-of>`/`<any-of>` 트리로 찍습니다.
     *
     * @param  array<string, mixed>  $conditions  `{and|or: [...]}`
     * @return MarkupElement 조건 요소
     */
    private function conditions(array $conditions): MarkupElement
    {
        $operator = array_key_exists('and', $conditions) ? 'and' : 'or';
        $tag = $operator === 'and' ? 'all-of' : 'any-of';
        $operands = [];

        foreach ($conditions[$operator] ?? [] as $operand) {
            $operands[] = is_array($operand)
                ? $this->conditions($operand)
                : $this->el('expr', [], [], (string) $operand);
        }

        return $this->el($tag, [], $operands);
    }

    /**
     * `sortable` 를 `<sortable>` 로 찍습니다 (불리언 키는 무접두 불리언 속성).
     *
     * @param  array<string, mixed>  $sortable  sortable 객체
     * @return MarkupElement `<sortable>` 요소
     */
    private function sortable(array $sortable): MarkupElement
    {
        $attributes = [];

        foreach (self::SORTABLE_ATTRS as $key => $attr) {
            if (! array_key_exists($key, $sortable)) {
                continue;
            }

            $attributes[] = is_bool($sortable[$key])
                ? ($sortable[$key] ? $this->attr($attr) : $this->typedAttr($attr, false))
                : $this->attr($attr, (string) $sortable[$key]);
        }

        return $this->el('sortable', $attributes);
    }

    /**
     * responsive 오버라이드 하나를 `<responsive on="bp">` 로 찍습니다 (미니 노드).
     *
     * @param  string  $breakpoint  브레이크포인트
     * @param  array<string, mixed>  $override  오버라이드 객체
     * @return MarkupElement `<responsive>` 요소
     */
    private function responsiveOverride(string $breakpoint, array $override): MarkupElement
    {
        $attributes = [$this->attr('on', $breakpoint)];
        $children = [];

        if (array_key_exists('if', $override)) {
            $attributes[] = $this->conditionAttr('data-if', $override['if']);
        }

        if (array_key_exists('iteration', $override)) {
            $attributes[] = $this->attr('data-each', $this->eachExpression($override['iteration']));
        }

        // 구조 노드 키도 브레이크포인트별로 오버라이드된다(portable 전용 classMap 실사용).
        if (isset($override['classMap'])) {
            $children[] = $this->classMap($override['classMap']);
        }

        $this->applyProps($override['props'] ?? [], '', false, $attributes, $children);

        foreach ($override['children'] ?? [] as $child) {
            $children[] = $this->node($child);
        }

        $text = array_key_exists('text', $override) ? (string) $override['text'] : null;

        return $this->el('responsive', $attributes, $children, $text, $override['comment'] ?? null);
    }

    /**
     * props 를 속성/`<prop>`/임베딩 태그로 찍습니다.
     *
     * @param  array<string, mixed>  $props  props 객체
     * @param  string  $componentName  소유 컴포넌트 name (임베딩 종전 부모 판별용)
     * @param  bool  $isVoid  소유 노드가 void 태그인지 (객체 props 를 `<prop>` 대신 타입 속성으로)
     * @param  array<int, MarkupAttribute>  $attributes  속성 (참조)
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return void
     */
    private function applyProps(array $props, string $componentName, bool $isVoid, array &$attributes, array &$children): void
    {
        foreach ($props as $key => $value) {
            if (! $isVoid && $this->applyEmbedding((string) $key, $value, $props, $componentName, $children)) {
                continue;
            }

            $alias = self::PROPS_ALIASES[$key] ?? $key;

            // 예약 이름(컴파일러가 무접두 속성으로 거부)은 `<prop>` 로 — bare 속성 거부를 피한다.
            if (in_array($alias, self::RESERVED_PROP_NAMES, true)) {
                $children[] = $this->propElement($alias, $value);
            } elseif (is_string($value)) {
                $attributes[] = $this->attr($alias, $value);
            } elseif (is_array($value) && ! $isVoid) {
                $children[] = $this->propElement($alias, $value);
            } else {
                $attributes[] = $this->typedAttr($alias, $value);
            }
        }
    }

    /**
     * 값이 `<switch-value>` 로 되돌릴 수 있는 조건부 값 객체인지 판정합니다.
     *
     * 계약은 `EmbeddingCompiler::applySwitchValue` 와 짝이다 — `$switch`(선택식)와 `$cases`(맵)가
     * 필수, `$default` 는 선택. `$cases` 값과 `$default` 는 문자열이라야 `<case>`/`<default>` 본문
     * 텍스트로 실린다(컴파일러가 `(string) $child->text` 로 읽으므로 중첩 객체는 표현 불가).
     *
     * @param  mixed  $value  props 값
     * @return bool `<switch-value>` 로 표현 가능하면 true
     */
    private function isSwitchValue(mixed $value): bool
    {
        if (! is_array($value) || ! isset($value['$switch'], $value['$cases']) || ! is_string($value['$switch'])) {
            return false;
        }

        if (! is_array($value['$cases']) || $value['$cases'] === [] || array_is_list($value['$cases'])) {
            return false;
        }

        foreach ($value['$cases'] as $case) {
            if (! is_string($case)) {
                return false;
            }
        }

        if (array_key_exists('$default', $value) && ! is_string($value['$default'])) {
            return false;
        }

        // 컴파일러가 되돌려 놓는 키는 이 셋뿐이다 — 다른 키가 섞이면 왕복이 깨지므로 blob 유지.
        return array_diff(array_keys($value), ['$switch', '$cases', '$default']) === [];
    }

    /**
     * 조건부 값 객체를 `<switch-value prop on>` + `<case when>`/`<default>` 로 찍습니다.
     *
     * @param  string  $key  props 키
     * @param  array<string, mixed>  $value  `{$switch, $cases, $default}`
     * @return MarkupElement `<switch-value>` 요소
     */
    private function switchValueElement(string $key, array $value): MarkupElement
    {
        $children = [];

        foreach ($value['$cases'] as $when => $text) {
            $children[] = $this->el('case', [$this->attr('when', (string) $when)], [], (string) $text);
        }

        if (array_key_exists('$default', $value)) {
            $children[] = $this->el('default', [], [], (string) $value['$default']);
        }

        return $this->el(
            'switch-value',
            [$this->attr('prop', $key), $this->attr('on', (string) $value['$switch'])],
            $children,
        );
    }

    /**
     * props 임베딩 hoist 키면 전용 태그로 찍고 true 를 반환합니다.
     *
     * @param  string  $key  props 키
     * @param  mixed  $value  값
     * @param  array<string, mixed>  $props  전체 props (options 리프트 판정용)
     * @param  string  $componentName  소유 컴포넌트 name (종전 부모 판별용 — responsive 오버라이드는 빈 문자열)
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return bool 임베딩으로 처리했으면 true
     */
    private function applyEmbedding(string $key, mixed $value, array $props, string $componentName, array &$children): bool
    {
        if (! is_array($value)) {
            return false;
        }

        // 조건부 값(`{$switch, $cases, $default}`)은 1급 문법이 있다 — `<switch-value>` 로 되돌린다(D41).
        // 안 되돌리면 `<prop>` 본문에 통짜 JSON 으로 나가는데, `<json>` 태그가 아니라서 escape 계량에
        // 잡히지도 않는다(검수에서 직접 발견한 자리).
        if ($this->isSwitchValue($value)) {
            $children[] = $this->switchValueElement($key, $value);

            return true;
        }

        if (isset(self::NODE_ARRAY_HOISTS[$key])) {
            $children[] = $this->el(self::NODE_ARRAY_HOISTS[$key], [], $this->nodes($value));

            return true;
        }

        // 종전 부모(DataGrid·그리드·Modal·Select)의 방출은 **한 글자도 바꾸지 않는다** — 기존 소스·산출
        // 대조가 이 형태를 전제로 한다. 컴파일러는 이제 부모 이름을 보지 않으므로(2026-09-28 결정)
        // 그 밖의 부모에도 같은 태그를 찍을 수 있지만, 거기서는 되컴파일로 이 키 하나를 확인한 뒤에만
        // 찍는다(verifiedHoist) — 어긋나면 노드 전체 검증이 props 통째를 `<json>` 으로 빼기 때문이다.
        $legacy = match ($key) {
            'columns' => $componentName === 'DataGrid' ? $this->appendColumns($value, $children) : null,
            'cardColumns' => in_array($componentName, EmbeddingCompiler::LEGACY_TAGS['card']['parents'], true) || $componentName === ''
                ? $this->appendCards($value, $children)
                : null,
            'footerCells' => $componentName === 'DataGrid' ? $this->appendFooterCells($value, $children) : null,
            'footer' => in_array($componentName, EmbeddingCompiler::LEGACY_TAGS['footer']['parents'], true)
                ? $this->appendWrap('footer', $value, $children)
                : null,
            'options' => $componentName === 'Select' ? $this->appendOptions($value, $props, $children) : null,
            default => false,
        };

        // responsive 오버라이드(`$componentName === ''`)는 임베딩 태그를 읽지 않는다 — 종전대로 `<prop>`.
        if ($legacy !== null || $componentName === '') {
            return (bool) $legacy;
        }

        $elements = match ($key) {
            'columns' => $this->columnElements($value),
            'cardColumns' => $this->hoistElements($value, fn (array &$out): bool => $this->appendCards($value, $out, 'prop-card')),
            'footerCells' => $this->hoistElements($value, fn (array &$out): bool => $this->appendFooterCells($value, $out)),
            'footer' => $this->hoistElements($value, fn (array &$out): bool => $this->appendWrap('prop-footer', $value, $out)),
            'options' => $this->hoistElements($value, fn (array &$out): bool => $this->appendOptions($value, $props, $out)),
            default => null,
        };

        if ($elements === null || ! $this->verifiedHoist((string) $key, $value, $elements)) {
            return false;
        }

        array_push($children, ...$elements);

        return true;
    }

    /**
     * 종전 부모 밖에서 쓸 hoist 요소를 짓습니다 (값이 리스트가 아니면 짓지 않는다).
     *
     * @param  array<array-key, mixed>  $value  props 값
     * @param  callable(array<int, MarkupElement>&): bool  $build  요소를 밀어 넣는 빌더
     * @return array<int, MarkupElement>|null 요소 목록, 지을 수 없으면 null
     */
    private function hoistElements(array $value, callable $build): ?array
    {
        if ($value === [] || ! array_is_list($value)) {
            return null;
        }

        $out = [];

        try {
            return $build($out) ? $out : null;
        } catch (\Throwable) {
            // 원소 shape 가 빌더 전제(객체 원소·노드 배열)와 다르면 태그로 되돌리지 않는다 — `<prop>` 폴백.
            return null;
        }
    }

    /**
     * hoist 요소가 되컴파일에서 원본 props 값 하나를 그대로 재현하는지 확인합니다.
     *
     * 노드 단위 검증(component)은 어긋나는 키를 통째로 `<json>` 탈출시키므로, 표현 가능 여부가 불확실한
     * 새 자리(종전 부모 밖)에서는 이 키만 먼저 대조해 어긋나면 종전 `<prop>` 표기로 물러선다.
     *
     * @param  string  $key  props 키
     * @param  mixed  $value  원본 값
     * @param  array<int, MarkupElement>  $elements  방출할 hoist 요소
     * @return bool 재현되면 true
     */
    private function verifiedHoist(string $key, mixed $value, array $elements): bool
    {
        $source = ['type' => NodeCompiler::DEFAULT_COMPONENT_TYPE, 'name' => 'Div', 'props' => [$key => $value]];

        try {
            $recompiled = $this->compiler->compile($this->el('div', [], $elements));
        } catch (\Throwable) {
            return false;
        }

        return $this->encodeCanonical($source) === $this->encodeCanonical($recompiled);
    }

    /**
     * 노드 배열을 자식 요소 목록으로 되돌립니다.
     *
     * @param  array<int, array<string, mixed>>  $nodes  노드 JSON 목록
     * @return array<int, MarkupElement> 요소 목록
     */
    private function nodes(array $nodes): array
    {
        return array_map(fn (array $node): MarkupElement => $this->node($node), $nodes);
    }

    /**
     * 노드 배열을 하나의 래퍼 태그 자식으로 찍습니다.
     *
     * @param  string  $tag  래퍼 태그
     * @param  array<int, array<string, mixed>>  $nodes  노드 목록
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return bool 항상 true
     */
    private function appendWrap(string $tag, array $nodes, array &$children): bool
    {
        $children[] = $this->el($tag, [], $this->nodes($nodes));

        return true;
    }

    /**
     * `columns` 를 `<column>` 요소 목록으로 되돌립니다 (D42 G7 — 확장 주입 `<props>` 진입점).
     *
     * 되돌릴 수 없는 형태(리스트가 아님·원소가 객체가 아님·`field` 없음)면 **null** 을 돌려주고
     * 호출자가 종전 JSON 표기로 떨어뜨린다 — `EmbeddingCompiler::compileColumn` 이 `field` 를
     * 필수로 요구하므로, 없는 채로 찍으면 되컴파일이 죽어 주입 props 전체가 `<json>` 으로 탈출한다.
     *
     * @param  array<array-key, mixed>  $columns  컬럼 목록 후보
     * @return array<int, MarkupElement>|null `<column>` 요소 목록, 표현 불가면 null
     */
    public function columnElements(array $columns): ?array
    {
        if ($columns === [] || ! array_is_list($columns)) {
            return null;
        }

        foreach ($columns as $column) {
            if (! is_array($column) || array_is_list($column) || ! array_key_exists('field', $column)) {
                return null;
            }
        }

        $children = [];
        $this->appendColumns($columns, $children);

        return $children;
    }

    /**
     * `columns` 를 `<column>`(+`<cell>`) 자식으로 찍습니다.
     *
     * @param  array<int, array<string, mixed>>  $columns  컬럼 목록
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return bool 항상 true
     */
    private function appendColumns(array $columns, array &$children): bool
    {
        foreach ($columns as $column) {
            $cell = $column['cellChildren'] ?? null;
            unset($column['cellChildren']);

            $body = $cell !== null ? [$this->el('cell', [], $this->nodes($cell))] : [];
            $children[] = $this->el('column', $this->scalarAttrs($column), $body);
        }

        return true;
    }

    /**
     * `cardColumns` 를 카드 태그 자식으로 찍습니다 (종전 부모는 `<card>`, 그 밖은 `<prop-card>`).
     *
     * @param  array<int, array<string, mixed>>  $cards  카드 목록
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @param  string  $tag  방출 태그
     * @return bool 항상 true
     */
    private function appendCards(array $cards, array &$children, string $tag = 'card'): bool
    {
        foreach ($cards as $card) {
            $cell = $card['cellChildren'] ?? [];
            $attributes = [$this->attr('id', (string) ($card['id'] ?? ''))];
            $children[] = $this->el($tag, $attributes, $this->nodes($cell));
        }

        return true;
    }

    /**
     * `footerCells` 를 `<footer-cell>` 자식으로 찍습니다 (children 있으면 본문, 없으면 스칼라 속성).
     *
     * @param  array<int, array<string, mixed>>  $cells  푸터 셀 목록
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return bool 항상 true
     */
    private function appendFooterCells(array $cells, array &$children): bool
    {
        foreach ($cells as $cell) {
            $body = $cell['children'] ?? null;
            unset($cell['children']);

            $childNodes = $body !== null ? $this->nodes($body) : [];
            $children[] = $this->el('footer-cell', $this->scalarAttrs($cell), $childNodes);
        }

        return true;
    }

    /**
     * `options` 를 정적 데이터로 찍을 수 있으면 `<options>` 래퍼 자식으로 방출합니다 (G13).
     *
     * 래퍼가 "이건 데이터다" 를 말한다 — 래퍼 없는 직속 `<option>` 은 Option 컴포넌트 노드이며
     * 컴파일러가 절대 접지 않는다. Select 는 `props.options` 유무로 렌더 컴포넌트가 갈리므로
     * (커스텀 드롭다운 vs 네이티브 select) 이 구분은 표기 층에서 결정적이어야 한다.
     *
     * @param  array<int, mixed>  $options  옵션 목록
     * @param  array<string, mixed>  $props  전체 props
     * @param  array<int, MarkupElement>  $children  자식 (참조)
     * @return bool 방출했으면 true, 아니면 false (→ `<prop>` 폴백)
     */
    private function appendOptions(array $options, array $props, array &$children): bool
    {
        if (! $this->liftableOptions($options)) {
            return false;
        }

        $items = [];

        foreach ($options as $option) {
            $attributes = [is_string($option['value'])
                ? $this->attr('value', $option['value'])
                : $this->typedAttr('value', $option['value'])];

            if (($option['disabled'] ?? false) === true) {
                $attributes[] = $this->attr('disabled');
            }

            $items[] = $this->el('option', $attributes, [], (string) ($option['label'] ?? ''));
        }

        $children[] = $this->el('options', [], $items);

        return true;
    }

    /**
     * 옵션 목록이 정적 리프트 대상인지 판정합니다 (value/label/disabled 만, 표현식 아님).
     *
     * @param  array<int, mixed>  $options  옵션 목록
     * @return bool 리프트 가능하면 true
     */
    private function liftableOptions(array $options): bool
    {
        if ($options === []) {
            return false;
        }

        foreach ($options as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option)) {
                return false;
            }

            if (array_diff(array_keys($option), ['value', 'label', 'disabled']) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * 스칼라 객체를 속성 목록으로 찍습니다 (className→class, 문자열=무접두, 그 밖=타입 리터럴).
     *
     * @param  array<string, mixed>  $object  스칼라 객체
     * @return array<int, MarkupAttribute> 속성 목록
     */
    private function scalarAttrs(array $object): array
    {
        $attributes = [];

        foreach ($object as $key => $value) {
            $alias = self::PROPS_ALIASES[$key] ?? $key;
            $attributes[] = is_string($value) ? $this->attr($alias, $value) : $this->typedAttr($alias, $value);
        }

        return $attributes;
    }

    /**
     * `<slot name>` 자리표시 노드를 찍습니다.
     *
     * @param  array<string, mixed>  $node  슬롯 노드 JSON
     * @return MarkupElement `<slot>` 요소
     */
    private function slotNode(array $node): MarkupElement
    {
        $children = $this->nodes($node['children'] ?? []);

        return $this->el('slot', [$this->attr('name', (string) ($node['name'] ?? ''))], $children, null, $node['comment'] ?? null);
    }

    /**
     * `<extension-point>` 노드를 찍습니다.
     *
     * @param  array<string, mixed>  $node  extension_point 노드 JSON
     * @return MarkupElement `<extension-point>` 요소
     */
    private function extensionPoint(array $node): MarkupElement
    {
        $attributes = [$this->attr('name', (string) ($node['name'] ?? ''))];
        $children = [];

        // 지시자(id→data-ref·if→data-if 등)는 컴파일러 applyAttribute 가 extension-point 에서도 처리한다.
        $this->applyDirectives($node, $attributes, $children);

        foreach ($node['props'] ?? [] as $key => $value) {
            // props 키 'name' 은 태그의 주입점 name 과 충돌하므로 강제로 <prop name="name">.
            if ($key === 'name') {
                $children[] = $this->propElement('name', $value);

                continue;
            }

            $alias = self::PROPS_ALIASES[$key] ?? $key;

            if (is_string($value)) {
                $attributes[] = $this->attr($alias, $value);
            } elseif (is_array($value)) {
                $children[] = $this->propElement($alias, $value);
            } else {
                $attributes[] = $this->typedAttr($alias, $value);
            }
        }

        if (isset($node['default'])) {
            $children[] = $this->el('default', [], $this->nodes($node['default']));
        }

        foreach ($node['callbacks'] ?? [] as $name => $action) {
            $children[] = $this->el('callback', [$this->attr('name', (string) $name)], [$this->callbackAction($action)]);
        }

        return $this->el('extension-point', $attributes, $children, null, $node['comment'] ?? null);
    }

    /**
     * extension_point 콜백 액션 하나를 요소로 찍습니다.
     *
     * @param  array<string, mixed>  $action  액션 JSON
     * @return MarkupElement 액션 요소
     */
    private function callbackAction(array $action): MarkupElement
    {
        return $this->actions->initAction($action);
    }
}
