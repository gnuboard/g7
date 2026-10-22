<?php

namespace Plugins\Sirsoft\SocialLogin\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 제공자 왕복 중 생긴 오류.
 *
 * `errorCode` 는 진단 화면과 시도 기록에 남기는 짧은 코드(예 `token_exchange_failed`, `KOE320`),
 * 메시지는 비밀값을 담지 않는 요약이다. 사용자에게는 코드별 다국어 문구를 따로 보여 준다.
 *
 * @since 1.0.0
 */
class ProviderException extends RuntimeException
{
    /**
     * @param  string  $errorCode  오류 코드
     * @param  string  $message  오류 요약 (비밀 제외)
     * @param  int|null  $httpStatus  제공자 응답 HTTP 상태
     * @param  Throwable|null  $previous  원인 예외
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }
}
