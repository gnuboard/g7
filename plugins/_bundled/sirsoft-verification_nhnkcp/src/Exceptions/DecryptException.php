<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * KCP 결과조회 응답의 AES-256-CBC 복호화 실패 시 발생.
 *
 * 잘못된 암호화 키(enc_key), 사이트코드 불일치(IV 파생 실패), 응답 데이터 손상이 원인일 수 있다.
 *
 * @since 1.0.0
 */
class DecryptException extends RuntimeException
{
    /**
     * 복호화 실패한 필드명을 컨텍스트로 보존한다 (PII 평문은 절대 보존하지 않는다).
     *
     * @param  string  $field  복호화 실패한 필드명 (예: enc_cert_data)
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $field,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_nhnkcp::exceptions.decrypt_failed'),
            0,
            $previous,
        );
    }
}
