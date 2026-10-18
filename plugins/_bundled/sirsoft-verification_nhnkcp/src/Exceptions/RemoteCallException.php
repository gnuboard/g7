<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * KCP REST API (거래등록 / 결과조회) 통신 오류 시 발생.
 *
 * 연결 타임아웃, SSL 검증 실패, HTTP 5xx, JSON 파싱 실패 등 네트워크/원격 응답 이상이 원인.
 *
 * @since 1.0.0
 */
class RemoteCallException extends RuntimeException
{
    /**
     * 호출 실패 시점의 컨텍스트를 보존한다 (PII 는 절대 보존하지 않는다).
     *
     * @param  string  $detail  호출 실패 상세 (통신 오류 메시지 또는 HTTP status 텍스트)
     * @param  int  $httpStatus  HTTP status code (0 = 통신 자체 실패)
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $detail,
        public readonly int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_nhnkcp::exceptions.remote_call_failed'),
            $httpStatus,
            $previous,
        );
    }
}
