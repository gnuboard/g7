<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 이미 verify 처리된 거래에 대해 콜백이 재진입했을 때 발생.
 *
 * Replay 공격 또는 사용자가 같은 인증 결과를 두 번 제출하는 경우를 차단한다.
 *
 * @since 1.0.0
 */
class AlreadyConsumedException extends RuntimeException
{
    /**
     * 이미 소비된 challenge 의 식별자를 컨텍스트로 보존한다 (거래키 평문은 보존하지 않는다).
     *
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $challengeId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_nhnkcp::exceptions.already_consumed'),
            0,
            $previous,
        );
    }
}
