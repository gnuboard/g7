<?php

namespace App\Seo;

use Illuminate\Support\Facades\Log;

/**
 * 봇 화면에 삽입되는 사용자 작성 HTML 을 정화합니다.
 *
 * 일반 화면(React)은 `HtmlContent` 컴포지트가 DOMPurify 로 정화한 결과를 그리므로,
 * 봇 화면도 **같은 강도**로 정화해야 두 화면의 출력이 일치합니다. 정화하지 않으면
 * 봇 화면에서만 `<script>` / `onerror` 등이 살아 있어, 주소에 봇 파라미터를 붙이는
 * 것만으로 저장된 스크립트가 실행됩니다.
 *
 * 차단 목록은 아래 파일의 DOMPurify 설정을 옮긴 것입니다 — 한쪽만 고치면 두 화면의
 * 정화 강도가 어긋나므로 **함께 갱신**해야 합니다.
 *
 * @see templates/_bundled/sirsoft-basic/src/components/composite/HtmlContent.tsx
 * @see docs/backend/seo-system.md "봇 화면의 HTML 정화"
 */
class HtmlSanitizer
{
    /**
     * 정화 대상 HTML 을 감쌀 래퍼 요소 id.
     *
     * DOM 파서가 요구하는 문서 골격을 씌운 뒤 이 요소의 자식만 되돌려 받습니다.
     */
    private const ROOT_ID = 'g7-seo-sanitize-root';

    /**
     * 요소와 내용을 통째로 제거할 태그 목록.
     *
     * 스크립트 실행 · 외부 콘텐츠 삽입 · 문서 구조 조작처럼 내부 텍스트를 남기는 것조차
     * 위험하거나 무의미한 태그입니다. (DOMPurify 의 `FORBID_CONTENTS` 계열 동작)
     */
    private const DROP_WITH_CONTENT = [
        'script', 'noscript', 'style', 'template',
        'iframe', 'frame', 'frameset',
        'title', 'head',
        'svg', 'math',
        'plaintext', 'xmp', 'listing',
        'noframes', 'noembed',
        'audio', 'video',
    ];

    /**
     * 요소만 벗기고 내부 텍스트는 남길 태그 목록.
     *
     * 본문 텍스트를 품고 있을 수 있어 통째로 버리면 콘텐츠가 사라지는 태그입니다.
     * (DOMPurify 의 `KEEP_CONTENT: true` 기본 동작)
     */
    private const UNWRAP_KEEP_CONTENT = [
        'object', 'embed', 'applet', 'portal',
        'link', 'meta', 'base',
        'form', 'input', 'textarea', 'select', 'option', 'button',
        'body', 'html',
        'source', 'track', 'canvas',
        'details', 'dialog',
        'marquee', 'slot',
    ];

    /**
     * 제거할 속성 목록 (`on*` 이벤트 핸들러는 전부 별도로 제거).
     */
    private const FORBID_ATTRS = [
        'formaction', 'xlink:href', 'action', 'srcdoc', 'ping',
    ];

    /**
     * URL 값을 담는 속성 목록 (스킴 검사 대상).
     */
    private const URL_ATTRS = ['href', 'src', 'srcset', 'poster', 'background', 'cite', 'longdesc'];

    /**
     * URL 속성에 허용하는 스킴.
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel', 'sms', 'ftp', 'cid', 'xmpp', 'callto'];

    /**
     * `data:` URL 중 허용하는 형태 (이미지 한정 — svg 는 스크립트 실행 벡터라 제외).
     */
    private const ALLOWED_DATA_URL = '/^data:image\/(png|jpe?g|gif|webp|avif|bmp);/i';

    /**
     * 사용자 작성 HTML 을 정화해 반환합니다.
     *
     * 파싱에 실패하면 정화되지 않은 HTML 을 내보내지 않고 전체를 이스케이프합니다
     * (실패 시 안전한 쪽으로 기웁니다).
     *
     * @param  string  $html  정화할 HTML
     * @return string 정화된 HTML
     */
    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (! class_exists(\DOMDocument::class)) {
            return e($html);
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="'
                    .self::ROOT_ID.'">'.$html.'</div></body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if (! $loaded) {
                return e($html);
            }

