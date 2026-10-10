<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 구조를 가진 값(객체·리스트)을 `<field>`/`<item>` 태그 트리로 작성하는 공통 층 (D42 G2·G3·G4·G6).
 *
 * "구조를 가진 값은 어디에 있든 `<field>` 트리로 적는다" 는 D42 공통 원리를 한 곳에 구현한다.
 * 같은 규칙이 세 자리에 필요하기 때문이다 — 데이터소스 자식(`<params>`/`<fallback>`), `<prop>` 값,
 * 액션 구조값 파라미터(`<field name>`). 세 곳에 복제하면 한쪽만 늘려 마크업 형식이 자리마다 달라진다
 * (`EmitsMarkup` 이 방출 규칙을 한 곳에 모은 것과 같은 이유).
 *
 * 표기는 셋이다.
 *
 * ```html
 * <field name="label"><field name="ko" value="한글"/></field>   <!-- 중첩 객체 -->
 * <field name="tabs"><item id="a" label="A"/></field>            <!-- 평면 객체의 리스트 -->
 * <field name="slug"><item>$t:…required</item></field>           <!-- 문자열의 리스트 -->
 * ```
 *
 * **`<item>` 의 세 형태는 서로 배타적이라 모호하지 않다** — 자식이 있으면 중첩 구조, 속성이 있으면
 * 평면 객체, 본문 텍스트만 있으면 문자열이다. 문자열 아닌 스칼라 원소(`[1, true]`)에는 표기를 주지
 * 않는다: `<item :value="1"/>` 은 객체 `{"value": 1}` 과 구별되지 않아 왕복이 깨진다. 그런 리스트는
 * 종전대로 JSON 본문·타입 리터럴로 남고 원시 JSON 대장에 계량된다(묵인이 아니라 열거 — D42).
 *
 * 리프 값은 `:attr` 규칙을 그대로 쓴다 — `:value` 는 JSON 타입 리터럴(`0`·`[]`·`null`), `value` 는
 * 문자열이다.
 */
final class StructuredValueCompiler
{
    /**
     * @param  LayoutMarkupErrorCode  $unexpectedChild  기대하지 않은 자식 태그에 쓸 에러 코드
     *                                                  (액션 문맥과 구조 태그 문맥이 서로 다른 코드를 쓴다)
     */
    public function __construct(private readonly LayoutMarkupErrorCode $unexpectedChild) {}

    /**
     * 컨테이너의 자식들을 구조값으로 컴파일합니다 — `<item>` 이면 리스트, `<field>` 면 맵.
     *
     * @param  MarkupElement  $element  `<field>`/`<prop>`/`<params>` 등 컨테이너 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<array-key, mixed> 구조값 (자식이 없으면 빈 배열)
     *
     * @throws CompileError 자식 표기가 규칙을 벗어난 경우
     */
    public function compileChildren(MarkupElement $element, ?string $path): array
    {
        if ($element->children === []) {
            return [];
        }

        return $element->children[0]->name === 'item'
            ? $this->compileList($element, $path)
            : $this->compileMap($element, $path);
    }

    /**
     * `<field name>` 자식들을 맵으로 컴파일합니다 (값이 다시 구조면 재귀).
     *
     * @param  MarkupElement  $element  컨테이너 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 맵
     *
     * @throws CompileError `<field>` 가 아닌 자식이 있거나 name 이 없거나 중복인 경우
     */
    private function compileMap(MarkupElement $element, ?string $path): array
    {
        $map = [];

        foreach ($element->children as $child) {
            $this->assertTag($child, 'field', $element, $path);

            $name = $this->requiredName($child, $path);

            if (array_key_exists($name, $map)) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                    'tag' => $child->name,
                    'attr' => $name,
                ]);
            }

            $map[$name] = $child->children !== []
                ? $this->compileChildren($child, $path)
                : $this->leafValue($child, $path);
        }

        return $map;
    }

    /**
     * `<item>` 자식들을 리스트로 컴파일합니다.
     *
     * @param  MarkupElement  $element  컨테이너 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, mixed> 리스트
     *
     * @throws CompileError `<item>` 이 아닌 자식이 있는 경우
     */
    private function compileList(MarkupElement $element, ?string $path): array
    {
        $list = [];

        foreach ($element->children as $child) {
            $this->assertTag($child, 'item', $element, $path);
            $list[] = $this->itemValue($child, $path);
        }

        return $list;
    }

    /**
     * `<item>` 하나의 값을 결정합니다 — 자식=중첩 구조 / 속성=평면 객체 / 본문=문자열.
     *
     * @param  MarkupElement  $element  `<item>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 원소 값
     *
     * @throws CompileError 속성 표기가 규칙을 벗어난 경우
     */
    private function itemValue(MarkupElement $element, ?string $path): mixed
    {
        if ($element->children !== []) {
            return $this->compileChildren($element, $path);
        }

        if ($element->attributes !== []) {
            return $this->attributeMap($element, $path);
        }

        return (string) ($element->text ?? '');
    }

    /**
     * 요소의 속성을 평면 키-값 맵으로 읽습니다 (`:attr` 은 타입 리터럴, 불리언 속성은 true).
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 평면 맵
     *
     * @throws CompileError 중복 키가 있는 경우
     */
    public function attributeMap(MarkupElement $element, ?string $path): array
    {
        $map = [];

        foreach ($element->attributes as $attribute) {
            $key = $attribute->name;
            $value = str_starts_with($key, ':')
                ? MarkupValue::decodeTypeLiteral($attribute, substr($key, 1), $path)
                : ($attribute->isBoolean() ? true : $attribute->value);
            $key = ltrim($key, ':');

            if (array_key_exists($key, $map)) {
                throw new CompileError(LayoutMarkupErrorCode::DuplicateAttribute, $attribute->line, $attribute->column, [
                    'tag' => $element->name,
                    'attr' => $key,
                ], $path);
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * `<field>` 리프의 값을 읽습니다 — `:value`(타입 리터럴) 우선, 없으면 `value`(문자열).
     *
     * @param  MarkupElement  $field  `<field>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 리프 값
     */
    private function leafValue(MarkupElement $field, ?string $path): mixed
    {
        $typed = $field->attribute(':value');

        if ($typed !== null) {
            return MarkupValue::decodeTypeLiteral($typed, 'value', $path);
        }

        return $field->attribute('value')?->value ?? null;
    }

    /**
     * `name` 속성을 필수로 읽습니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return string name 값
     *
     * @throws CompileError name 이 없거나 빈 경우
     */
    private function requiredName(MarkupElement $element, ?string $path): string
    {
        $attribute = $element->attribute('name');

        if ($attribute === null || $attribute->value === null || $attribute->value === '') {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => $element->name,
                'attr' => 'name',
            ]);
        }

        return $attribute->value;
    }

    /**
     * 자식 태그가 기대한 것인지 확인합니다.
     *
     * @param  MarkupElement  $child  자식 요소
     * @param  string  $expected  기대 태그명
     * @param  MarkupElement  $parent  부모 요소
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 태그가 다른 경우
     */
    private function assertTag(MarkupElement $child, string $expected, MarkupElement $parent, ?string $path): void
    {
        if ($child->name !== $expected) {
            throw $this->error($child, $this->unexpectedChild, $path, [
                'tag' => $child->name,
                'parent' => $parent->name,
            ]);
        }
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
