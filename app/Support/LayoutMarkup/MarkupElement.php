<?php

namespace App\Support\LayoutMarkup;

/**
 * 트리 빌더가 만든 요소 하나 — 토큰 스트림(S1)과 노드 컴파일러(S2) 사이의 중간 표현.
 *
 * 아직 **G7 의미가 없다**: 태그명은 컴포넌트로 해석되기 전이고, 속성은 3분할(무접두 props /
 * `data-*` 지시자 / `:attr` 타입 리터럴)되기 전이다. 이 층의 유일한 관심사는 "누가 누구의
 * 자식인가" 와 소스 순서·좌표를 잃지 않는 것이다.
 *
 * 그래서 `text` 와 `children` 이 **함께 실릴 수 있다**. JSON 노드에서는 둘이 배타적이지만
 * (실측 공존 0건), 자식 중에 구조 태그(`<class-map>` — 노드 키 운반체)가 섞여 있으면 텍스트와
 * 공존하는 것이 정상 마크업이다. 진짜 혼재(E_MIXED_TEXT)인지는 구조 태그를 걷어낸 뒤에야
 * 알 수 있으므로 노드 컴파일러가 판정한다.
 */
final class MarkupElement
{
    /**
     * @param  string  $name  태그명 (소문자 kebab)
     * @param  array<int, MarkupAttribute>  $attributes  속성 목록 (소스 순서 보존)
     * @param  array<int, MarkupElement>  $children  자식 요소 (구조 태그 포함 — 분리는 노드 컴파일러 몫)
     * @param  string|null  $text  인덴트를 걷어낸 텍스트 내용. self-closing(`<span/>`)은 null,
     *                             빈 내용(`<span></span>`)은 `''`, 자식 요소만 있고 인덴트뿐이면 null.
     * @param  string|null  $comment  이 요소 **직전**에 놓인 주석 (노드 `comment` 키로 방출)
     * @param  bool  $selfClosing  자기닫힘 표기 여부 (`text` 부재와 빈 문자열을 가르는 근거)
     * @param  int  $line  시작 태그 라인 (1-base)
     * @param  int  $column  시작 태그 컬럼 (1-base)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes,
        public readonly array $children,
        public readonly ?string $text,
        public readonly ?string $comment,
        public readonly bool $selfClosing,
        public readonly int $line,
        public readonly int $column,
    ) {}

    /**
     * 이름으로 속성을 찾습니다.
     *
     * @param  string  $name  속성명 (대소문자 구분 — 원문 보존)
     * @return MarkupAttribute|null 속성, 없으면 null
     */
    public function attribute(string $name): ?MarkupAttribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === $name) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * 속성 존재 여부를 판정합니다.
     *
     * @param  string  $name  속성명
     * @return bool 존재하면 true
     */
    public function hasAttribute(string $name): bool
    {
        return $this->attribute($name) !== null;
    }

    /**
     * 주어진 태그명의 자식만 골라 반환합니다.
     *
     * @param  string  $name  태그명
     * @return array<int, MarkupElement> 일치하는 자식 요소
     */
    public function childrenNamed(string $name): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (MarkupElement $child): bool => $child->name === $name,
        ));
    }
}
