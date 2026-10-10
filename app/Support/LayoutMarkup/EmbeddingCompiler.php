<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 노드 서브트리를 props/노드 키로 끌어올리는(hoist) 임베딩 층 (매핑 사전 D — S5).
 *
 * JSON 레이아웃에는 HTML 자식으로 곧장 표기할 수 없는 자리가 있다. `DataGrid.columns[].cellChildren`
 * 처럼 **props 안의 배열 원소가 다시 노드 트리를 품거나**, `responsive.{bp}` 처럼 **노드 키가
 * 브레이크포인트별 부분 노드를 품는** 지점이다. 작성자는 이를 이름 있는 자식 태그로 쓰고
 * (`<column><cell>…</cell></column>`), 이 층이 그 서브트리를 컴파일해 제자리로 끌어올린다.
 *
 * 이 층이 지키는 규칙은 셋이다.
 *
 * **① 태그가 자리를 정하고, 부모 이름은 보지 않는다 (2026-09-28 결정).** `<column>` 은 어느 부모
 * 아래서든 `props.columns` 원소, `<title-prefix>` 는 어느 부모 아래서든 `props.titlePrefix` 다 — 부모
 * 컴포넌트 이름(DataGrid·Modal)을 보면 번들 템플릿의 컴포넌트 이름에 문법이 묶여, 다른 템플릿의 같은
 * 역할 컴포넌트가 이 표기를 쓸 수 없다. 그 prop 을 받는 컴포넌트인지는 대상 템플릿이 분명한 자리에서
 * lint 가 선언으로 판정한다(LayoutLinter ⑧). 컴포넌트 이름과 겹치는 옛 표기(`<card>`·`<footer>`)만은
 * 종전 부모 아래에서 hoist 로 읽고 그 밖에서는 컴포넌트다 — 부모와 무관한 새 표기는 `<prop-card>`·
 * `<prop-footer>` 다(LEGACY_TAGS). 옵션 데이터는 처음부터 부모와 무관한 `<options>` 래퍼가 맡는다.
 *
 * **② 서브트리 hoist 와 표현식-리프 `<prop>` 은 다른 자리다.** `<expand>` 는 노드 트리를
 * `expandChildren` 으로 끌어올리지만, `expandContext`·`subRowCondition` 은 `{키: "{{식}}"}` 표현식
 * 리프라 서브트리가 아니다 — 그건 노드 층의 `<prop>` 이 맡는다. 둘을 섞으면 선평가 함정에 빠진다.
 *
 * **③ 선평가하지 않는다.** 끌어올린 서브트리 안의 `{{...}}`/`$t:defer:` 는 원문 그대로 둔다 —
 * 런타임 skipBindingKeys(DEFAULT 4종 + 컴포넌트별 선언)와 같은 목록을 공유한다. 애초에 노드
 * 컴파일러가 값을 풀지 않으므로 서브트리를 그 컴파일러로 넘기는 한 패리티는 자동으로 성립한다.
 *
 * extension_point 노드(`<extension-point>`)와 상태·정적 옵션 리프트는 노드 컴파일러가 직접
 * 다루고(같은 노드 층의 책임), 이 층은 **자식 hoist** 만 맡는다.
 */
final class EmbeddingCompiler
{
    /**
     * 부모 이름과 무관하게 props 배열 키로 끌어올리는 자식 태그 → props 키.
     *
     * `<column>`·`<footer-cell>`·`<prop-card>` 는 자기 스칼라 필드를 갖는 객체 원소를 배열에 쌓고
     * (ELEMENT_HOISTS), 나머지는 컴파일한 노드 배열을 통째로 그 키에 놓는다. 어느 쪽도 부모 컴포넌트
     * 이름을 보지 않는다 — 그 prop 을 받는 컴포넌트인지는 템플릿 선언의 몫이다(LayoutLinter ⑧).
     *
     * **public 인 이유**: 역변환기(`NodeMarkupDecompiler`)와 lint 가 같은 표를 본다. 목록을 저쪽에
     * 복제하면 hoist 를 하나 더하는 날 한쪽만 조용히 반쪽이 된다.
     *
     * @var array<string, string>
     */
    public const HOIST_TAGS = [
        'column' => 'columns',
        'expand' => 'expandChildren',
        'sub-row' => 'subRowChildren',
        'footer-cell' => 'footerCells',
        'footer-card' => 'footerCardChildren',
        'prop-card' => 'cardColumns',
        'title-prefix' => 'titlePrefix',
        'title-suffix' => 'titleSuffix',
        'prop-footer' => 'footer',
    ];

