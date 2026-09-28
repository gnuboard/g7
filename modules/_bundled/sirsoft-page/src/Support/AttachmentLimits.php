<?php

namespace Modules\Sirsoft\Page\Support;

/**
 * 페이지 첨부 상한·허용 형식 해석 (업로드 검증과 화면 공개 설정이 함께 쓰는 단일 지점)
 *
 * 모듈 환경설정 `attachment.*` 가 SSoT 이고, 이 클래스는 그 값을 읽어 기본값·폴백을 입힙니다.
 * 업로드 검증(`UploadPageAttachmentRequest`)과 화면용 공개 설정(`PageAttachmentService::publicLimits()`)
 * 이 **같은 해석**을 써야 화면이 안내한 상한과 서버가 거절하는 상한이 갈리지 않습니다.
 *
 * HTTP 계층·Service 계층 어디에도 속하지 않는 하위 지점에 둡니다 — Service 가 FormRequest 의
 * 상수를 읽으면 하위 계층이 상위 계층(HTTP)에 기대는 역전이 됩니다.
 */
final class AttachmentLimits
{
    /**
     * 허용 형식 폴백 (config/settings/defaults.json 의 attachment.allowed_types 와 동일)
     *
     * 설정이 로드되지 않은 상태에서도 형식 제한이 유지되도록 하는 안전값입니다.
     */
    public const FALLBACK_ALLOWED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/zip',
    ];

    /**
     * 파일 1개 크기 상한(MB)을 반환합니다. 1 미만은 1 로 올립니다.
     *
     * @return int 크기 상한 (MB)
     */
    public static function maxSizeMb(): int
    {
        return max(1, (int) g7_module_settings('sirsoft-page', 'attachment.max_size_mb', 10));
    }

    /**
     * 첨부 개수 상한을 반환합니다. 0 이하는 "제한 없음" 입니다.
     *
     * @return int 개수 상한 (0 이하 = 제한 없음)
     */
    public static function maxCount(): int
    {
        return (int) g7_module_settings('sirsoft-page', 'attachment.max_count', 0);
    }

    /**
     * 허용 MIME 목록을 반환합니다.
     *
     * 설정 키가 없으면(신규 설치 직후 등) 폴백 목록을 씁니다. 명시적 빈 배열만 "제한 없음" 입니다.
     *
     * @return array<int, string> 허용 MIME 목록
     */
    public static function allowedTypes(): array
    {
        $allowedTypes = g7_module_settings('sirsoft-page', 'attachment.allowed_types', null);
        if (! is_array($allowedTypes)) {
            $allowedTypes = self::FALLBACK_ALLOWED_TYPES;
        }

        return array_values(array_filter(array_map('strval', $allowedTypes)));
    }
}
