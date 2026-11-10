<?php

namespace App\Support\Content;

/**
 * HTML 요소 분류 — 저장 정화기가 HTMLPurifier 정의에 요소를 더할 때 인라인·블록을 정하는 기준 (#887)
 *
 * 본문 콘텐츠 규칙(`ContentHtmlPolicy`)의 후보 요소(audio·video 등)와 운영자 추가 태그를 HTMLPurifier 정의에 더할 때, 「문구 콘텐츠」
 * (phrasing content)는 인라인으로 정의해야 문단(`<p>`) 안에 그대로 남는다. 블록으로 정의하면 HTMLPurifier 가 문단을 그 앞에서 닫아
 * `<p></p><audio>` 처럼 빈 문단을 남기고 요소를 문단 밖으로 뺀다. 저장 정화를 하는 확장이 같은 분류를 쓰도록 코어 한 곳에 둔다.
 *
 * @since 7.1.5
 */
final class HtmlElementCategories
{
    /**
     * HTML 표준(WHATWG HTML Living Standard 「Phrasing content」 절)의 「문구 콘텐츠」(phrasing content) 요소.
     *
     * 문단 안에 둘 수 있는 요소의 표준 분류다. 여기 없는 요소(details·figure·section 등 흐름 콘텐츠)는 블록이다. 하이픈이 든 자율
     * 사용자 정의 요소도 문구 콘텐츠다(`isPhrasingContent`).
     */
    public const PHRASING_CONTENT = [
        'a', 'abbr', 'area', 'audio', 'b', 'bdi', 'bdo', 'br', 'button', 'canvas', 'cite', 'code', 'data', 'datalist', 'del', 'dfn',
        'em', 'embed', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label', 'link', 'map', 'mark', 'math', 'meta', 'meter', 'noscript',
        'object', 'output', 'picture', 'progress', 'q', 'ruby', 's', 'samp', 'script', 'select', 'slot', 'small', 'span', 'strong',
        'sub', 'sup', 'svg', 'template', 'textarea', 'time', 'u', 'var', 'video', 'wbr',
    ];

    /**
     * 표준 분류는 없지만 미디어·그림 안에서만 쓰는 빈 요소 — 미디어·문단 안에 놓일 수 있게 인라인으로 둔다.
     */
    public const INLINE_EMPTY_MEDIA_ELEMENTS = ['source', 'track', 'oembed'];

    /**
     * 문구 콘텐츠인지 — 표준 목록이거나 하이픈이 든 자율 사용자 정의 요소.
     *
     * @param  string  $tag  태그 이름
     * @return bool 문구 콘텐츠면 true
     */
    public static function isPhrasingContent(string $tag): bool
    {
        $tag = strtolower($tag);

        return in_array($tag, self::PHRASING_CONTENT, true) || str_contains($tag, '-');
    }

    /**
     * HTMLPurifier 정의(`HTMLPurifier_HTMLDefinition::addElement` 의 종류 인자)에 더할 요소의 종류.
     *
     * source·track·oembed 는 `Inline`, 문구 콘텐츠면 `Inline`, 그 밖은 `Block`.
     *
     * @param  string  $tag  태그 이름
     * @return string `Inline` | `Block`
     */
    public static function purifierElementType(string $tag): string
    {
        $tag = strtolower($tag);

        if (in_array($tag, self::INLINE_EMPTY_MEDIA_ELEMENTS, true)) {
            return 'Inline';
        }

        return self::isPhrasingContent($tag) ? 'Inline' : 'Block';
    }
}
