<?php

namespace Plugins\Sirsoft\VerificationKginicis\Services;

/**
 * 이니시스 callback 처리 결과 DTO (Value Object).
 *
 * `InicisCallbackResolver::resolve()` 가 반환하며, Controller 가 redirect 분기에 사용한다.
 *
 * @since 1.0.0-beta.1
 */
final readonly class InicisCallbackOutcome
{
    /**
     * @param  bool  $success  처리 성공 여부
     * @param  string|null  $challengeId  매칭된 challenge UUID (mtxid 매칭 실패 시 null)
     * @param  string|null  $verificationToken  verify 성공 시 발급된 1회용 토큰
     * @param  string|null  $failureCode  실패 코드 (PROVIDER_ERROR / INVALID_AUTH_URL / REMOTE_CALL_FAILED / DECRYPT_FAILED / NOT_FOUND / ALREADY_CONSUMED / 코어 failureCode)
     */
    public function __construct(
        public bool $success,
        public ?string $challengeId = null,
        public ?string $verificationToken = null,
        public ?string $failureCode = null,
    ) {}

    /**
     * 성공 결과 인스턴스를 생성한다.
     *
     * @param  string  $challengeId  매칭된 challenge UUID
     * @param  string  $verificationToken  verify 성공 시 발급된 1회용 토큰
     * @return self
     */
    public static function success(string $challengeId, string $verificationToken): self
    {
        return new self(
            success: true,
            challengeId: $challengeId,
            verificationToken: $verificationToken,
        );
    }

    /**
     * 실패 결과 인스턴스를 생성한다.
     *
     * @param  string|null  $challengeId  매칭된 challenge UUID (있으면)
     * @param  string  $failureCode  실패 코드
     * @return self
     */
    public static function failure(?string $challengeId = null, string $failureCode = 'UNKNOWN'): self
    {
        return new self(
            success: false,
            challengeId: $challengeId,
            failureCode: $failureCode,
        );
    }

    /**
     * bridge 페이지로 전달할 query params 를 반환한다.
     *
     * @return array<string, string>
     */
    public function toBridgeQuery(): array
    {
        if ($this->success) {
            return [
                'verification_token' => (string) $this->verificationToken,
                'challenge_id' => (string) $this->challengeId,
            ];
        }

        return ['identity_error' => (string) $this->failureCode];
    }
}
