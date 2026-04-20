<?php

namespace App\Extension\Vendor;

/**
 * Vendor 설치 모드.
 *
 * - Auto: composer 사용 가능 시 composer, 불가 시 bundled
 * - Composer: 강제 composer 실행 (불가 시 예외)
 * - Bundled: 강제 vendor-bundle.zip 추출 (zip 없으면 예외)
 */
enum VendorMode: string
{
    case Auto = 'auto';
    case Composer = 'composer';
    case Bundled = 'bundled';

    /**
     * 다국어 라벨.
     */
    public function label(): string
    {
        return match ($this) {
            self::Auto => __('vendor.mode.auto'),
            self::Composer => __('vendor.mode.composer'),
            self::Bundled => __('vendor.mode.bundled'),
        };
    }

    /**
     * 문자열에서 안전하게 변환 (null/auto 폴백).
     */
    public static function fromStringOrAuto(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Auto;
        }

        return self::tryFrom($value) ?? self::Auto;
    }
}
