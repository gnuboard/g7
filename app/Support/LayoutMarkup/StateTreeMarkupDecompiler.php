<?php

namespace App\Support\LayoutMarkup;

/**
 * 상태 초기화 JSON 객체를 `<field>` 트리로 되돌리는 역변환 층 (S9 — StateTreeCompiler 의 역).
 *
 * 계획서가 "최대 리스크 지점" 으로 꼽은 자리지만, 역변환은 오히려 단순하다. 컴파일 시점의 세 작성
 * 형태(`<field>` 리터럴 / `<from-source>` / `merge` 속성)가 **모두 같은 JSON 객체로 접히기** 때문이다:
 *
 *  - `<from-source>SRC</from-source>` → `"SRC"` 는 `<field name=k value="SRC"/>` 와 동일 산출
 *  - `<from-source key=K path=P/>` → `{key:K,path:P}` 는 `<field>` 중첩과 동일 산출
 *  - `merge="deep"` → `_merge` 키는 평범한 `<field name="_merge">` 와 동일 산출
 *
 * 그래서 역변환은 어느 형태였는지 복원할 필요가 없다 — **JSON 객체를 그대로 `<field>` 트리로** 찍으면
 * 모든 마크업 형태와 deep-equal 로 왕복한다. 소스 키 순서대로 필드를 찍어 키 순서도 보존한다. 빈 `{}`↔`[]`
 * 구분은 `json_decode(assoc)` 가 양쪽에서 똑같이 `[]` 로 접으므로 발산하지 않는다.
 */
final class StateTreeMarkupDecompiler
{
    use EmitsMarkup;

    /**
     * 상태 객체의 각 키를 `<field>` 요소로 찍습니다 (소스 키 순서 보존).
     *
     * @param  array<string, mixed>  $object  상태 객체
     * @return array<int, MarkupElement> `<field>` 요소 목록
     */
    public function fields(array $object): array
    {
        $fields = [];

        foreach ($object as $name => $value) {
            $fields[] = $this->valueField((string) $name, $value);
        }

        return $fields;
    }

    /**
     * 상태 값 하나를 `<field>` 요소로 찍습니다.
     *
     * 문자열=무접두 `value`, 비어있지 않은 객체=중첩 `<field>` 트리, 그 밖(숫자·불리언·null·배열·빈값)
     * =`:value` 타입 리터럴.
     *
     * @param  string  $name  필드명
     * @param  mixed  $value  값
     * @return MarkupElement `<field>` 요소
     */
    public function valueField(string $name, mixed $value): MarkupElement
    {
        $nameAttr = $this->attr('name', $name);

        if (is_string($value)) {
            return $this->el('field', [$nameAttr, $this->attr('value', $value)]);
        }

        if ($this->isObject($value)) {
            return $this->el('field', [$nameAttr], $this->fields($value));
        }

        // 표현 가능한 리스트는 `<item>` 트리로 (D53 G18 — 컴파일 짝은 `StateTreeCompiler::compileField`).
        // `structuredChildren` 이 표현 불가로 판정한 형태(스칼라 원소·빈 문자열 원소·빈 컬렉션)만
        // 아래 타입 리터럴로 떨어지고, 그 자리는 원시 JSON 대장에 계량된다(묵인이 아니라 열거 — D42).
        $items = $this->structuredChildren($value);

        if ($items !== null) {
            return $this->el('field', [$nameAttr], $items);
        }

        // 숫자·불리언·null·표현 불가 리스트·빈 배열 — 타입 리터럴 한 표기로 모은다.
        return $this->el('field', [$nameAttr, $this->attr(':value', $this->encode($value))]);
    }
}
