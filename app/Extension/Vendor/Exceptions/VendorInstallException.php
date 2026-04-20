<?php

namespace App\Extension\Vendor\Exceptions;

/**
 * Vendor 설치 실패 예외.
 *
 * 에러 키는 lang/{locale}/exceptions.php 의 vendor.* 하위에 정의되어 있음.
 * 예: exceptions.vendor.composer_not_available
 */
class VendorInstallException extends \RuntimeException
{
    /**
     * @param  string  $errorKey  'exceptions.vendor.*' 하위 키 (예: 'composer_not_available')
     * @param  array<string, mixed>  $context  다국어 파라미터 치환 컨텍스트
     * @param  \Throwable|null  $previous  이전 예외
     */
    public function __construct(
        public readonly string $errorKey,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        $message = __('exceptions.vendor.'.$errorKey, $context);
        if (is_array($message)) {
            $message = 'exceptions.vendor.'.$errorKey;
        }

        // 권한 거부 등 진단 힌트가 있으면 메시지에 덧붙여 사용자에게 즉시 노출
        if (isset($context['hint'])) {
            $message = (string) $message.' — '.$context['hint'];
        }

        parent::__construct((string) $message, 0, $previous);
    }

    public function getErrorKey(): string
    {
        return $this->errorKey;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
