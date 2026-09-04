<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 상태 초기값을 `<field>` 중첩 태그 트리로 작성하고 JSON 객체로 컴파일하는 층.
 *
 * 초기값을 `<isolated-state>{ "scrollIdx": 0 }</isolated-state>` 처럼 JSON 덩어리로 본문에
 * 넣는 표기는 쓰지 않는다(D5). "속성 JSON 추방" 을 절반만 지키는 타협이라, 마크업 형식 안에
 * 두 번째 언어가 남는다. 대신 `ul/li` 처럼 객체 구조를 자식 태그 계층으로 표현한다:
 *
 * ```html
 * <isolated-state scope-id="product_list">
 *   <field name="scrollIdx" :value="0"/>
 *   <field name="filter">
 *     <field name="keyword" value=""/>
 *   </field>
 * </isolated-state>
 * ```
 *
 * 리프의 타입은 `:attr` 규칙을 그대로 쓴다 — `:value` 는 JSON 타입(`0`·`[]`·`null`),
 * `value` 는 문자열. `{ "x": [] }` 의 자명한 타입이 태그화로 소실되지 않게 하려는 것이다.
 *
 * 지금은 `<field>` 만 다룬다. 데이터소스 값을 끌어오는 `<from-source key path>`(initLocal/
 * initGlobal 의 `{key, path}` 배열 원소)는 문서 섹션을 세우는 S4 가 같은 트리에 얹는다 —
 * 격리 상태(isolatedState)에는 그 표면이 없기 때문이다.
 */
final class StateTreeCompiler
{
    /**
     * 구조값 컴파일 SSoT (`<item>` 리스트 위임 — D53 G18).
     */
    private ?StructuredValueCompiler $structuredValues = null;

    /**
     * 구조값 컴파일러를 지연 생성합니다 (`<field>` 안의 `<item>` 리스트 — D53 G18).
     *
     * @return StructuredValueCompiler 구조값 컴파일러
     */
    private function structuredValues(): StructuredValueCompiler
    {
        return $this->structuredValues ??= new StructuredValueCompiler(LayoutMarkupErrorCode::UnexpectedStructuralChild);
    }

