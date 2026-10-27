<?php

namespace Plugins\Sirsoft\Sso\Exceptions;

use RuntimeException;
use Throwable;

/**
 * SSO 흐름 실패 — 오류 코드로 화면 문구(`sirsoft-sso::messages.error.{code}`)와 시도 기록 사유를 고른다.
 *
 * 메시지(`getMessage()`)는 운영자 진단용 요약이다. 비밀값, 토큰, 어설션 원문을 넣지 않는다.
 *
 * @since 1.0.0
 */
class SsoException extends RuntimeException
{
    /**
     * @param  string  $errorCode  오류 코드 (부록 C-3 목록)
     * @param  string  $detail  운영자 진단용 요약
     * @param  int  $status  API 응답 상태 코드
     * @param  Throwable|null  $previous  원인 예외
     * @param  array{signed: bool, not_on_or_after: string|null, audiences: array<int, string>}|null  $responseSummary  실패한 IdP 응답 요약 — 진단 화면용, 원문 없음(§7 L-3)
     */
    public function __construct(
        public readonly string $errorCode,
        string $detail = '',
        public readonly int $status = 422,
        ?Throwable $previous = null,
        public readonly ?array $responseSummary = null,
    ) {
        parent::__construct($detail !== '' ? $detail : $errorCode, 0, $previous);
    }
}