    /** 원소 하나씩 배열에 쌓는 hoist 태그 (나머지 HOIST_TAGS 는 노드 배열을 통째로 한 번만 놓는다) */
    private const ELEMENT_HOISTS = ['column', 'footer-cell', 'prop-card'];

    /**
     * 컴포넌트 이름과 겹치는 옛 hoist 표기 → 부모와 무관한 새 표기·옛 표기를 hoist 로 읽는 부모.
     *
     * `<card>`(Card)·`<footer>`(Footer, HTML `<footer>`)는 태그 이름이 컴포넌트와 겹쳐, 부모 이름 없이는
     * 컴포넌트인지 hoist 인지 가를 수 없다. 기존 소스를 고치지 않도록 **종전 부모 아래에서만** 옛 뜻을
     * 유지하고, 그 밖에서는 컴포넌트다. 새로 쓰는 소스는 어느 부모 아래서나 같은 뜻인 새 표기를 쓴다.
     *
     * @var array<string, array{tag: string, parents: array<int, string>}>
     */
    public const LEGACY_TAGS = [
        'card' => ['tag' => 'prop-card', 'parents' => ['DataGrid', 'CardGrid']],
        'footer' => ['tag' => 'prop-footer', 'parents' => ['Modal']],
    ];

    /**
     * 대상 템플릿의 선언으로 "받는 컴포넌트인가" 를 판정하는 hoist props 키 (LayoutLinter ⑧).
     *
     * 전 hoist 키로 넓히지 않는 이유는 실측이다(2026-09-28, 번들 레이아웃 전수 × 번들 두 템플릿) —
     * `DataGrid.expandChildren`·`Select.options` 처럼 **선언하지 않고도 렌더하는** 조합이 수백 건이라
     * 선언 대조가 곧 오탐이다(D19). 이 세 키는 거짓 양성 0 건이고, 양성(`sirsoft-basic` Modal 의
     * `footer` 11 건·`titlePrefix` 1 건)은 실제로 렌더되지 않는 자리다.
     *
     * @var array<int, string>
     */
    public const DECLARATION_CHECKED_PROPS = ['footer', 'titlePrefix', 'titleSuffix'];

    /** 부모 문맥 없이 항상 임베딩인 자식 태그 (HOIST_TAGS 외 — 노드 키 hoist·홀로 오면 자리 오류인 태그) */
    private const CONTEXT_FREE_TAGS = [
        'cell', 'responsive', 'template', 'item-template', 'switch-value', 'default', 'callback',
    ];

    /**
     * @param  NodeCompiler  $nodes  서브트리를 노드로 컴파일할 노드 컴파일러 (역참조)
     */
    public function __construct(private readonly NodeCompiler $nodes) {}

    /**
     * 이 태그가 주어진 부모 아래에서 임베딩 자식으로 처리되는지 판정합니다.
     *
     * @param  string  $tag  자식 태그명
     * @param  ComponentDefinition  $parent  부모 컴포넌트 정의
     * @return bool 임베딩 자식이면 true
     */
    public function handlesChild(string $tag, ComponentDefinition $parent): bool
    {
        if (isset(self::HOIST_TAGS[$tag]) || in_array($tag, self::CONTEXT_FREE_TAGS, true)) {
            return true;
        }

        // 옛 표기(`<card>`·`<footer>`)는 종전 부모 아래에서만 hoist — 그 밖에서는 Card·Footer 컴포넌트다.
        return isset(self::LEGACY_TAGS[$tag]) && in_array($parent->name, self::LEGACY_TAGS[$tag]['parents'], true);
    }

