<?php

namespace App\Search;

/**
 * 검색 결과의 키워드 하이라이트와 본문 프리뷰 평문화를 담당하는 공유 헬퍼.
 *
 * 하이라이트 필드는 소비 측이 HTML 로 렌더하는 계약이므로, 원문을 반드시
 * 이스케이프한 뒤 검색어만 <mark> 로 감싼다. 이스케이프를 건너뛰면 제목·본문에
 * 삽입된 태그가 그대로 실행 계약으로 나간다(게시판/상품/페이지 검색 공통).
 */
class SearchHighlighter
{
    /**
     * 원문을 HTML 이스케이프한 뒤 검색어만 <mark> 로 감쌉니다.
     *
     * @param  string|null  $text  원문 텍스트(평문)
     * @param  string  $keyword  검색어
     * @return string 이스케이프 완료된 안전한 HTML
     */
    public static function highlight(?string $text, string $keyword): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if ($keyword === '') {
            return $safe;
        }

        // 키워드도 동일하게 이스케이프해, 이스케이프된 원문과 일관되게 매칭한다.
        $safeKeyword = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8');
        $escapedKeyword = preg_quote($safeKeyword, '/');

        $result = preg_replace('/('.$escapedKeyword.')/iu', '<mark>$1</mark>', $safe);

        // 유효하지 않은 UTF-8 등으로 preg_replace 가 null 을 반환하면 이스케이프본을 유지한다.
        return $result ?? $safe;
    }

    /**
     * HTML 본문을 태그 없는 평문으로 변환합니다.
     *
     * 엔티티를 먼저 디코드한 뒤 태그를 제거해, 엔티티로 인코딩된 태그가
     * 평문화 단계에서 실제 태그로 부활하지 못하게 합니다.
     *
     * @param  string|null  $html  본문(HTML 또는 평문)
     * @return string 태그가 제거된 평문
     */
    public static function toPlainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($decoded)));
    }
}
