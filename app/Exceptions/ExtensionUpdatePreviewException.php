<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 확장 업데이트 미리보기를 만들 수 없을 때 — 알 수 없는 확장 종류, 설치되지 않은 확장.
 *
 * 응답 자리가 사유(`getReason()`)로 상태 코드를 가르고 메시지 키(`getMessageKey()`)를 응답에 싣도록 키와 치환
 * 파라미터를 들고 다닌다.
 */
class ExtensionUpdatePreviewException extends RuntimeException
{
    /** 사유: 알 수 없는 확장 종류 */
    public const REASON_UNKNOWN_TYPE = 'unknown_type';

    /** 사유: 설치되지 않은 확장 */
    public const REASON_NOT_INSTALLED = 'not_installed';

    /**
     * @param  string  $reason  사유 (`REASON_*`)
     * @param  string  $messageKey  다국어 메시지 키
     * @param  array<string, scalar>  $messageParams  메시지 치환 파라미터
     */
    public function __construct(
        private readonly string $reason,
        private readonly string $messageKey,
        private readonly array $messageParams = [],
    ) {
        parent::__construct(__($messageKey, $messageParams));
    }

    /**
     * 알 수 없는 확장 종류 예외를 만듭니다.
     *
     * @param  string  $type  요청된 확장 종류
     * @return self 예외
     */
    public static function unknownType(string $type): self
    {
        return new self(self::REASON_UNKNOWN_TYPE, 'extensions.errors.update_preview_unknown_type', ['type' => $type]);
    }

    /**
     * 설치되지 않은 확장 예외를 만듭니다.
     *
     * @param  string  $identifier  확장 식별자
     * @return self 예외
     */
    public static function notInstalled(string $identifier): self
    {
        return new self(self::REASON_NOT_INSTALLED, 'extensions.errors.update_preview_not_installed', ['identifier' => $identifier]);
    }

    /**
     * 사유를 반환합니다.
     *
     * @return string `REASON_*` 중 하나
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, scalar> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->messageParams;
    }

    /**
     * 사유에 맞는 HTTP 상태 코드를 반환합니다 — 설치되지 않은 확장은 404, 알 수 없는 종류는 422.
     *
     * @return int HTTP 상태 코드
     */
    public function getStatusCode(): int
    {
        return $this->reason === self::REASON_NOT_INSTALLED ? 404 : 422;
    }
}
