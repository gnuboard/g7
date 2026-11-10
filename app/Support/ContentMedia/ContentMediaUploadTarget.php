<?php

namespace App\Support\ContentMedia;

/**
 * 본문 미디어 업로드 계약 객체 — 화면(확장 레이아웃)이 편집기에 넘기는 `mediaUpload` 값 (#883 4단계, D4)
 *
 * 형식은 클라이언트 `ContentMediaUploadTarget`(`resources/js/core/contentMedia/types.ts`, `version: 1`)과 같다. 편집기 플러그인은
 * 이 값을 읽기만 한다 — 어느 편집기든 같은 계약으로 업로드한다(CKEditor 에 의존하지 않는다).
 *
 * @since 7.1.5
 */
final class ContentMediaUploadTarget
{
    /** 계약 버전 */
    public const VERSION = 1;

    /** 호출자가 실을 수 있는 헤더 허용 목록 */
    public const ALLOWED_HEADERS = ['X-Board-Post-Verify-Token'];

    /**
     * 계약 배열을 만듭니다.
     *
     * @param  string  $endpoint  업로드 주소 (같은 출처 상대 경로)
     * @param  ContentMediaPolicy  $policy  정책
     * @param  string  $mediaUrlPattern  서빙 경로 정규식 본문 (문서당 개수 셀 때) — 클라이언트는 `new RegExp(pattern)`
     * @param  array<string, string|int>  $fields  함께 보낼 폼 필드 (예: post_id)
     * @param  array<string, string>  $headers  함께 보낼 헤더 (허용 목록만)
     * @return array<string, mixed> 계약
     */
    public static function toArray(
        string $endpoint,
        ContentMediaPolicy $policy,
        string $mediaUrlPattern,
        array $fields = [],
        array $headers = [],
    ): array {
        return [
            'version' => self::VERSION,
            'endpoint' => $endpoint,
            'fileField' => 'file',
            'fields' => (object) $fields,
            'headers' => (object) array_intersect_key($headers, array_flip(self::ALLOWED_HEADERS)),
            'response' => ['urlPath' => 'data.url', 'idPath' => 'data.id', 'kindPath' => 'data.kind'],
            'mediaUrlPattern' => self::clientPattern($mediaUrlPattern),
            'policy' => $policy->toClientArray(),
        ];
    }

    /**
     * PHP 정규식 본문을 JS `RegExp` 에서도 같은 뜻이 되게 바꿉니다(명명 그룹 `(?<hash>…)` 는 둘 다 지원).
     *
     * @param  string  $pattern  정규식 본문
     * @return string 클라이언트 정규식 본문
     */
    private static function clientPattern(string $pattern): string
    {
        return str_replace('(?P<', '(?<', $pattern);
    }
}
