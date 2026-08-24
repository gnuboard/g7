<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 비즈뿌리오 API 호출 실패 예외.
 *
 * 발송(api.bizppurio.com)·카카오 관리(kapi.ppurio.com) 두 시스템의 API 호출
 * 단계에서 발생하는 실패(HTTP 오류, 응답 code 비정상, 응답 파싱 실패 등)를 단일
 * 도메인 예외로 통합한다. 베이스 \Exception 직접 throw 대신 본 클래스를 사용해
 * 외부 소비자가 비즈뿌리오 도메인 오류만 선택적으로 catch 할 수 있도록 한다.
 *
 * 비즈뿌리오 응답 결과코드(예: 3002 토큰무효, 3006 계정오류)를 함께 보존하여
 * SendMessageJob 의 재시도/영구실패 판정(ResultCodeResolver, Phase 4)이 코드로
 * 분기할 수 있게 한다. HTTP 전송 자체가 실패한 경우 결과코드는 null 이다.
 */
class BizppurioApiException extends RuntimeException
{
    /**
     * @param  string  $message  예외 메시지
     * @param  string|null  $resultCode  비즈뿌리오 응답 결과코드(있으면). 전송 실패 시 null
     * @param  int|null  $httpStatus  HTTP 상태 코드(있으면)
     * @param  int  $code  예외 코드(기본 0)
     * @param  Throwable|null  $previous  원인 예외
     */
    public function __construct(
        string $message = '',
        private readonly ?string $resultCode = null,
        private readonly ?int $httpStatus = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 비즈뿌리오 응답 결과코드를 반환합니다.
     *
     * @return string|null 결과코드(예: "3002"), 전송 실패 시 null
     */
    public function getResultCode(): ?string
    {
        return $this->resultCode;
    }

    /**
     * HTTP 상태 코드를 반환합니다.
     *
     * @return int|null HTTP 상태 코드, 없으면 null
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
