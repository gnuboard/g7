<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 본문 HTML 에서 이미지 URL 후보를 추출하는 공용 유틸리티.
 *
 * 게시글(board)·상품 설명(ecommerce)·페이지(page)처럼 에디터로 작성된 본문에서
 * "첫 번째 내부 이미지" 를 뽑아 목록 썸네일·og:image 폴백 캐시로 쓰기 위한 계약이다.
 * 내부/외부 판정은 origin 정규화 SSoT 인
 * {@see TrustedScriptHosts::normalizeForOriginCheck()} 를 그대로 재사용한다 —
 * 문자열상 path 로 보이지만 브라우저가 외부 origin 으로 읽는 형태
 * (`/\/evil.com/x.jpg` 등)를 내부로 오판하지 않기 위함이다.
 *
 * 다국어 JSON 콘텐츠(로케일별 HTML 배열)는 호출측이 로케일을 순회하며 이 클래스에는
 * 단일 HTML 문자열만 전달한다.
 */
final class HtmlImageExtractor
{
    /**
     * 반환 URL 최대 길이 (캐시 컬럼 `string(1000)` 상한 정합).
     */
    private const MAX_URL_LENGTH = 1000;

    /**
     * 파서가 요구하는 문서 골격을 씌울 래퍼 요소 id.
     */
    private const ROOT_ID = 'g7-html-image-extractor-root';

    /**
     * HTML 에서 모든 `<img>` 의 `src` 속성을 문서 순서로 수집합니다.
     *
     * ext-dom 부재·파싱 실패 시에는 안전측(빈 배열)으로 폴백합니다.
     *
     * @param  string  $html  본문 HTML
     * @return array<int, string> src 속성값 목록 (빈 값 제외, 문서 순서)
     */
    public static function candidates(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        if (! class_exists(\DOMDocument::class)) {
            return [];
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
                return [];
            }

            $sources = [];

            foreach ($document->getElementsByTagName('img') as $img) {
                if (! $img instanceof \DOMElement) {
                    continue;
                }

                $src = trim($img->getAttribute('src'));

                if ($src !== '') {
                    $sources[] = $src;
                }
            }

            return $sources;
        } catch (\Throwable $e) {
            Log::warning('본문 이미지 추출 실패 — 후보 없음으로 폴백', [
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * 본문 HTML 에서 첫 번째 **내부** 이미지 URL 을 반환합니다.

     * 내부 판정 규칙 (후보를 문서 순서로 순회하며 첫 통과 값):
     * 1. {@see TrustedScriptHosts::normalizeForOriginCheck()} 로 정규화
     * 2. 정규화 결과 `//` 시작(protocol-relative) → 외부로 제외, `/` 단일 시작 → 내부
     * 3. http(s) 절대 URL 은 host 가 `config('app.url')` host 와 동일(대소문자 무시)할
     *    때만 통과하고 path 부터의 상대 형태로 변환해 반환 (응답 절대화는 소비측 규약)
     * 4. `$extraAllowedPrefixes` 중 하나로 시작(정규화 후 비교)하면 통과 — CDN 스토리지
     *    확장용이며 이 경우 원형 그대로 반환
     * 5. `data:` / `javascript:` 등 비 http(s)·비 path 스킴은 전부 제외
     *
     * @param  string  $html  본문 HTML
     * @param  array<int, string>  $extraAllowedPrefixes  추가 허용 URL prefix 목록
     * @return string|null 첫 내부 이미지 URL (후보 없으면 null)
     */
    public static function firstInternal(string $html, array $extraAllowedPrefixes = []): ?string
    {
        foreach (self::candidates($html) as $src) {
            $resolved = self::resolveInternal($src, $extraAllowedPrefixes);

            if ($resolved === null) {
                continue;
            }

            // 컬럼 상한 초과 URL 은 캐시 불가 — 다음 후보로 넘어간다
            if (mb_strlen($resolved) > self::MAX_URL_LENGTH) {
                continue;
            }

            return $resolved;
        }

        return null;
    }

    /**
     * 단일 src 후보를 내부 URL 로 해석합니다.
     *
     * @param  string  $src  img src 속성값
     * @param  array<int, string>  $extraAllowedPrefixes  추가 허용 URL prefix 목록
     * @return string|null 내부로 판정된 URL (외부/비허용 스킴이면 null)
     */
    private static function resolveInternal(string $src, array $extraAllowedPrefixes): ?string
    {
        $trimmed = trim($src);

        if ($trimmed === '') {
            return null;
        }

        $normalized = TrustedScriptHosts::normalizeForOriginCheck($trimmed);

        if ($normalized === '') {
            return null;
        }

        // protocol-relative 는 외부 origin
        if (str_starts_with($normalized, '//')) {
            return null;
        }

        // 단일 슬래시 시작 = same-origin 경로
        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        $scheme = parse_url($normalized, PHP_URL_SCHEME);

        if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
            $host = parse_url($normalized, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($host) && is_string($appHost) && strcasecmp($host, $appHost) === 0) {
                $path = parse_url($normalized, PHP_URL_PATH);
                $query = parse_url($normalized, PHP_URL_QUERY);

                return (is_string($path) && $path !== '' ? $path : '/')
                    .(is_string($query) && $query !== '' ? '?'.$query : '');
            }
        }

        // 확장이 선언한 허용 prefix (CDN 직접 URL 스토리지 등) — 원형 유지 반환
        foreach ($extraAllowedPrefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($normalized, $prefix)) {
                return $trimmed;
            }
        }

        // 그 외 스킴(data:/javascript:/blob: 등)·미허용 host 전부 제외
        return null;
    }
}
