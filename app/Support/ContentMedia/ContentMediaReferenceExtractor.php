<?php

namespace App\Support\ContentMedia;

use Illuminate\Support\Facades\Log;

/**
 * 본문 HTML 이 참조하는 본문 미디어 해시를 뽑습니다 (#883 4단계)
 *
 * `img`·`video`·`audio`·`source` 의 `src` 와 `video` 의 `poster` 가운데 서빙 주소 정규식에 맞는 것의 `hash` 그룹을 모은다.
 * DOM 파싱은 `HtmlImageExtractor` 와 같은 방식이다(본문을 래퍼로 감싸 loadHTML). 본문 미디어 연결·문서당 개수 판정에 쓴다.
 *
 * @since 7.1.5
 */
final class ContentMediaReferenceExtractor
{
    /** 미디어 속성을 읽는 태그 */
    private const TAGS = ['img' => ['src'], 'video' => ['src', 'poster'], 'audio' => ['src'], 'source' => ['src']];

    /**
     * 본문이 참조하는 해시 목록.
     *
     * @param  string|null  $html  본문 HTML
     * @param  string  $pattern  서빙 주소 정규식 본문(구분자 없음, `(?<hash>…)` 그룹 필수) — 예: `/api/…/content-media/(?<hash>[A-Za-z0-9]{12})`
     * @return array<int, string> 해시 (문서 순서, 중복 제거)
     */
    public static function hashes(?string $html, string $pattern): array
    {
        $html = (string) $html;
        if (trim($html) === '' || ! class_exists(\DOMDocument::class)) {
            return [];
        }

        $regex = '#'.str_replace('#', '\#', $pattern).'#';
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING
            )) {
                return [];
            }

            $hashes = [];
            foreach (self::TAGS as $tag => $attributes) {
                foreach ($document->getElementsByTagName($tag) as $element) {
                    if (! $element instanceof \DOMElement) {
                        continue;
                    }
                    foreach ($attributes as $attribute) {
                        $value = trim($element->getAttribute($attribute));
                        if ($value !== '' && preg_match($regex, $value, $m) === 1 && isset($m['hash'])) {
                            $hashes[$m['hash']] = true;
                        }
                    }
                }
            }

            return array_keys($hashes);
        } catch (\Throwable $e) {
            Log::warning('본문 미디어 참조 추출 실패 — 참조 없음으로 폴백', ['error' => $e->getMessage()]);

            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
