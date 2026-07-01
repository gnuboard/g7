<?php

namespace Plugins\Sirsoft\VerificationKginicis\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 이니시스 STEP3 응답의 SEED CBC 복호화 실패 시 발생.
 *
 * 잘못된 SEED 키 (token), IV 불일치, 또는 응답 데이터 손상이 원인일 수 있다.
 *
 * @since 1.0.0-beta.1
 */
class DecryptException extends RuntimeException
{
    /**
     * 복호화 실패한 필드명을 컨텍스트로 보존한다 (PII 평문은 절대 보존하지 않는다).
     *
     * @param  string  $field  복호화 실패한 필드명 (예: userName, userPhone)
     * @param  Throwable|null  $previous  KISA libs 가 던진 원본 예외 (있을 시)
     */
    public function __construct(
        public readonly string $field,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_kginicis::exceptions.decrypt_failed'),
            0,
            $previous,
        );
    }
}
