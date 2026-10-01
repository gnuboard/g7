<?php

namespace App\Extension\Traits;

use App\Support\LayoutMarkup\EmptyMap;
use Illuminate\Database\Eloquent\Model;

/**
 * 레이아웃 콘텐츠의 정규화된 해시/크기를 계산하는 공통 로직.
 *
 * template_layouts 테이블의 original_content_hash / original_content_size
 * 컬럼에 기록되는 값이며, 확장 업데이트 시 사용자 수정 여부 판단에 사용됩니다.
 *
 * Module/Plugin/Template 매니저에서 공용으로 사용합니다.
 *
 * ## 빈 map(`{}`)과 빈 list(`[]`)
 *
 * `.g7.html` 컴파일 산출은 빈 map 을 {@see EmptyMap} 표식으로 들고 있어
 * `json_encode` 가 `{}` 를 낸다. 그런데 DB 에서 읽은 content 는 `'array'` 캐스트를 거치며 `{}` 가 `[]` 로
 * 바뀐다. 두 쪽을 그대로 직렬화해 비교하면 **손대지 않은 레이아웃도 "사용자 수정" 으로 판정**된다(크기는 같고
 * 해시만 다르다). 그래서 정규화는 빈 컨테이너를 모두 `[]` 로 맞춘 표준형으로 계산하고, 표준형 도입 전에
 * `{}` 를 그대로 담아 저장된 원본 해시는 {@see contentMatchesOriginalHash()} 가 DB 원문으로 함께 대조한다 —
 * 기존 행을 데이터 이관 없이 바로잡기 위해서다.
 */
trait ComputesLayoutContentHash
{
    /**
     * 레이아웃 콘텐츠의 정규화된 SHA-256 해시를 계산합니다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     * @return string 표준형 JSON 의 SHA-256 16진 해시 (64자)
     */
    protected function computeContentHash(array|string $content): string
    {
        return hash('sha256', $this->normalizeContent($content));
    }

    /**
     * 레이아웃 콘텐츠의 정규화된 바이트 크기를 계산합니다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     * @return int 표준형 JSON 의 바이트 수
     */
    protected function computeContentSize(array|string $content): int
    {
        return strlen($this->normalizeContent($content));
    }

    /**
     * 콘텐츠를 정규화된 JSON 문자열(표준형)로 변환합니다.
     *
     * 배열은 한 번 직렬화한 뒤 연관 배열로 되읽어, 컴파일 산출의 빈 map 표식(`{}`)과 DB 캐스트가 돌려준
     * 빈 배열(`[]`)이 같은 문자열이 되게 한다. 비어 있지 않은 map 은 되읽어도 키가 남으므로 그대로 `{...}` 다.
     *
     * 배열로 받아도 JSON 을 한 번 왕복시켜 연관 배열로 맞춘다. 파일을 컴파일한 본문은 빈 객체를 객체(`{}`)로
     * 들고 있지만 DB 에서 읽은 본문은 배열 캐스트로 빈 배열(`[]`)이 되므로, 그대로 인코딩하면 아무도 고치지
     * 않은 레이아웃이 설치 직후부터 "수정됨"으로 판정된다(#138 실측 ⑥). 7.0.x 방식으로 저장된 해시는
     * 7.1.0 업그레이드 스텝(RehashLayoutOriginalContent)이 새 방식으로 다시 적는다.
     *
     * @param  array|string  $content  배열 또는 JSON 문자열
     * @return string 빈 컨테이너를 `[]` 로 맞춘 표준형 JSON 문자열
     */
    protected function normalizeContent(array|string $content): string
    {
        $json = is_string($content)
            ? $content
            : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) json_encode(json_decode((string) $json, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 현재 content 가 원본 해시와 같은지(= 운영자가 손대지 않았는지) 판정합니다.
     *
     * 표준형 해시가 같으면 같다. 표준형 도입 전에 저장된 원본 해시는 빈 map 을 `{}` 로 담아 계산됐으므로,
     * DB 원문(`$rawContent`)을 객체 보존 디코드로 다시 직렬화한 해시도 함께 대조한다 — DB 원문에는 `{}` 가
     * 그대로 남아 있다. 원문을 모르면(배열만 있으면) 배열을 그대로 직렬화한 해시를 대신 본다.
     *
     * @param  array|string  $content  현재 content (캐스트된 배열 또는 JSON 문자열)
     * @param  string  $originalHash  저장된 원본 해시
     * @param  string|null  $rawContent  DB 원문 JSON ({@see rawStoredContent()}) — 모르면 null
     * @return bool 원본과 같으면 true (수정 안 됨)
     */
    protected function contentMatchesOriginalHash(array|string $content, string $originalHash, ?string $rawContent = null): bool
    {
        if ($this->computeContentHash($content) === $originalHash) {
            return true;
        }

        $legacy = $rawContent ?? (is_string($content) ? $content : null);

        $legacyJson = $legacy !== null
            ? json_encode(json_decode($legacy, false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($legacyJson) && hash('sha256', $legacyJson) === $originalHash;
    }

    /**
     * 저장된 행이 원본 해시와 달라졌는지(= 운영자가 수정했는지) 판정합니다.
     *
     * 원본 해시가 없는 행(레거시)은 수정되지 않은 것으로 본다 — 종전 호출부들의 규약과 같다.
     *
     * @param  Model  $model  content·original_content_hash 를 가진 레이아웃/확장 모델
     * @return bool 수정됐으면 true
     */
    protected function isStoredContentModified(Model $model): bool
    {
        $originalHash = $model->getAttribute('original_content_hash');

        if (! is_string($originalHash) || $originalHash === '') {
            return false;
        }

        $content = $model->getAttribute('content');

        if (! is_array($content) && ! is_string($content)) {
            return false;
        }

        return ! $this->contentMatchesOriginalHash($content, $originalHash, $this->rawStoredContent($model));
    }

    /**
     * 모델의 content DB 원문(JSON 문자열)을 돌려줍니다 — 캐스트 전 값이라 빈 map `{}` 이 보존돼 있습니다.
     *
     * @param  Model  $model  content 컬럼을 가진 모델
     * @return string|null 원문 JSON, 없으면 null
     */
    protected function rawStoredContent(Model $model): ?string
    {
        $raw = $model->getAttributes()['content'] ?? null;

        return is_string($raw) ? $raw : null;
    }
}
