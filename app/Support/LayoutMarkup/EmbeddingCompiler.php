<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 노드 서브트리를 props/노드 키로 끌어올리는(hoist) 임베딩 층 (매핑 사전 D — S5).
 *
 * JSON 레이아웃에는 HTML 자식으로 곧장 표기할 수 없는 자리가 있다. `DataGrid.columns[].cellChildren`
 * 처럼 **props 안의 배열 원소가 다시 노드 트리를 품거나**, `responsive.{bp}` 처럼 **노드 키가
 * 브레이크포인트별 부분 노드를 품는** 지점이다. 저작자는 이를 이름 있는 자식 태그로 쓰고
 * (`<column><cell>…</cell></column>`), 이 층이 그 서브트리를 컴파일해 제자리로 끌어올린다.
 *
 * 이 층이 지키는 규칙은 셋이다.
 *
 * **① 부모 문맥이 태그를 정한다.** `<column>` 은 DataGrid 안에서만, `<title-prefix>` 는 Modal
 * 안에서만 뜻이 있다. 엉뚱한 부모 아래 오면 자리 오류로 안내한다 — 조용히 props 로 흘리면
 * 화면에 아무 영향도 주지 않은 채 사라진다. `<card>`·`<option>` 처럼 등록 컴포넌트와 이름이
 * 겹치는 태그는 부모가 그리드·Select 일 때만 임베딩이고 그 밖에서는 그냥 컴포넌트다(위치 분기).
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
     * DataGrid 전용 자식 태그 → props 배열 키.
     *
     * `<column>` 만 자기 스칼라 필드(field/header/…)를 갖고, 나머지는 서브트리만 담는다.
     *
     * @var array<string, string>
     */
    private const DATAGRID_TAGS = [
        'column' => 'columns',
        'expand' => 'expandChildren',
        'sub-row' => 'subRowChildren',
        'footer-cell' => 'footerCells',
        'footer-card' => 'footerCardChildren',
    ];

    /** Modal 전용 자식 태그 → props 배열 키 */
    private const MODAL_TAGS = ['title-prefix' => 'titlePrefix', 'title-suffix' => 'titleSuffix'];

    /** 부모 문맥 없이 항상 임베딩인 자식 태그 (등록 컴포넌트와 이름이 겹치지 않는다) */
    private const CONTEXT_FREE_TAGS = [
        'column', 'cell', 'expand', 'sub-row', 'footer-cell', 'footer-card',
        'title-prefix', 'title-suffix', 'responsive', 'template', 'item-template',
        'switch-value', 'default', 'callback',
    ];

    /** 부모가 그리드일 때만 임베딩인 태그 (그 밖에서는 Card 컴포넌트) */
    private const GRID_PARENTS = ['DataGrid', 'CardGrid'];

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
        if (in_array($tag, self::CONTEXT_FREE_TAGS, true)) {
            return true;
        }

        return $tag === 'card' && in_array($parent->name, self::GRID_PARENTS, true);
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
     *
     * @throws CompileError 부모 문맥이 맞지 않거나 표기 규칙을 벗어난 경우
     */
    public function applyChild(
        MarkupElement $child,
        ComponentDefinition $parent,
        array &$node,
        array &$props,
        array &$hoist,
        ?string $path,
    ): void {
        switch ($child->name) {
            case 'column':
            case 'expand':
            case 'sub-row':
            case 'footer-cell':
            case 'footer-card':
                $this->requireParent($child, $parent, ['DataGrid'], 'data-grid', $path);
                $this->applyDataGridChild($child, $hoist, $path);

                return;

            case 'card':
                // handlesChild 가 그리드 부모일 때만 여기로 보낸다.
                $hoist['cardColumns'][] = $this->compileCard($child, $path);

                return;

            case 'title-prefix':
            case 'title-suffix':
                $this->requireParent($child, $parent, ['Modal'], 'modal', $path);
                $hoist[self::MODAL_TAGS[$child->name]] = $this->compileChildNodes($child, $path);

                return;

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
     * DataGrid 서브트리 자식(`<column>`/`<expand>`/…)을 hoist 버퍼에 누적합니다.
     *
     * @param  MarkupElement  $child  자식 요소
     * @param  array<string, mixed>  $hoist  hoist 버퍼 (참조)
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyDataGridChild(MarkupElement $child, array &$hoist, ?string $path): void
    {
        $key = self::DATAGRID_TAGS[$child->name];

        // `<column>`·`<footer-cell>` 은 스칼라 필드를 함께 갖는 객체 원소이고, `<expand>`·
        // `<sub-row>`·`<footer-card>` 는 노드 배열 자체다. 앞 둘은 원소를 배열에 밀어 넣고
        // 뒤 셋은 컴파일한 노드 배열을 통째로 그 키에 놓는다.
        if (in_array($child->name, ['expand', 'sub-row', 'footer-card'], true)) {
            // 단일 배열 키 — 같은 태그를 두 번 쓰면 앞엣것이 조용히 사라진다.
            if (isset($hoist[$key])) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => $key]);
            }

            $hoist[$key] = $this->compileChildNodes($child, $path);

            return;
        }

        $hoist[$key][] = $child->name === 'column'
            ? $this->compileColumn($child, $path)
            : $this->compileFooterCell($child, $path);
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
     * `<card>` 를 cardColumns 배열 원소(`{id, cellChildren}`)로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<card>` 요소
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
     * 임베딩 태그가 요구하는 부모 컴포넌트인지 확인합니다.
     *
     * @param  MarkupElement  $child  임베딩 자식
     * @param  ComponentDefinition  $parent  실제 부모 정의
     * @param  array<int, string>  $expected  허용 부모 컴포넌트명
     * @param  string  $expectedTag  안내에 실을 부모 저작 태그명
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 부모가 허용 목록 밖인 경우
     */
    private function requireParent(MarkupElement $child, ComponentDefinition $parent, array $expected, string $expectedTag, ?string $path): void
    {
        if (! in_array($parent->name, $expected, true)) {
            throw $this->wrongParent($child, $expectedTag, $parent->tag, $path);
        }
    }

    /**
     * 부모 문맥 오류를 만듭니다.
     *
     * @param  MarkupElement  $child  임베딩 자식
     * @param  string  $expectedTag  요구되는 부모 저작 태그
     * @param  string  $foundTag  실제 부모 저작 태그
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
