<?php

namespace Plugins\Sirsoft\VerificationKginicis\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 동일 mTxId 로 이미 verify 처리된 challenge 에 대해 callback 이 재진입했을 때 발생.
 *
 * Replay 공격 또는 사용자가 같은 인증 결과를 두 번 제출하는 경우를 차단한다.
 *
 * @since 1.0.0-beta.1
 */
class AlreadyConsumedException extends RuntimeException
{
    /**
     * 이미 소비된 challenge 의 식별자를 컨텍스트로 보존한다.
     *
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @param  string  $mtxid  이니시스 가맹점 거래 ID
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $challengeId,
        public readonly string $mtxid,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_kginicis::exceptions.already_consumed'),
            0,
            $previous,
        );
    }
}