    /**
     * props 키를 되돌릴 hoist 태그를 고릅니다 (역변환·lint 안내 공용).
     *
     * 옛 표기의 종전 부모면 옛 태그(`<card>`·`<footer>`)를, 그 밖이면 부모와 무관한 표기를 돌려준다 —
     * 기존 소스의 역변환 산출이 한 글자도 바뀌지 않게 하는 자리다.
     *
     * @param  string  $prop  props 키 (`cardColumns`·`footer` 등)
     * @param  string  $parentName  소유 컴포넌트 name
     * @return string|null hoist 태그, hoist 키가 아니면 null
     */
    public static function tagForProp(string $prop, string $parentName): ?string
    {
        $tag = array_search($prop, self::HOIST_TAGS, true);

        if ($tag === false) {
            return null;
        }

        foreach (self::LEGACY_TAGS as $legacy => $spec) {
            if ($spec['tag'] === $tag && in_array($parentName, $spec['parents'], true)) {
                return $legacy;
            }
        }

        return $tag;
    }

    /**
     * 임베딩 자식 하나를 부모 노드의 props 또는 노드 키로 끌어올립니다.
     *
     * props 배열 키(columns/footerCells 등)는 `$hoist` 에 누적했다가 노드 컴파일러가 마지막에
     * `<prop>`·`:attr` 로 이미 찬 자리와 충돌 검사를 거쳐 props 로 옮긴다. 노드 키(responsive/
     * component_layout/itemTemplate)와 props 값(switch-value)은 여기서 바로 배치한다.
     *
     * @param  MarkupElement  $child  임베딩 자식 요소
     * @param  ComponentDefinition  $parent  부모 컴포넌트 정의
     * @param  array<string, mixed>  $node  부모 노드 (참조)
     * @param  array<string, mixed>  $props  부모 props (참조)
     * @param  array<string, mixed>  $hoist  props 배열 hoist 누적 버퍼 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 홀로 올 수 없는 태그이거나 표기 규칙을 벗어난 경우
     */
    public function applyChild(
        MarkupElement $child,
        ComponentDefinition $parent,
        array &$node,
        array &$props,
        array &$hoist,
        ?string $path,
    ): void {
        // 옛 표기는 handlesChild 가 종전 부모일 때만 여기로 보낸다 — 새 표기와 같은 규칙으로 컴파일한다.
        $tag = self::LEGACY_TAGS[$child->name]['tag'] ?? $child->name;

        if (isset(self::HOIST_TAGS[$tag])) {
            $this->applyHoistChild($child, $tag, $hoist, $path);

            return;
        }

        switch ($child->name) {
            case 'responsive':
                $this->applyResponsive($child, $node, $path);

                return;

            case 'template':
                $this->applyComponentLayout($child, $node, $path);

                return;

            case 'item-template':
                $this->applyItemTemplate($child, $node, $path);

                return;

            case 'switch-value':
                $this->applySwitchValue($child, $props, $path);

                return;

            case 'cell':
                // 홀로 온 `<cell>` — `<column>` 안에서만 뜻이 있다.
                throw $this->wrongParent($child, 'column', $parent->tag, $path);
            case 'default':
            case 'callback':
                // 홀로 온 `<default>`/`<callback>` — `<extension-point>` 안에서만 뜻이 있다.
                throw $this->wrongParent($child, 'extension-point', $parent->tag, $path);
        }
    }

