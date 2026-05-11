<?php

namespace App\Enums;

/**
 * 확장(모듈, 플러그인, 템플릿) 비활성화 사유 Enum
 *
 * `plugins/modules/templates.deactivated_reason` 컬럼의 값 도메인.
 * 사용자 수동 비활성화와 시스템 자동 비활성화를 DB 레벨에서 구분하여
 * UI 라벨링·알림 영속화·재호환 시 원클릭 복구 판정에 사용합니다.
 */
enum DeactivationReason: string
{
    /**
     * 관리자가 직접 비활성화
     */
    case Manual = 'manual';

    /**
     * 코어 버전 호환성 검사 실패로 시스템이 자동 비활성화
     */
    case IncompatibleCore = 'incompatible_core';

    /**
     * 사람이 읽을 수 있는 라벨 (i18n key 가 아닌 fallback 한국어)
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => '사용자 수동 비활성화',
            self::IncompatibleCore => '코어 버전 호환성',
        };
    }

    /**
     * 시스템(자동) 트리거 여부
     *
     * true 인 경우 알림 영속화 + 원클릭 복구 UX 대상이 됩니다.
     */
    public function isSystemTriggered(): bool
    {
        return match ($this) {
            self::Manual => false,
            self::IncompatibleCore => true,
        };
    }

    /**
     * 모든 값 배열
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
