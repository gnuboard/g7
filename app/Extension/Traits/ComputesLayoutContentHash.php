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
     * @return string SHA-256 해시
     */
    protected function computeContentHash(array|string $content): string
    {
        return hash('sha256', $this->normalizeContent($content));
    }

    /**
     * 레이아웃 콘텐츠의 정규화된 바이트 크기를 계산합니다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     * @return int 정규화된 JSON 의 바이트 크기
     */
    protected function computeContentSize(array|string $content): int
    {
        return strlen($this->normalizeContent($content));
    }

    /**
     * 콘텐츠를 정규화된 JSON 문자열로 변환합니다.
     *
     * 배열로 받아도 JSON 을 한 번 왕복시켜 연관 배열로 맞춘다. 파일을 컴파일한 본문은 빈 객체를 객체(`{}`)로
     * 들고 있지만 DB 에서 읽은 본문은 배열 캐스트로 빈 배열(`[]`)이 되므로, 그대로 인코딩하면 아무도 고치지
     * 않은 레이아웃이 설치 직후부터 "수정됨"으로 판정된다(#138 실측 ⑥). 7.0.x 방식으로 저장된 해시는
     * 7.1.0 업그레이드 스텝(RehashLayoutOriginalContent)이 새 방식으로 다시 적는다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     * @return string 정규화된 JSON 문자열
     */
    protected function normalizeContent(array|string $content): string
    {
        $decoded = is_string($content)
            ? json_decode($content, true)
            : json_decode(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