    /**
     * props 배열 hoist 자식(`<column>`/`<expand>`/`<prop-footer>`/…)을 hoist 버퍼에 누적합니다.
     *
     * @param  MarkupElement  $child  자식 요소
     * @param  string  $tag  부모와 무관한 표기로 맞춘 태그명 (옛 표기면 새 표기)
     * @param  array<string, mixed>  $hoist  hoist 버퍼 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyHoistChild(MarkupElement $child, string $tag, array &$hoist, ?string $path): void
    {
        $key = self::HOIST_TAGS[$tag];

        // `<column>`·`<footer-cell>`·`<prop-card>` 는 스칼라 필드를 함께 갖는 객체 원소라 배열에 밀어
        // 넣고, 나머지는 컴파일한 노드 배열을 통째로 그 키에 놓는다.
        if (in_array($tag, self::ELEMENT_HOISTS, true)) {
            $hoist[$key][] = match ($tag) {
                'column' => $this->compileColumn($child, $path),
                'footer-cell' => $this->compileFooterCell($child, $path),
                default => $this->compileCard($child, $path),
            };

            return;
        }

        // 단일 배열 키 — 같은 태그를 두 번 쓰면 앞엣것이 조용히 사라진다.
        if (isset($hoist[$key])) {
            throw $this->error($child, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => $key]);
        }

        $hoist[$key] = $this->compileChildNodes($child, $path);
    }

    /**
     * `<column>` 자식들을 columns 배열로 컴파일합니다 (D42 G7 — 확장 주입 `<props>` 전용 진입점).
     *
     * 노드 자리의 `<column>` 은 부모 노드의 `props.columns` 로 끌어올리지만(applyChild), 확장
     * 주입 fragment 에는 부모 컴포넌트가 없다 — 대상 노드가 **다른 레이아웃에** 있기 때문이다.
     * 그 자리에서는 `<prop name="columns">` 컨테이너가 소유자를 대신하고, 게이트는 그 컨테이너
     * 안으로만 열린다(`NodeCompiler::compilePropValue` 의 `$allowColumnTree`). 컬럼 원소를 만드는
     * 규칙 자체는 노드 자리와 **완전히 같은 코드**를 쓴다 — 갈라지면 같은 컬럼이 자리에 따라 다른
     * JSON 이 된다.
     *
     * @param  MarkupElement  $element  `<prop name="columns">` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> columns 배열
     *
     * @throws CompileError `<column>` 외 자식이 있거나 컬럼 표기가 규칙을 벗어난 경우
     */
    public function compileColumnList(MarkupElement $element, ?string $path): array
    {
        $columns = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'column') {
                throw $this->unexpectedChild($child, $element->name, $path);
            }

