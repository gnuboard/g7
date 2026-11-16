<?php

namespace App\Extension\Integrity;

/**
 * 패키지 무결성 검증 결과 상태.
 *
 * 설치 행의 `integrity_status` 컬럼에도 이 값을 그대로 적는다(기록이 없는 옛 설치는 null).
 *
 * @since 7.2.0
 */
enum PackageVerificationStatus: string
{
    /** 신뢰하는 키로 서명이 확인됨 */
    case Verified = 'verified';

    /** 서명 파일이 없음 */
    case Unsigned = 'unsigned';

    /** 서명이 있으나 내용·형식이 맞지 않음(변조·식별자 불일치 등) — 늘 차단 */
    case Invalid = 'invalid';

    /** 서명이 있으나 신뢰하지 않는 키(미등록·폐기·vendor 범위 밖) — 늘 차단 */
    case Untrusted = 'untrusted';

    /** 코어와 함께 배포된 번들 원본(`_bundled`)에서 설치 — 부모 패키지 서명으로 덮인다 */
    case Bundled = 'bundled';

    /** 서명은 있으나 이 서버에 sodium 확장이 없어 검증할 수 없음 — 미서명 정책을 따른다 */
    case Unverifiable = 'unverifiable';

    /**
     * 미서명 정책(경고 후 확인·차단 스위치)을 따르는 상태인지 판정합니다.
     *
     * @return bool 미서명·검증 불가면 true
     */
    public function followsUnsignedPolicy(): bool
    {
        return $this === self::Unsigned || $this === self::Unverifiable;
    }

    /**
     * 예외 없이 차단하는 상태인지 판정합니다.
     *
     * @return bool 서명 불일치·미신뢰 키면 true
     */
    public function isRejected(): bool
    {
        return $this === self::Invalid || $this === self::Untrusted;
    }

    /**
     * 모든 값 목록을 돌려줍니다.
     *
     * @return array<int, string> 상태 값 목록
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
