<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 상태 초기값을 `<field>` 중첩 태그 트리로 저작하고 JSON 객체로 컴파일하는 층.
 *
 * 초기값을 `<isolated-state>{ "scrollIdx": 0 }</isolated-state>` 처럼 JSON 덩어리로 본문에
 * 넣는 표기는 쓰지 않는다(D5). "속성 JSON 추방" 을 절반만 지키는 타협이라, 저작 포맷 안에
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
            return $this->compile($field, $path);
        }

        if ($value === null) {
            // 값도 자식도 없으면 `{}` 인지 `null` 인지 `""` 인지 저작자만 안다. 하나를 골라
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
        $raw = (string) $attribute->value;
        $value = json_decode($raw, true);

        if ($value === null && strtolower(trim($raw)) !== 'null') {
            throw new CompileError(LayoutMarkupErrorCode::InvalidTypeLiteral, $attribute->line, $attribute->column, [
                'attr' => $attribute->name,
                'found' => $raw,
                'suggestion' => 'value',
            ], $path);
        }

        return $value;
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
