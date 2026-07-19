<?php

namespace App\Support\LayoutMarkup;

/**
 * 역변환 각 층이 공유하는 요소·속성·값 생성 헬퍼 (S9).
 *
 * 컴파일러가 값을 **읽는** 규칙(무접두=문자열 / `:attr`=타입 리터럴 / 불리언=존재)을 여기서 거꾸로
 * **쓴다**. 규칙을 각 역변환 층에 복제하면 한쪽만 고쳐 서로 어긋나므로(컴파일러 `MarkupValue` 가
 * 한 곳에 모은 것과 같은 이유) 여기 모은다.
 */
trait EmitsMarkup
{
    /**
     * 요소를 만듭니다 (좌표는 역변환이라 의미 없어 0,0 — 직렬화기가 무시).
     *
     * @param  string  $name  태그명
     * @param  array<int, MarkupAttribute>  $attributes  속성
     * @param  array<int, MarkupElement>  $children  자식
     * @param  string|null  $text  텍스트 (null=자기닫힘/자식만, ''=빈 요소)
     * @param  string|null  $comment  직전 주석
     * @return MarkupElement 요소
     */
    protected function el(string $name, array $attributes = [], array $children = [], ?string $text = null, ?string $comment = null): MarkupElement
    {
        return new MarkupElement(
            $name,
            $attributes,
            $children,
            $text,
            $comment,
            $text === null && $children === [],
            0,
            0,
        );
    }

    /**
     * 속성을 만듭니다 (`$value === null` 이면 불리언 속성 = 이름만).
     *
     * @param  string  $name  속성명
     * @param  string|null  $value  값 (불리언이면 null)
     * @return MarkupAttribute 속성
     */
    protected function attr(string $name, ?string $value = null): MarkupAttribute
    {
        return new MarkupAttribute($name, $value, 0, 0, null);
    }

    /**
     * props/속성 값 하나를 표기로 씁니다 — 문자열은 무접두, 그 밖(숫자·불리언·null)은 `:attr` 타입 리터럴.
     *
     * @param  string  $key  키
     * @param  mixed  $value  값
     * @return MarkupAttribute 속성
     */
    protected function valueAttr(string $key, mixed $value): MarkupAttribute
    {
        if (is_string($value)) {
            return $this->attr($key, $value);
        }

        return $this->attr(':'.$key, $this->encode($value));
    }

    /**
     * 값을 강제로 `:attr` 타입 리터럴로 씁니다 (문자열 포함).
     *
     * @param  string  $key  키
     * @param  mixed  $value  값
     * @return MarkupAttribute 속성
     */
    protected function typedAttr(string $key, mixed $value): MarkupAttribute
    {
        return $this->attr(':'.$key, $this->encode($value));
    }

    /**
     * 조건식 값을 `data-if` 계열 지시자로 씁니다 — 문자열은 무접두, 불리언은 `:` 타입.
     *
     * 컴파일된 조건 문자열은 항상 단일 `{{...}}` 바인딩이라 그대로 찍으면 `normalizeCondition` 이
     * 동일 문자열로 되돌린다. 불리언은 `:if="false"` 처럼 타입 리터럴이라야 왕복한다.
     *
     * @param  string  $directive  지시자명 (`data-if` 등)
     * @param  mixed  $value  조건 값
     * @return MarkupAttribute 속성
     */
    protected function conditionAttr(string $directive, mixed $value): MarkupAttribute
    {
        if (is_bool($value)) {
            return $this->attr(':'.$directive, $this->encode($value));
        }

        return $this->attr($directive, (string) $value);
    }

    /**
     * `<prop name="key">JSON</prop>` 구조 자식을 만듭니다 (객체/배열 props).
     *
     * @param  string  $key  props 키
     * @param  mixed  $value  값
     * @return MarkupElement `<prop>` 요소
     */
    protected function propElement(string $key, mixed $value): MarkupElement
    {
        return $this->el('prop', [$this->attr('name', $key)], [], $this->encode($value));
    }

    /**
     * `<json key="X">JSON</json>` 표기 탈출구 요소를 만듭니다 (미표현 키 보존 — S9).
     *
     * @param  string  $key  키
     * @param  mixed  $value  값
     * @return MarkupElement `<json>` 요소
     */
    protected function jsonElement(string $key, mixed $value): MarkupElement
    {
        return $this->el('json', [$this->attr('key', $key)], [], $this->encode($value));
    }

    /**
     * `<json>JSON</json>` 표기 탈출구 요소를 만듭니다 (노드 전체 보존 — void 자식 등, S9).
     *
     * @param  mixed  $value  노드 JSON
     * @return MarkupElement `<json>` 요소
     */
    protected function jsonNode(mixed $value): MarkupElement
    {
        return $this->el('json', [], [], $this->encode($value));
    }

    /**
     * `permissions`(평면 배열 또는 조건형)를 `<permissions>` 요소로 되돌립니다 (문서·노드 공용, D36).
     *
     * @param  array<int|string, mixed>  $permissions  권한 배열 또는 `{or|and}`
     * @return MarkupElement `<permissions>` 요소
     */
    protected function permissionsElement(array $permissions): MarkupElement
    {
        if ($permissions !== [] && ! array_is_list($permissions)) {
            return $this->el('permissions', [], [$this->permissionConditionElement($permissions)]);
        }

        $children = array_map(
            fn (mixed $permission): MarkupElement => $this->el('permission', [], [], (string) $permission),
            $permissions,
        );

        return $this->el('permissions', [], $children);
    }

    /**
     * 조건형 권한(`{or|and}`)을 `<any-of>`/`<all-of>` (`<permission>` 피연산자)로 되돌립니다.
     *
     * @param  array<string, mixed>  $condition  `{or|and: [...]}`
     * @return MarkupElement 조건 요소
     */
    protected function permissionConditionElement(array $condition): MarkupElement
    {
        $operator = array_key_exists('and', $condition) ? 'and' : 'or';
        $tag = $operator === 'and' ? 'all-of' : 'any-of';
        $operands = [];

        foreach ($condition[$operator] ?? [] as $operand) {
            $operands[] = is_array($operand)
                ? $this->permissionConditionElement($operand)
                : $this->el('permission', [], [], (string) $operand);
        }

        return $this->el($tag, [], $operands);
    }

    /**
     * 값을 결정적 JSON 문자열로 인코드합니다 (유니코드·슬래시 비이스케이프 — 저작 가독성).
     *
     * @param  mixed  $value  값
     * @return string JSON 문자열
     */
    protected function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * JSON 값이 빈 것을 뺀 비어있지 않은 객체(연관 배열)인지 판정합니다.
     *
     * @param  mixed  $value  값
     * @return bool 비어있지 않은 연관 배열이면 true
     */
    protected function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
