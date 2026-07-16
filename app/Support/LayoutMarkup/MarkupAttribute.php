<?php

namespace App\Support\LayoutMarkup;

/**
 * `.g7.html` 시작 태그에 실린 속성 하나.
 *
 * 토크나이저는 속성의 **의미를 해석하지 않는다**. 속성명 3분할(무접두 props /
 * `data-*` 지시자 / `:attr` 타입 리터럴)과 값의 타입 판정은 레지스트리(components.json)
 * 를 아는 상위 컴파일러의 몫이므로, 여기서는 원문을 손실 없이 실어 나르기만 한다.
 *
 * 그래서 이름은 **대소문자를 보존한다** — `:serverSidePagination` 처럼 camelCase 인
 * props 가 실재하므로(태그명과 달리) 소문자화는 손실이다.
 *
 * `value` 가 null 이면 값이 없는 **불리언 속성**(`<field required>`)이다. HTML5 는
 * 불리언 속성의 참을 "존재" 로 표기하므로, 빈 문자열 값(`required=""`)과 구분해야
 * 한다 — 전자는 null, 후자는 `''`.
 */
final class MarkupAttribute
{
    /**
     * @param  string  $name  속성명 (원문 — 대소문자·대괄호·콜론 보존)
     * @param  string|null  $value  속성값 (문자 참조 디코드 후), 불리언 속성이면 null
     * @param  int  $line  속성명이 시작된 라인 (1-base)
     * @param  int  $column  속성명이 시작된 컬럼 (1-base)
     * @param  string|null  $quote  값을 감싼 따옴표 (`"` 또는 `'`), 불리언 속성이면 null
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $value,
        public readonly int $line,
        public readonly int $column,
        public readonly ?string $quote = null,
    ) {}

    /**
     * 값이 없는 불리언 속성인지 판정합니다.
     *
     * @return bool 값이 없으면(존재만으로 참) true
     */
    public function isBoolean(): bool
    {
        return $this->value === null;
    }
}
