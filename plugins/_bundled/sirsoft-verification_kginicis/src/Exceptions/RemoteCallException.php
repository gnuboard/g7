<?php

namespace Plugins\Sirsoft\VerificationKginicis\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 이니시스 STEP3 (`authRequestUrl` 서버-서버 호출) 통신 오류 시 발생.
 *
 * curl timeout, SSL 검증 실패, HTTP 5xx 등 네트워크/원격 응답 이상이 원인.
 *
 * @since 1.0.0-beta.1
 */
class RemoteCallException extends RuntimeException
{
    /**
     * 호출 실패 시점의 컨텍스트 (HTTP status / curl errno) 를 보존한다.
     *
     * @param  string  $detail  호출 실패 상세 (curl_error 또는 HTTP status 텍스트)
     * @param  int  $httpStatus  HTTP status code (0 = 통신 자체 실패)
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $detail,
        public readonly int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_kginicis::exceptions.remote_call_failed'),
            $httpStatus,
            $previous,
        );
    }
}
