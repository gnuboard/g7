<?php

namespace App\Exceptions;

use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\PackageVerificationStatus;

/**
 * 패키지 무결성 검증이 설치·업데이트를 멈췄을 때의 예외.
 *
 * - 409 `package_unsigned`: 미서명·검증 불가 — `canContinue` 가 true 면 화면이 「미서명으로 계속」을 보여 같은 요청을
 *   `allow_unsigned=1` 로 다시 보낸다. 환경설정 「미서명 패키지 차단」이 켜져 있으면 false(계속 불가).
 * - 422 `package_signature_invalid`·`package_signer_untrusted`: 서명 불일치·미신뢰 키 — 늘 차단.
 *
 * 응답 `errors` 에는 표지(`package_unsigned`·`package_signature_invalid`·`package_signer_untrusted` 중 하나 = true)와
 * `integrity` 페이로드를 싣는다 — 화면이 같은 409 의 다른 계약(작업 잠금·의존 경고)과 이 거절을 가른다.
 *
 * @since 7.2.0
 */
class PackageIntegrityException extends \RuntimeException
{
    /** 미서명 표지·코드 */
    public const CODE_UNSIGNED = 'package_unsigned';

    /** 서명 불일치 표지·코드 */
    public const CODE_INVALID = 'package_signature_invalid';

    /** 미신뢰 키 표지·코드 */
    public const CODE_UNTRUSTED = 'package_signer_untrusted';

    /**
     * 예외를 만듭니다.
     *
     * @param  PackageVerification  $verification  검증 결과
     * @param  string  $integrityCode  표지 코드 (CODE_* 중 하나)
     * @param  bool  $canContinue  운영자 확인으로 계속할 수 있는지
     * @param  bool  $blockedByPolicy  「미서명 패키지 차단」 스위치로 막혔는지
     */
    public function __construct(
        public readonly PackageVerification $verification,
        public readonly string $integrityCode,
        public readonly bool $canContinue = false,
        public readonly bool $blockedByPolicy = false,
    ) {
        parent::__construct((string) __($this->getMessageKey(), $this->getMessageParams()));
    }

    /**
     * 응답 상태 코드를 돌려줍니다.
     *
     * @return int 409(미서명) 또는 422(불일치·미신뢰)
     */
    public function getStatusCode(): int
    {
        return $this->integrityCode === self::CODE_UNSIGNED ? 409 : 422;
    }

    /**
     * 응답 문장 키를 돌려줍니다.
     *
     * @return string 다국어 키
     */
    public function getMessageKey(): string
    {
        if ($this->integrityCode === self::CODE_UNSIGNED) {
            return $this->blockedByPolicy
                ? 'extension_integrity.errors.package_unsigned_blocked'
                : ($this->verification->status === PackageVerificationStatus::Unverifiable
                    ? 'extension_integrity.errors.package_unverifiable'
                    : 'extension_integrity.errors.package_unsigned');
        }

        return 'extension_integrity.errors.'.$this->integrityCode;
    }

    /**
     * 응답 문장 치환 값을 돌려줍니다.
     *
     * @return array<string, string> 치환 값
     */
    public function getMessageParams(): array
    {
        return ['reason' => (string) ($this->verification->reasonMessage() ?? '')];
    }

    /**
     * 응답 `errors` 칸 내용을 돌려줍니다.
     *
     * @return array<string, mixed> 표지 + integrity 페이로드
     */
    public function getErrors(): array
    {
        return [
            $this->integrityCode => true,
            'integrity' => $this->getPayload(),
        ];
    }

    /**
     * 화면·명령이 쓰는 검증 페이로드를 돌려줍니다.
     *
     * @return array<string, mixed> 페이로드
     */
    public function getPayload(): array
    {
        return $this->verification->toArray() + [
            'code' => $this->integrityCode,
            'can_continue' => $this->canContinue,
            'blocked_by_policy' => $this->blockedByPolicy,
        ];
    }
}
