<?php

namespace App\Extension\IdentityVerification\DTO;

use Carbon\CarbonInterface;

/**
 * 본인인증 검증 결과 DTO.
 *
 * {@see \App\Contracts\Extension\IdentityVerificationInterface::verify()} 의 반환값.
 */
final class VerificationResult
{
    /**
     * @param  bool  $success  검증 성공 여부
     * @param  string  $challengeId  challenge UUID
     * @param  string  $providerId  프로바이더 식별자
     * @param  CarbonInterface|null  $verifiedAt  검증 완료 시각 (success=true 일 때)
     * @param  string|null  $identityHash  프로바이더 교체 시 동일인 매칭용 정규화 식별자 (SHA256(name|birth|gender) 등)
     * @param  array  $claims  프로바이더가 반환한 PII 클레임 (각 플러그인이 자기 테이블에 저장)
     * @param  string|null  $failureCode  실패 코드 (예: INVALID_CODE|EXPIRED|MAX_ATTEMPTS)
     * @param  string|null  $failureReason  실패 이유 (i18n key 또는 raw 메시지)
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $challengeId,
        public readonly string $providerId,
        public readonly ?CarbonInterface $verifiedAt = null,
        public readonly ?string $identityHash = null,
        public readonly array $claims = [],
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * 검증 성공 결과 인스턴스를 생성합니다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  string  $providerId  프로바이더 식별자
     * @param  CarbonInterface  $verifiedAt  검증 완료 시각
     * @param  string|null  $identityHash  정규화 식별자
     * @param  array  $claims  프로바이더 클레임
     * @return self 성공 결과 DTO
     */
    public static function success(
        string $challengeId,
        string $providerId,
        CarbonInterface $verifiedAt,
        ?string $identityHash = null,
        array $claims = [],
    ): self {
        return new self(
            success: true,
            challengeId: $challengeId,
            providerId: $providerId,
            verifiedAt: $verifiedAt,
            identityHash: $identityHash,
            claims: $claims,
        );
    }

    /**
     * 실패 결과 생성.
     *
     * @param string $challengeId
     * @param string $providerId
     * @param string $failureCode
     * @param string|null $failureReason
     * @return self
     */
    public static function failure(
        string $challengeId,
        string $providerId,
        string $failureCode,
        ?string $failureReason = null,
    ): self {
        return new self(
            success: false,
            challengeId: $challengeId,
            providerId: $providerId,
            failureCode: $failureCode,
            failureReason: $failureReason,
        );
    }
}