            $columns[] = $this->compileColumn($child, $path);
        }

        return $columns;
    }

    /**
     * `<column>` 을 columns 배열 원소(스칼라 필드 + cellChildren)로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<column>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 컬럼 서술 객체
     *
     * @throws CompileError field 가 없거나 `<cell>` 외 자식이 있는 경우
     */
    private function compileColumn(MarkupElement $element, ?string $path): array
    {
        $column = $this->attributesToObject($element, $path);

        if (! array_key_exists('field', $column)) {
            throw $this->missingAttribute($element, 'field', $path);
        }

        $cellChildren = null;

        foreach ($element->children as $child) {
            if ($child->name !== 'cell') {
                throw $this->unexpectedChild($child, 'column', $path);
            }

            if ($cellChildren !== null) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => 'cellChildren']);
            }

            $cellChildren = $this->compileChildNodes($child, $path);
        }

        if ($cellChildren !== null) {
            $column['cellChildren'] = $cellChildren;
        }

        return $column;
    }

    /**
     * `<footer-cell>` 을 footerCells 배열 원소로 컴파일합니다.
     *
     * `{field, children}`(복합 셀) 또는 `{field, text, className}`(단순 텍스트 셀) 또는 `{field}`
     * (빈 셀) 세 형태를 모두 보존한다.
     *
     * @param  MarkupElement  $element  `<footer-cell>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 푸터 셀 객체
     *
     * @throws CompileError field 가 없거나 텍스트와 자식이 함께 있는 경우
     */
    private function compileFooterCell(MarkupElement $element, ?string $path): array
    {
        $cell = $this->attributesToObject($element, $path);

        if (! array_key_exists('field', $cell)) {
            throw $this->missingAttribute($element, 'field', $path);
        }

        $children = $this->compileChildNodes($element, $path);

        if ($children !== [] && $element->text !== null && trim($element->text) !== '') {
            throw $this->error($element, LayoutMarkupErrorCode::MixedTextAndChildren, $path, [
                'tag' => $element->name,
                'found' => trim($element->text),
            ]);
        }

        if ($children !== []) {
            $cell['children'] = $children;
        } elseif ($element->text !== null) {
            $cell['text'] = $element->text;
        }

        return $cell;
    }

    /**
     * `<prop-card>`(옛 표기 `<card>`)를 cardColumns 배열 원소(`{id, cellChildren}`)로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<prop-card>`·`<card>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 카드 컬럼 객체
     *
     * @throws CompileError id 가 없는 경우
     */
    private function compileCard(MarkupElement $element, ?string $path): array
    {
        $card = ['id' => $this->requiredAttribute($element, 'id', $path)];
        $cellChildren = $this->compileChildNodes($element, $path);

        if ($cellChildren !== []) {
            $card['cellChildren'] = $cellChildren;
        }

        return $card;
    }

    /**
     * `<responsive on="{bp}">` 을 responsive 노드 키의 브레이크포인트 항목으로 배치합니다.
     *
     * @param  MarkupElement  $element  `<responsive>` 요소
     * @param  array<string, mixed>  $node  부모 노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError on 이 없거나 같은 브레이크포인트가 중복된 경우
     */
    private function applyResponsive(MarkupElement $element, array &$node, ?string $path): void
    {
        $breakpoint = $this->requiredAttribute($element, 'on', $path);

        if (isset($node['responsive'][$breakpoint])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateNodeKey, $path, [
                'key' => 'responsive.'.$breakpoint,
            ]);
        }

        $node['responsive'][$breakpoint] = $this->nodes->compileResponsiveOverride($element, $path);
    }

    /**
     * `<template slot="{name}">` 을 component_layout 명명 슬롯으로 배치합니다.
     *
     * @param  MarkupElement  $element  `<template>` 요소
     * @param  array<string, mixed>  $node  부모 노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError slot 이 없거나 같은 슬롯이 중복된 경우
     */
    private function applyComponentLayout(MarkupElement $element, array &$node, ?string $path): void
    {
        $slot = $this->requiredAttribute($element, 'slot', $path);

        if (isset($node['component_layout'][$slot])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateNodeKey, $path, [
                'key' => 'component_layout.'.$slot,
            ]);
        }

        $node['component_layout'][$slot] = $this->compileChildNodes($element, $path);
    }

    /**
     * `<item-template>` 을 itemTemplate 노드 키(단일 노드)로 배치합니다.
     *
     * @param  MarkupElement  $element  `<item-template>` 요소
     * @param  array<string, mixed>  $node  부모 노드 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이미 지정됐거나 자식이 정확히 하나가 아닌 경우
     */
    private function applyItemTemplate(MarkupElement $element, array &$node, ?string $path): void
    {
        if (isset($node['itemTemplate'])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => 'itemTemplate']);
        }

        if (count($element->children) !== 1) {
            throw $this->error($element, LayoutMarkupErrorCode::EmbeddingSingleNodeRequired, $path, ['tag' => $element->name]);
        }

        $node['itemTemplate'] = $this->nodes->compile($element->children[0], $path);
    }

    /**
     * `<switch-value prop="{key}" on="{식}">` 을 props 의 `$switch` 객체 값으로 배치합니다.
     *
     * `{$switch, $cases, $default}` — engine-v1.9.0+ 의 조건부 값 표기다. `on` 은 조건이 아니라
     * 값 선택식이므로 정규화하지 않고 원문으로 싣는다.
     *
     * @param  MarkupElement  $element  `<switch-value>` 요소
     * @param  array<string, mixed>  $props  부모 props (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError prop·on 이 없거나 자식이 `<case>`/`<default>` 가 아닌 경우
     */
    private function applySwitchValue(MarkupElement $element, array &$props, ?string $path): void
    {
        $prop = $this->requiredAttribute($element, 'prop', $path);
        $on = $this->requiredAttribute($element, 'on', $path);

        $cases = [];
        $default = null;

        foreach ($element->children as $child) {
            switch ($child->name) {
                case 'case':
                    $when = $this->requiredAttribute($child, 'when', $path);

                    if (array_key_exists($when, $cases)) {
                        throw $this->error($child, LayoutMarkupErrorCode::DuplicateCaseKey, $path, ['case' => $when]);
                    }

                    $cases[$when] = (string) ($child->text ?? '');
                    break;

                case 'default':
                    if ($default !== null) {
                        throw $this->error($child, LayoutMarkupErrorCode::DuplicateCaseKey, $path, ['case' => 'default']);
                    }

                    $default = (string) ($child->text ?? '');
                    break;

                default:
                    throw $this->unexpectedChild($child, 'switch-value', $path);
            }
        }

        $value = ['$switch' => $on, '$cases' => $cases];

        if ($default !== null) {
            $value['$default'] = $default;
        }

        $this->assignProp($props, $prop, $value, $element, $path);
    }

    /**
     * 요소의 자식들을 각각 노드로 컴파일합니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> 컴파일된 노드 배열
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileChildNodes(MarkupElement $element, ?string $path): array
    {
        return array_map(
            fn (MarkupElement $child): array => $this->nodes->compile($child, $path),
            $element->children,
        );
    }

    /**
     * 요소의 속성을 `{이름: 값}` 객체로 모읍니다 (`:attr` 타입 리터럴, 무접두 문자열/불리언).
     *
     * `class` → `className` 별칭만 적용한다(컬럼·푸터 셀의 className 필드).
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 속성 객체
     *
     * @throws CompileError 속성명이 비었거나 중복이거나 타입 리터럴이 JSON 이 아닌 경우
     */
    private function attributesToObject(MarkupElement $element, ?string $path): array
    {
        $object = [];

        foreach ($element->attributes as $attribute) {
            $typed = str_starts_with($attribute->name, ':');
            $bare = $typed ? substr($attribute->name, 1) : $attribute->name;

            if ($bare === '') {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, ['found' => $attribute->name]);
            }

            $key = $bare === 'class' ? 'className' : $bare;

            if (array_key_exists($key, $object)) {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                    'tag' => $element->name,
                    'attr' => $key,
                ]);
            }

            $object[$key] = $typed
                ? MarkupValue::decodeTypeLiteral($attribute, $key, $path)
                : ($attribute->isBoolean() ? true : $attribute->value);
        }

        return $object;
    }

    /**
     * props 값을 배치하되 이미 찬 자리를 덮어쓰지 않게 막습니다.
     *
     * @param  array<string, mixed>  $props  props (참조)
     * @param  string  $key  props 키
     * @param  mixed  $value  값
     * @param  MarkupElement  $element  값이 실린 요소 (오류 좌표용)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 같은 자리를 두 표기가 다투는 경우
     */
    private function assignProp(array &$props, string $key, mixed $value, MarkupElement $element, ?string $path): void
    {
        if (array_key_exists($key, $props)) {
            throw new CompileError(
                LayoutMarkupErrorCode::DuplicateAttribute,
                $element->line,
                $element->column,
                ['tag' => $element->name, 'attr' => $key],
                $path,
            );
        }

        $props[$key] = $value;
    }

    /**
     * 부모 문맥 오류를 만듭니다.
     *
     * @param  MarkupElement  $child  임베딩 자식
     * @param  string  $expectedTag  요구되는 부모 마크업 태그
     * @param  string  $foundTag  실제 부모 마크업 태그
     * @param  string|null  $path  소스 파일 경로
     * @return CompileError 컴파일 오류
     */
    private function wrongParent(MarkupElement $child, string $expectedTag, string $foundTag, ?string $path): CompileError
    {
        return $this->error($child, LayoutMarkupErrorCode::EmbeddingTagWrongParent, $path, [
            'tag' => $child->name,
            'parent' => $expectedTag,
            'found' => $foundTag,
        ]);
    }

    /**
     * 임베딩 태그 안에 올 수 없는 자식 오류를 만듭니다.
     *
     * @param  MarkupElement  $child  잘못 놓인 자식
     * @param  string  $parentTag  임베딩 부모 태그
     * @param  string|null  $path  소스 파일 경로
     * @return CompileError 컴파일 오류
     */
    private function unexpectedChild(MarkupElement $child, string $parentTag, ?string $path): CompileError
    {
        return $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
            'tag' => $child->name,
            'parent' => $parentTag,
        ]);
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
            throw $this->missingAttribute($element, $name, $path);
        }

        return $attribute->value;
    }

    /**
     * 필수 속성 누락 오류를 만듭니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string  $name  속성명
     * @param  string|null  $path  소스 파일 경로
     * @return CompileError 컴파일 오류
     */
    private function missingAttribute(MarkupElement $element, string $name, ?string $path): CompileError
    {
        return $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
            'tag' => $element->name,
            'attr' => $name,
        ]);
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
