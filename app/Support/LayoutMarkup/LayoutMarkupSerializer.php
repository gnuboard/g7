<?php

namespace App\Support\LayoutMarkup;

/**
 * `MarkupElement` 트리를 `.g7.html` 소스 문자열로 직렬화하는 층 (S9 역변환의 마지막 단계).
 *
 * 역변환기(`LayoutMarkupDecompiler`)가 JSON 을 요소 트리로 되돌리면, 이 층이 그 트리를 토크나이저가
 * **똑같이 되읽을** 문자열로 찍는다. 그래서 이 층의 유일한 관심사는 토크나이저(S1)의 어휘 규칙을
 * 한 치도 어기지 않는 것이다 — 이스케이프·인용·void·self-closing·주석 배치. 이 규칙을 여기 한 곳에
 * 모으는 이유는 역변환 각 층이 문자열 기계를 각자 구현하면 미묘하게 어긋나 왕복이 깨지기 때문이다.
 *
 * **핵심 규칙 하나만 특별하다 — 텍스트 앞뒤 공백이 의미를 갖는 요소는 한 줄로 찍는다(inline).**
 * 트리 빌더의 `normalizeText` 는 요소 텍스트의 앞뒤 "개행+공백" 을 인덴트로 보고 걷어낸다. 그래서
 * 자식·텍스트를 들여쓰기로 늘어놓아도 텍스트는 대개 그대로이고, 값이 달라지는 것은 텍스트 자신이
 * 공백으로 시작하거나 끝날 때뿐이다(` $t:...` 의 선행 공백이 인덴트에 흡수된다). 그 경우와 자식이
 * 없는 경우만 한 줄로 붙이고, 나머지는 자식을 인덴트로 전개한 뒤 글자를 자기 줄에 둔다
 * ({@see mustRenderInline()}). 자식 사이 개행+공백은 어차피 null 로 걷혀 왕복에 무해하다.
 *
 * 종전에는 텍스트가 있으면 무조건 서브트리 전체를 한 줄로 찍었다 — 액션(`<on>`)을 실은 버튼 하나가
 * 1,800자를 넘겨 소스가 읽히지 않았고, 그것이 이 규칙을 좁힌 이유다.
 */
final class LayoutMarkupSerializer
{
    /** 인덴트 한 단계 (2칸 — fixture 관례와 정합) */
    private const INDENT = '  ';

    /**
     * void 요소 — 끝태그 없이 self-closing 으로만 찍는다 (토크나이저 VOID_ELEMENTS 와 동일 11종).
     *
     * `img`·`input` 은 제외(D36) — 액션(`<on>`)을 실을 수 있어 자식을 갖는다.
     *
     * @var array<int, string>
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'link', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * 요소 트리를 `.g7.html` 소스로 직렬화합니다.
     *
     * @param  MarkupElement  $root  루트 요소
     * @return string `.g7.html` 소스 (끝 개행 포함)
     */
    public function serialize(MarkupElement $root): string
    {
        return $this->renderElement($root, 0)."\n";
    }

    /**
     * 요소 하나를 (자식 포함) 문자열로 찍습니다.
     *
     * 텍스트를 가진 요소는 서브트리 전체를 한 줄로(inline) 찍어 선행/후행 공백을 보전한다.
     *
     * @param  MarkupElement  $element  요소
     * @param  int  $depth  들여쓰기 깊이
     * @return string 찍힌 문자열 (선행 인덴트 포함, 끝 개행 없음)
     */
    private function renderElement(MarkupElement $element, int $depth): string
    {
        $indent = str_repeat(self::INDENT, $depth);

        $prefix = $element->comment !== null ? $indent.'<!-- '.$element->comment.' -->'."\n" : '';

        // 텍스트 앞뒤 공백이 **의미를 갖는** 요소만 한 줄로 — normalizeText 의 개행 스트립을 피한다.
        // 주석은 이 자리에서 윗줄로 빼므로 renderInline 에는 싣지 않는다(중복 방지). 주석 위치는
        // 텍스트 공백에 닿지 않아 분리해도 무손실이고, 그래야 요소 종류와 무관하게 형태가 같다.
        if ($element->text !== null && $this->mustRenderInline($element)) {
            return $prefix.$indent.$this->renderInline($element, withComment: false);
        }

        $open = '<'.$element->name.$this->renderAttributes($element->attributes);

        if ($element->children === [] && ! $this->isVoid($element)) {
            return $prefix.$indent.$open.'/>';
        }

        if ($this->isVoid($element)) {
            return $prefix.$indent.$open.'/>';
        }

        $lines = [$indent.$open.'>'];

        foreach ($element->children as $child) {
            $lines[] = $this->renderElement($child, $depth + 1);
        }

        // 글자는 자식 뒤에 자기 줄로 — inline 판본의 순서(자식 → 텍스트)와 같다.
        if ($element->text !== null) {
            $lines[] = $indent.self::INDENT.$this->escapeText($element->text);
        }

        $lines[] = $indent.'</'.$element->name.'>';

        return $prefix.implode("\n", $lines);
    }

