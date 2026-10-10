<?php

namespace App\Enums;

/**
 * 언어팩이 켜져 있지 않은 사유 (`language_packs.deactivated_reason` 값 도메인).
 *
 * 확장(모듈·플러그인·템플릿)의 `DeactivationReason` 과 같은 이름의 컬럼이지만 값 도메인은 언어팩 전용이다.
 * 값이 없으면(null) 별다른 사유가 없는 상태 — 사용자가 끄거나 켜지 않은 그대로다.
 */
enum LanguagePackDeactivationReason: string
{
    /**
     * 켜 달라는 요청으로 설치됐지만 대상 확장이 꺼져 있어 꺼진 채 설치된 상태.
     * 대상 확장을 켤 때 함께 켜지고, 켜지면 사유가 지워진다.
     */
    case AwaitingHost = 'awaiting_host';

    /**
     * 모든 값 배열
     *
     * @return array<string> 사유 값 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
