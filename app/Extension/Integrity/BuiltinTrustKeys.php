<?php

namespace App\Extension\Integrity;

/**
 * 코어에 박힌 신뢰 공개키 목록 (sirsoft 공식 릴리스 서명 키).
 *
 * 이 목록은 PHP 상수다 — `.env`·config·DB 로 덮을 수 없다. 코어 패키지(코어 업데이트)는 이 목록의 키로만 검증하고,
 * 확장·언어팩은 이 목록 또는 운영자가 등록한 제작자 키(vendor 범위 필수)로 검증한다. 내장 키는 플랫폼 발행자의
 * 키라 vendor 범위 없이 모든 패키지에 쓰인다.
 *
 * 항목: `key_id`(서버가 label·공개키로 다시 계산해 대조), `public_key`(raw 32바이트 표준 base64), `label`,
 * `status`(`active` 서명·검증 / `retired` 검증만 통과 — 새 서명에 쓰지 않음 / `revoked` 거부).
 *
 * 운영 키 교체: 새 키를 `active` 로 더한 코어 릴리스를 옛 키로 서명해 먼저 내보내고, 사이트들이 그 판으로 올라온 뒤
 * 새 키로 서명한다. 옛 키는 `retired`(유출이면 `revoked`)로 내린다 — 코어 패키지는 이 목록으로만 검증하므로 한 판을
 * 겹치지 않으면 새 키로 서명한 코어 업데이트를 옛 사이트가 검증할 수 없다.
 *
 * @since 7.2.0
 */
final class BuiltinTrustKeys
{
    /** 내장 키가 쓰는 예약 vendor·key_id 접두 — 제작자 키는 이 이름을 쓸 수 없다 */
    public const RESERVED_VENDOR = 'sirsoft';

    /**
     * 내장 신뢰 키 목록.
     *
     * @var array<int, array{key_id: string, public_key: string, label: string, status: string}>
     */
    public const KEYS = [
        // sirsoft 공식 릴리스 서명 주 키 (2026-11-16 생성)
        [
            'key_id' => 'sirsoft:dc05cd817a9ac65c',
            'public_key' => 'wUvOhUGqnAawLxAFeCkEtGP6CXbN0wVuN8pl7VaYaXU=',
            'label' => 'sirsoft',
            'status' => TrustKeyStatus::Active->value,
        ],
    ];

    /**
     * 테스트에서만 쓰는 대체 목록 (운영 실행에서는 설정할 수 없다).
     *
     * @var array<int, array{key_id: string, public_key: string, label: string, status: string}>|null
     */
    private static ?array $testingKeys = null;

    /**
     * 내장 키 목록을 돌려줍니다.
     *
     * @return array<int, array{key_id: string, public_key: string, label: string, status: string}> 내장 키
     */
    public static function all(): array
    {
        return self::$testingKeys ?? self::KEYS;
    }

    /**
     * 쓸 수 있는(폐기되지 않은) 내장 키가 하나라도 있는지 판정합니다.
     *
     * 진단 경고의 단일 판정이다 — `php artisan about` 의 「패키지 무결성」 구획, `core:check-updates`,
     * `extension:trust-key list` 가 이 메서드로 같은 기준(폐기 아닌 내장 키 0 개면 경고)을 쓴다. 은퇴(`retired`) 키는
     * 검증을 통과하므로 쓸 수 있는 키로 센다.
     *
     * @return bool 폐기되지 않은 내장 키가 있으면 true
     */
    public static function hasUsableKey(): bool
    {
        return self::usableCount() > 0;
    }

    /**
     * 쓸 수 있는(폐기되지 않은) 내장 키 수를 돌려줍니다.
     *
     * @return int 폐기되지 않은 내장 키 수
     */
    public static function usableCount(): int
    {
        return count(array_filter(
            self::all(),
            fn (array $key): bool => ($key['status'] ?? null) !== TrustKeyStatus::Revoked->value,
        ));
    }

    /**
     * key_id 로 내장 키를 찾습니다.
     *
     * @param  string  $keyId  key_id
     * @return array{key_id: string, public_key: string, label: string, status: string}|null 내장 키 (없으면 null)
     */
    public static function find(string $keyId): ?array
    {
        foreach (self::all() as $key) {
            if (($key['key_id'] ?? null) === $keyId) {
                return $key;
            }
        }

        return null;
    }

    /**
     * 테스트 동안만 내장 키 목록을 바꿉니다 (null 이면 원래 목록으로).
     *
     * 단위 테스트 실행이 아니면 아무 일도 하지 않는다 — 운영 코드가 신뢰 목록을 넓히는 길이 되지 않게 한다.
     *
     * @param  array<int, array{key_id: string, public_key: string, label: string, status: string}>|null  $keys  대체 목록
     * @return void 반환값 없음
     */
    public static function useForTesting(?array $keys): void
    {
        if (! app()->runningUnitTests()) {
            return;
        }

        self::$testingKeys = $keys;
    }
}