    /**
     * 한 줄로 찍어야만 텍스트가 보존되는 요소인지 판정합니다.
     *
     * 트리 빌더의 `normalizeText` 는 텍스트 **앞뒤**의 "개행+공백" 을 인덴트로 보고 걷어낸다.
     * 그래서 자식을 들여쓰기로 펼쳐도 텍스트가 그대로인 경우가 대부분이고, 값이 달라지는 것은
     * 텍스트 자신이 공백으로 시작하거나 끝날 때뿐이다(`" — {{v}}"` 의 선행 공백이 인덴트에
     * 흡수된다). 자식이 없는 요소는 나눌 이유가 없으므로 그대로 한 줄이다.
     *
     * @param  MarkupElement  $element  텍스트를 가진 요소
     * @return bool 한 줄로 찍어야 하면 true
     */
    private function mustRenderInline(MarkupElement $element): bool
    {
        if ($element->children === []) {
            return true;
        }

        $text = (string) $element->text;

        return $text === '' || preg_match('/^\s|\s$/u', $text) === 1;
    }

    /**
     * 요소(및 서브트리)를 개행 없이 한 줄로 찍습니다 (텍스트 공백 보전용).
     *
     * 중첩 자식의 주석은 그대로 한 줄에 싣는다 — 서브트리가 통째로 한 줄이라 뺄 자리가 없다.
     * 최상위 요소의 주석만 호출부가 `withComment: false` 로 윗줄로 뺀다.
     *
     * @param  MarkupElement  $element  요소
     * @param  bool  $withComment  이 요소의 주석을 한 줄 안에 실을지
     * @return string 한 줄 문자열
     */
    private function renderInline(MarkupElement $element, bool $withComment = true): string
    {
        $comment = $withComment && $element->comment !== null ? '<!-- '.$element->comment.' -->' : '';
        $open = '<'.$element->name.$this->renderAttributes($element->attributes);

        if ($element->text === null && $element->children === []) {
            return $comment.$open.'/>';
        }

        $body = '';

        foreach ($element->children as $child) {
            $body .= $this->renderInline($child);
        }

        if ($element->text !== null) {
            $body .= $this->escapeText($element->text);
        }

        return $comment.$open.'>'.$body.'</'.$element->name.'>';
    }

    /**
     * 속성 목록을 ` name="value"` 문자열로 찍습니다 (불리언은 이름만).
     *
     * @param  array<int, MarkupAttribute>  $attributes  속성 목록
     * @return string 속성 문자열 (선행 공백 포함, 없으면 빈 문자열)
     */
    private function renderAttributes(array $attributes): string
    {
        $out = '';

        foreach ($attributes as $attribute) {
            if ($attribute->value === null) {
                $out .= ' '.$attribute->name;

                continue;
            }

            $quote = $this->chooseQuote($attribute->value);
            $out .= ' '.$attribute->name.'='.$quote.$this->escapeAttr($attribute->value, $quote).$quote;
        }

        return $out;
    }

    /**
     * 속성값을 감쌀 따옴표를 고릅니다.
     *
     * `"` 를 담고 `'` 는 없으면 `'` 로 감싸 이스케이프를 피한다(예: JSON 리터럴 `[{"id":..}]`).
     * 그 밖은 `"` 를 쓰고 내부 `"` 만 char ref 로 이스케이프한다.
     *
     * @param  string  $value  속성값
     * @return string 따옴표 문자 (`"` 또는 `'`)
     */
    private function chooseQuote(string $value): string
    {
        if (str_contains($value, '"') && ! str_contains($value, "'")) {
            return "'";
        }

        return '"';
    }

    /**
     * 속성값을 이스케이프합니다 (`&` + 구분 따옴표만 — `<`/`>` 는 인용값 안에서 그대로 통과).
     *
     * @param  string  $value  속성값
     * @param  string  $quote  구분 따옴표
     * @return string 이스케이프된 값
     */
    private function escapeAttr(string $value, string $quote): string
    {
        $value = str_replace('&', '&amp;', $value);

        return $quote === '"'
            ? str_replace('"', '&quot;', $value)
            : str_replace("'", '&#39;', $value);
    }

    /**
     * 텍스트 내용을 이스케이프합니다 (`&`·`<` 만 — `>`/`{`/`}` 는 그대로).
     *
     * 토크나이저는 텍스트에서 char ref 를 디코드하므로, `&`→`&amp;`·`<`→`&lt;` 만 하면 왕복한다.
     * `{{...}}`/`$t:` 는 평범한 텍스트 바이트라 손대지 않는다.
     *
     * @param  string  $text  텍스트
     * @return string 이스케이프된 텍스트
     */
    private function escapeText(string $text): string
    {
        return str_replace(['&', '<'], ['&amp;', '&lt;'], $text);
    }

    /**
     * void 요소인지 판정합니다.
     *
     * @param  MarkupElement  $element  요소
     * @return bool void 면 true
     */
    private function isVoid(MarkupElement $element): bool
    {
        return in_array($element->name, self::VOID_ELEMENTS, true);
    }
}
