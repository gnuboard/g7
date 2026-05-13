<?php

namespace App\Extension\Traits;

/**
 * 레이아웃 콘텐츠의 정규화된 해시/크기를 계산하는 공통 로직.
 *
 * template_layouts 테이블의 original_content_hash / original_content_size
 * 컬럼에 기록되는 값이며, 확장 업데이트 시 사용자 수정 여부 판단에 사용됩니다.
 *
 * Module/Plugin/Template 매니저에서 공용으로 사용합니다.
 */
trait ComputesLayoutContentHash
{
    /**
     * 레이아웃 콘텐츠의 정규화된 SHA-256 해시를 계산합니다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     */
    protected function computeContentHash(array|string $content): string
    {
        return hash('sha256', $this->normalizeContent($content));
    }

    /**
     * 레이아웃 콘텐츠의 정규화된 바이트 크기를 계산합니다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     */
    protected function computeContentSize(array|string $content): int
    {
        return strlen($this->normalizeContent($content));
    }

    /**
     * 콘텐츠를 정규화된 JSON 문자열로 변환합니다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     */
    protected function normalizeContent(array|string $content): string
    {
        return is_string($content)
            ? json_encode(json_decode($content, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
