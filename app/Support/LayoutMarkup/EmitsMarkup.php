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
     * `<prop name="key">` 구조 자식을 만듭니다 (객체/배열 props) — D42 G3·G4.
     *
     * 값 형태에 따라 1급 표기를 고른다:
     *  - **평면 스칼라 맵** → `<field>` 자식 (`<prop name="uploadParams"><field name="temp_key" value="…"/></prop>`)
     *  - **평면 객체의 리스트** → `<item>` 자식 (`<prop name="rowActions"><item id="view" label="…"/></prop>`)
     *  - 그 밖(중첩·혼재) → JSON 본문 (표현 수단이 아직 없는 자리. 대장에 등재된다)
     *
     * 저작 파일에서 통짜 JSON 을 없애는 것이 목적이므로(D42), 표현 가능한 형태는 전부 태그로 낸다.
     *
     * @param  string  $key  props 키
     * @param  mixed  $value  값
     * @return MarkupElement `<prop>` 요소
     */
    protected function propElement(string $key, mixed $value): MarkupElement
    {
        $nameAttr = [$this->attr('name', $key)];

        if (is_array($value) && $value !== []) {
            // 병합 지시자 — `{"_append":[…]}` → `<append><item …/></append>` (D42 G5).
            if (! array_is_list($value) && $this->isMergeDirectiveMap($value)) {
                $directives = [];

                foreach ($value as $directive => $inner) {
                    $directives[] = $this->propDirectiveElement(substr((string) $directive, 1), $inner);
                }

                return $this->el('prop', $nameAttr, $directives);
            }

            if (! array_is_list($value) && $this->isFlatScalarMapValue($value)) {
                return $this->el('prop', $nameAttr, $this->fieldChildren($value));
            }

            if (array_is_list($value) && $this->isFlatObjectList($value)) {
                return $this->el('prop', $nameAttr, array_map(
                    fn (array $item): MarkupElement => $this->el('item', $this->mapAttributes($item), []),
                    $value,
                ));
            }
        }

        return $this->el('prop', $nameAttr, [], $this->encode($value));
    }

    /**
     * 맵이 **병합 지시자로만** 이루어져 있는지 판정합니다 (D42 G5 — `NodeCompiler::MERGE_DIRECTIVE_TAGS` 와 짝).
     *
     * 지시자 값이 `<item>`/`<field>` 로 표현 가능해야 되돌릴 수 있으므로 그 조건까지 확인한다.
     *
     * @param  array<string, mixed>  $map  맵
     * @return bool 병합 지시자 맵이면 true
     */
    protected function isMergeDirectiveMap(array $map): bool
    {
        foreach ($map as $key => $value) {
            if ($key !== '_append') {
                return false;
            }

            $representable = is_array($value) && $value !== []
                && ((array_is_list($value) && $this->isFlatObjectList($value))
                    || (! array_is_list($value) && $this->isFlatScalarMapValue($value)));

            if (! $representable) {
                return false;
            }
        }

        return $map !== [];
    }

    /**
     * 병합 지시자 하나를 요소로 되돌립니다 (`<append>` 안에 `<item>` 또는 `<field>`).
     *
     * @param  string  $tag  지시자 태그명 (`append`)
     * @param  mixed  $value  지시자 값
     * @return MarkupElement 지시자 요소
     */
    protected function propDirectiveElement(string $tag, mixed $value): MarkupElement
    {
        if (is_array($value) && array_is_list($value)) {
            return $this->el($tag, [], array_map(
                fn (array $item): MarkupElement => $this->el('item', $this->mapAttributes($item), []),
                $value,
            ));
        }

        return $this->el($tag, [], $this->fieldChildren(is_array($value) ? $value : []));
    }

    /**
     * 연관 배열의 모든 값이 스칼라인지 판정합니다 (속성/`<field>` 표기 가능 여부).
     *
     * @param  array<string, mixed>  $map  맵
     * @return bool 평면이면 true
     */
    protected function isFlatScalarMapValue(array $map): bool
    {
        foreach ($map as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 리스트의 모든 원소가 **평면 객체**인지 판정합니다 (`<item>` 표기 가능 여부).
     *
     * @param  array<int, mixed>  $list  리스트
     * @return bool 전부 평면 객체면 true
     */
    protected function isFlatObjectList(array $list): bool
    {
        foreach ($list as $item) {
            if (! is_array($item) || $item === [] || array_is_list($item) || ! $this->isFlatScalarMapValue($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 평면 맵을 속성 목록으로 되돌립니다 (문자열은 `attr`, 그 밖은 `:attr` 타입 리터럴).
     *
     * @param  array<string, mixed>  $map  평면 맵
     * @return array<int, MarkupAttribute> 속성 목록
     */
    protected function mapAttributes(array $map): array
    {
        $attributes = [];

        foreach ($map as $key => $value) {
            $attributes[] = $this->valueAttr((string) $key, $value);
        }

        return $attributes;
    }

    /**
     * 평면 맵을 `<field name value>` 자식으로 되돌립니다 (D42 G3).
     *
     * @param  array<string, mixed>  $map  평면 맵
     * @return array<int, MarkupElement> `<field>` 요소 목록
     */
    protected function fieldChildren(array $map): array
    {
        $children = [];

        foreach ($map as $key => $value) {
            $children[] = $this->el('field', [
                $this->attr('name', (string) $key),
                $this->valueAttr('value', $value),
            ], []);
        }

        return $children;
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
