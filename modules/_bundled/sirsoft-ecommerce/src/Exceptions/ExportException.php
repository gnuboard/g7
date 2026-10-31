<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 내보내기 도메인 실패 예외
 *
 * 실패 사유 키(`error_code`)와 응답 상태를 함께 든다. 사유 키는 작업 행의 `error_code` 와
 * 다국어 메시지(`sirsoft-ecommerce::exceptions.export.{키}`)를 함께 가리킨다 — 예외 원문을 화면에 내지 않는다.
 */
class ExportException extends RuntimeException
{
    /**
     * @param  string  $errorCode  실패 사유 키 (예: permission_revoked, file_expired)
     * @param  int  $status  HTTP 응답 상태 (기본 422)
     */
    public function __construct(
        private readonly string $errorCode,
        private readonly int $status = 422,
    ) {
        parent::__construct($errorCode);
    }

    /**
     * 실패 사유 키를 반환합니다.
     *
     * @return string 사유 키
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * 응답 상태를 반환합니다.
     *
     * @return int HTTP 상태
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * 화면에 보일 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 키 (sirsoft-ecommerce::exceptions.export.*)
     */
    public function messageKey(): string
    {
        return 'sirsoft-ecommerce::exceptions.export.'.$this->errorCode;
    }
}
