<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

/**
 * 플러그인 설정 스키마 (`Plugin::getSettingsSchema()` 가 부른다).
 *
 * 정책과 IdP 설정은 여기 넣지 않는다 — 플러그인 표에 플러그인 API 로 저장한다(코어 설정 저장 경로로 최고관리자 전용 검사를
 * 우회해 같은 값을 저장할 수 없게).
 *
 * @since 1.0.0
 */
final class SettingsSchema
{
    /**
     * 설정 스키마를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public static function all(): array
    {
        return [];
    }
}
