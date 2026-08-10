<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * KCP 거래등록 요청 데이터의 AES-256-CBC 암호화 실패 시 발생.
 *
 * openssl 확장 이상 또는 파생 키/IV 생성 실패가 원인이다. 암호화 단계에서 실패하면
 * 거래등록 자체를 시도하지 않으므로 challenge 는 발행되지 않는다.
 *
 * @since 1.0.0
 */
class EncryptException extends RuntimeException
{
    /**
     * 암호화 실패 상세를 보존한다 (평문/키는 절대 보존하지 않는다).
     *
     * @param  string  $detail  openssl 오류 문자열 등 실패 상세
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $detail,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_nhnkcp::exceptions.encrypt_failed'),
            0,
            $previous,
        );
    }
}
