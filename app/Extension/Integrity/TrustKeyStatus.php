<?php

namespace App\Extension\Integrity;

/**
 * 내장 신뢰 키(`BuiltinTrustKeys::KEYS`)의 상태.
 *
 * 목록 항목은 PHP 상수 배열이라 `status` 에 이 Enum 의 문자열 값을 적고, 판정은 이 Enum 으로 한다.
 *
 * @since 7.2.0
 */
enum TrustKeyStatus: string
{
    /** 서명·검증에 쓰는 키 */
    case Active = 'active';

    /** 검증만 통과 — 새 서명에 쓰지 않는 은퇴 키 */
    case Retired = 'retired';

    /** 폐기 — 이 키의 서명은 거부 */
    case Revoked = 'revoked';

    /**
     * 허용하는 상태 문자열 목록을 돌려줍니다.
     *
     * @return array<int, string> 상태 값
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * 이 상태의 키로 서명한 패키지를 받아들이는지 확인합니다.
     *
     * @return bool 폐기가 아니면 true
     */
    public function acceptsSignatures(): bool
    {
        return $this !== self::Revoked;
    }
}
