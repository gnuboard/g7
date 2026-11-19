<?php

namespace App\Extension\IdentityVerification\DTO;

/**
 * 본인인증 수단이 확인한 인적 사항.
 *
 * 수단마다 다른 응답 형식을 코어가 쓰는 한 가지 모양으로 맞춘 값이다. 알 수 없는 값은 null 로 둔다.
 *
 * @since 7.2.0
 */
final class VerifiedClaims
{
    /** 인증 값 키: 이름 */
    public const CLAIM_NAME = 'name';

    /** 인증 값 키: 휴대폰 번호 */
    public const CLAIM_MOBILE = 'mobile';

    /** 인증 값 키: 생년월일 */
    public const CLAIM_BIRTH_DATE = 'birth_date';

    /** 인증 값 키: 성별 */
    public const CLAIM_GENDER = 'gender';

    /** 성별 값: 남성 */
    public const GENDER_MALE = 'male';

    /** 성별 값: 여성 */
    public const GENDER_FEMALE = 'female';

    /** 코어가 정한 인증 값 키 */
    public const CLAIMS = [self::CLAIM_NAME, self::CLAIM_MOBILE, self::CLAIM_BIRTH_DATE, self::CLAIM_GENDER];

    /**
     * @param  string|null  $name  이름
     * @param  string|null  $mobile  휴대폰 번호 (숫자만 또는 하이픈 포함)
     * @param  string|null  $birthDate  생년월일 (`YYYY-MM-DD`)
     * @param  string|null  $gender  성별 (`male` 또는 `female`)
     * @param  array<string, scalar|null>  $extra  그 밖의 인증 값 (키 => 값. 확장이 정한 키의 항목 연결에 쓴다)
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $mobile = null,
        public readonly ?string $birthDate = null,
        public readonly ?string $gender = null,
        public readonly array $extra = [],
    ) {}

    /**
     * 인증 값 키로 값을 꺼냅니다.
     *
     * @param  string  $claim  인증 값 키 (`name`, `mobile`, `birth_date`, `gender` 또는 `extra` 의 키)
     * @return string|null 값 (없거나 비었으면 null)
     */
    public function get(string $claim): ?string
    {
        $value = match ($claim) {
            self::CLAIM_NAME => $this->name,
            self::CLAIM_MOBILE => $this->mobile,
            self::CLAIM_BIRTH_DATE => $this->birthDate,
            self::CLAIM_GENDER => $this->gender,
            default => $this->extra[$claim] ?? null,
        };

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * 값이 하나라도 있는지 반환합니다.
     *
     * @return bool 값이 있으면 true
     */
    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * 값이 있는 인증 값만 `키 => 값` 으로 반환합니다 (코어 키 네 개와 `extra`).
     *
     * @return array<string, string> 인증 값 키 => 값
     */
    public function toArray(): array
    {
        $claims = [];
        foreach ([...self::CLAIMS, ...array_keys($this->extra)] as $claim) {
            $value = is_string($claim) ? $this->get($claim) : null;
            if ($value !== null) {
                $claims[$claim] = $value;
            }
        }

        return $claims;
    }
}
