<?php

namespace Plugins\Sirsoft\VerificationKginicis\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 이니시스 callback 의 authRequestUrl 이 표준 도메인 (kssa.inicis.com / fcsa.inicis.com) 외인 경우 발생.
 *
 * 위조된 callback 으로부터 가맹점을 보호한다.
 *
 * @since 1.0.0-beta.1
 */
class InvalidAuthUrlException extends RuntimeException
{
    /**
     * 차단된 authRequestUrl 을 컨텍스트로 보존하여 운영 로그에 남긴다.
     *
     * @param  string  $url  차단된 authRequestUrl
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $url,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_kginicis::exceptions.invalid_auth_url'),
            0,
            $previous,
        );
    }
}
