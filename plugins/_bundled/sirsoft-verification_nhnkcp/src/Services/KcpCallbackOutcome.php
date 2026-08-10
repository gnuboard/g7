<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Services;

/**
 * KCP 콜백 처리 결과 DTO (Value Object).
 *
 * `KcpCallbackResolver::resolve()` 가 반환하며, Controller 가 브리지 redirect 분기에 사용한다.
 *
 * @since 1.0.0
 */
final readonly class KcpCallbackOutcome
{
    /**
     * @param  bool  $success  처리 성공 여부
     * @param  string|null  $challengeId  매칭된 challenge UUID (거래 매칭 실패 시 null)
     * @param  string|null  $verificationToken  verify 성공 시 발급된 1회용 토큰
     * @param  string|null  $failureCode  실패 코드 (NOT_FOUND / ALREADY_CONSUMED / REMOTE_CALL_FAILED /
     *                                    DECRYPT_FAILED / NOT_ADULT / DUPLICATE / 9999 / KCP res_cd 등)
     * @param  string|null  $failureMessage  KCP 가 돌려준 원문 메시지 (우리말 안내가 없는 코드에 한해 그대로 노출)
     */
    public function __construct(
        public bool $success,
        public ?string $challengeId = null,
        public ?string $verificationToken = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    /**
     * 성공 결과 인스턴스를 생성한다.
     *
     * @param  string  $challengeId  매칭된 challenge UUID
     * @param  string  $verificationToken  발급된 1회용 토큰
     * @return self 성공 outcome
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
     * @param  string|null  $failureMessage  KCP 원문 메시지 (있을 때만)
     * @return self 실패 outcome
     */
    public static function failure(
        ?string $challengeId = null,
        string $failureCode = 'UNKNOWN',
        ?string $failureMessage = null,
    ): self {
        return new self(
            success: false,
            challengeId: $challengeId,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
        );
    }

    /**
     * 브리지 페이지로 전달할 query params 를 반환한다 (PII 는 절대 싣지 않는다).
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

        $query = ['identity_error' => (string) $this->failureCode];

        // 실패도 "이 거래는 끝났다" 는 사실을 화면에 알려야 한다. 콜백이 한 번 처리된 거래는 KCP
        // 쪽에서 종료되어 재사용할 수 없으므로, 화면은 이 신호를 받아 재시도 시 새 거래를 발급받는다.
        // 빠지면 죽은 거래키로 인증창을 다시 열어 "이미 종료된 거래" 로 실패하고, 사용자는 몇 번을
        // 눌러도 진행하지 못한다. (거래를 못 찾은 NOT_FOUND 는 알려줄 challenge 자체가 없다.)
        if ($this->challengeId !== null && $this->challengeId !== '') {
            $query['challenge_id'] = $this->challengeId;
        }

        // 우리말 안내가 없는 코드는 KCP 원문 메시지를 함께 전달해 사용자가 사유를 알 수 있게 한다.
        // 개인정보가 아닌 처리 상태 문구만 담긴다 (§3.7 "그 외 res_cd 는 [코드] 메시지 그대로").
        if ($this->failureMessage !== null && $this->failureMessage !== '') {
            $query['identity_error_message'] = $this->failureMessage;
        }

        return $query;
    }
}