            $root = $document->getElementById(self::ROOT_ID);
            if (! $root instanceof \DOMElement) {
                return e($html);
            }

            $this->cleanNode($root);

            return $this->innerHtml($document, $root);
        } catch (\Throwable $e) {
            Log::warning('봇 화면 HTML 정화 실패 — 전체 이스케이프로 대체', [
                'error' => $e->getMessage(),
            ]);

            return e($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * 노드와 그 자손을 재귀적으로 정화합니다.
     *
     * @param  \DOMNode  $node  대상 노드
     */
    private function cleanNode(\DOMNode $node): void
    {
        // 자식 목록을 먼저 복사한다 — 순회 중 DOM 을 변경하므로 라이브 NodeList 를 쓰면 항목을 건너뛴다.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $this->cleanElement($child);

                continue;
            }

            // 주석은 봇 화면에 남길 이유가 없고 조건부 주석 등 파서 혼란 요인이 된다.
            if ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /**
     * 단일 요소를 정화합니다 (제거 / 언랩 / 속성 정리).
     *
     * @param  \DOMElement  $element  대상 요소
     */
    private function cleanElement(\DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (in_array($tag, self::UNWRAP_KEEP_CONTENT, true)) {
            $this->cleanNode($element);
            $this->unwrap($element);

            return;
        }

        $this->cleanAttributes($element);
        $this->cleanNode($element);
    }

    /**
     * 요소를 제거하고 자식 노드를 부모의 같은 자리에 올립니다.
     *
     * @param  \DOMElement  $element  대상 요소
     */
    private function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * 요소의 속성을 정리합니다.
     *
     * @param  \DOMElement  $element  대상 요소
     */
    private function cleanAttributes(\DOMElement $element): void
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->nodeName;
        }

        foreach ($attributes as $name) {
            $lower = strtolower($name);

            // 이벤트 핸들러 전량 제거 (onerror, onload, onanimationend, ...)
            if (str_starts_with($lower, 'on')) {
                $element->removeAttribute($name);

                continue;
            }

            if (in_array($lower, self::FORBID_ATTRS, true)) {
                $element->removeAttribute($name);

                continue;
            }

            if (in_array($lower, self::URL_ATTRS, true)
                && ! $this->isAllowedUrl($element->getAttribute($name))) {
                $element->removeAttribute($name);
            }
        }

        // 외부 링크에 rel 보강 (일반 화면의 HtmlContent 후처리와 동일)
        if (strtolower($element->tagName) === 'a' && ! $element->hasAttribute('rel')) {
            $href = $element->getAttribute('href');
            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    /**
     * URL 속성 값이 허용된 스킴인지 판정합니다.
     *
     * 상대 경로 · 앵커 · 프로토콜 상대 경로는 허용하고, `javascript:` 처럼 스크립트를
     * 실행할 수 있는 스킴은 차단합니다.
     *
     * @param  string  $url  검사할 URL
     * @return bool 허용 여부
     */
    private function isAllowedUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return true;
        }

        // 제어문자를 끼워 넣어 스킴 검사를 우회하는 시도를 막는다 (`java\tscript:` 등)
        $normalized = strtolower(preg_replace('/[\x00-\x20]+/', '', $trimmed) ?? '');

        if (preg_match(self::ALLOWED_DATA_URL, $normalized) === 1) {
            return true;
        }

        // 스킴이 없으면 상대 경로 / 앵커 / 쿼리 → 허용
        if (preg_match('/^([a-z][a-z0-9+.-]*):/', $normalized, $matches) !== 1) {
            return true;
        }

        return in_array($matches[1], self::ALLOWED_SCHEMES, true);
    }

    /**
     * 요소의 내부 HTML 을 문자열로 반환합니다.
     *
     * @param  \DOMDocument  $document  소속 문서
     * @param  \DOMElement  $element  대상 요소
     * @return string 내부 HTML
     */
    private function innerHtml(\DOMDocument $document, \DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }
}