    /**
     * `<field>` 자식들을 상태 객체로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<field>` 를 자식으로 갖는 요소
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return array<string, mixed> 상태 객체
     *
     * @throws CompileError `<field>` 표기가 규칙을 벗어난 경우
     */
    public function compile(MarkupElement $element, ?string $path = null): array
    {
        $state = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'field') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => $element->name,
                ]);
            }

            $name = $child->attribute('name');

            if ($name === null || $name->value === null || $name->value === '') {
                throw $this->error($child, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                    'tag' => 'field',
                    'attr' => 'name',
                ]);
            }

            $state[$name->value] = $this->compileField($child, $path);
        }

        return $state;
    }

    /**
     * `<init-local>`/`<init-global>`/`<init-isolated>` 블록 하나를 상태 키 기여로 컴파일합니다.
     *
     * `<isolated-state>`(노드 층)와 달리 문서·데이터소스 초기화는 세 가지가 더 있다 — 데이터소스에서
     * 값을 끌어오는 `<from-source>`, 한 상태 키로 묶는 `key` 속성, 병합 전략 `merge`. 그래서 이
     * 메서드는 **여러 키를 낼 수도**(무-key 블록의 `<field>` 들이 각각 상태 키가 됨) **한 키를 낼 수도**
     * (`key` 지정 시 블록 전체가 그 키의 값) 있어, `{name: value}` 객체 하나를 돌려주는 `compile()`과
     * 반환 계약이 다르다.
     *
     * @param  MarkupElement  $block  `<init-*>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 초기화 대상 객체에 병합할 `{상태키: 값}` 기여
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    public function compileInitBlock(MarkupElement $block, ?string $path = null): array
    {
        $key = $block->attribute('key');
        $merge = $block->attribute('merge');
        $sources = $block->childrenNamed('from-source');

        // key 없는 블록 = 리프 `<field>` 들이 각각 상태 키가 되는 평면 매핑(레이아웃 루트의 표준형).
        // `<from-source>`(값 전체를 데이터에서 끌어옴)는 담을 상태 키가 없어 key 를 요구한다.
        if ($key === null || $key->value === null || $key->value === '') {
            if ($sources !== []) {
                throw $this->error($sources[0], LayoutMarkupErrorCode::StateSourceNeedsKey, $path, ['tag' => $block->name]);
            }

            if ($merge !== null) {
                throw $this->error($block, LayoutMarkupErrorCode::StateMergeNeedsKey, $path, ['tag' => $block->name]);
            }

            return $this->compile($block, $path);
        }

        return [$key->value => $this->compileKeyedValue($block, $sources, $merge?->value, $path)];
    }

    /**
     * `key` 로 묶인 초기화 블록의 값을 결정합니다.
     *
     * 값은 셋 중 하나다 — `<field>` 들이 이루는 매핑 객체, `<from-source>` 하나가 끌어오는 소스 값
     * (문자열 소스 또는 `{key, path}`), `<from-source>` 여럿이 이루는 혼합 배열. `merge` 는 매핑
     * 객체에만 `_merge` 로 얹는다(엔진이 최상위 키 레벨에만 병합 전략을 두므로).
     *
     * @param  MarkupElement  $block  `<init-*>` 요소
     * @param  array<int, MarkupElement>  $sources  `<from-source>` 자식들
     * @param  string|null  $merge  병합 전략 (`deep`/`shallow`/`replace`), 없으면 null
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 상태 키의 값
     *
     * @throws CompileError `<field>` 와 `<from-source>` 를 섞었거나 표기가 규칙을 벗어난 경우
     */
    private function compileKeyedValue(MarkupElement $block, array $sources, ?string $merge, ?string $path): mixed
    {
        $fields = $block->childrenNamed('field');

        if ($fields !== [] && $sources !== []) {
            throw $this->error($sources[0], LayoutMarkupErrorCode::StateFieldSourceMixed, $path, ['tag' => $block->name]);
        }

        if ($sources !== []) {
            if ($merge !== null) {
                throw $this->error($block, LayoutMarkupErrorCode::StateMergeNeedsMapping, $path, ['tag' => $block->name]);
            }

            $values = array_map(fn (MarkupElement $s): mixed => $this->compileSource($s, $path), $sources);

            return count($values) === 1 ? $values[0] : $values;
        }

        // `<field>` 매핑 — compile() 이 `{name: value}` 를 만든다. merge 는 최상위 `_merge` 로.
        $value = $this->compile($block, $path);

        if ($merge !== null) {
            $value['_merge'] = $merge;
        }

        return $value;
    }

    /**
     * `<from-source>` 하나를 소스 값으로 컴파일합니다.
     *
     * `<from-source>src</from-source>`(본문만) 는 전체 데이터를 저장하는 문자열 소스, `key`/`path`
     * 속성이 있으면 `{key, path}` 객체다(path 는 배열 인덱스 표기 `[0].name` 을 그대로 보존).
     *
     * @param  MarkupElement  $source  `<from-source>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return string|array<string, string> 소스 값
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileSource(MarkupElement $source, ?string $path): string|array
    {
        if ($source->children !== []) {
            throw $this->error($source->children[0], LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                'tag' => $source->children[0]->name,
                'parent' => 'from-source',
            ]);
        }

        $keyAttr = $source->attribute('key');
        $pathAttr = $source->attribute('path');

        // 본문 문자열 형태 — key/path 속성과 함께 쓸 수 없다(값이 문자열인지 {key,path} 인지 갈린다).
        if ($keyAttr === null && $pathAttr === null) {
            if ($source->text === null || trim($source->text) === '') {
                throw $this->error($source, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                    'tag' => 'from-source',
                    'attr' => 'key',
                ]);
            }

            return trim($source->text);
        }

        if ($source->text !== null && trim($source->text) !== '') {
            throw $this->error($source, LayoutMarkupErrorCode::StateSourceValueAndKey, $path, ['found' => trim($source->text)]);
        }

        if ($keyAttr === null || $keyAttr->value === null || $keyAttr->value === '') {
            throw $this->error($source, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => 'from-source',
                'attr' => 'key',
            ]);
        }

        $value = ['key' => $keyAttr->value];

        if ($pathAttr !== null && $pathAttr->value !== null) {
            $value['path'] = $pathAttr->value;
        }

        return $value;
    }

    /**
     * `<field>` 하나의 값을 결정합니다.
     *
     * @param  MarkupElement  $field  `<field>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 필드 값 (리프 값 또는 중첩 객체)
     *
     * @throws CompileError 값과 자식이 공존하거나 둘 다 없는 경우
     */
    private function compileField(MarkupElement $field, ?string $path): mixed
    {
        $typed = $field->attribute(':value');
        $plain = $field->attribute('value');
        $hasChildren = $field->children !== [];

        if ($typed !== null && $plain !== null) {
            throw $this->error($field, LayoutMarkupErrorCode::DuplicateNodeKey, $path, ['key' => 'value']);
        }

        $value = $typed ?? $plain;

        if ($value !== null && $hasChildren) {
            throw $this->error($field, LayoutMarkupErrorCode::FieldValueAndChildren, $path, [
                'name' => (string) $field->attribute('name')?->value,
            ]);
        }

        if ($hasChildren) {
            // `<item>` 자식은 리스트다 — 구조값 SSoT(`StructuredValueCompiler`)에 그대로 위임한다
            // (D53 G18). 같은 `<field>` 태그가 자리마다 다른 능력을 갖지 않게 하려는 것이고,
            // `<field>` 자식(매핑)은 리프 검증이 더 엄격한 이쪽 규칙을 그대로 유지한다.
            return $field->children[0]->name === 'item'
                ? $this->structuredValues()->compileChildren($field, $path)
                : $this->compile($field, $path);
        }

        if ($value === null) {
            // 값도 자식도 없으면 `{}` 인지 `null` 인지 `""` 인지 작성자만 안다. 하나를 골라
            // 짐작하면 상태 초기값이 조용히 달라지므로 명시를 요구한다.
            throw $this->error($field, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => 'field',
                'attr' => ':value',
            ]);
        }

        return $typed !== null ? $this->decodeTypeLiteral($typed, $path) : $plain->value;
    }

    /**
     * `:value` 를 JSON 리터럴로 디코드합니다.
     *
     * @param  MarkupAttribute  $attribute  `:value` 속성
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 디코드된 값
     *
     * @throws CompileError 값이 JSON 이 아닌 경우
     */
    private function decodeTypeLiteral(MarkupAttribute $attribute, ?string $path): mixed
    {
        // 공용 디코더에 위임한다 — 종전에는 이 파일이 같은 해석을 복사해 갖고 있었고, 그래서
        // 빈 객체 표식({@see EmptyMap}) 처리가 이쪽에만 없었다. `<field :value="{}"/>` 가
        // 조용히 `[]` 로 구워져 `initLocal` 의 map 이 순서 목록이 됐다(50차 규율의 사각).
        return MarkupValue::decodeTypeLiteral($attribute, 'value', $path);
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
}
