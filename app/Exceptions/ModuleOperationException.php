<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 모듈 설치/활성화/업데이트 흐름에서 발생하는 운영 오류 예외.
 *
 * 다국어 키 + 파라미터를 보존하여 컨트롤러/리스너가 원본 키를 활용 가능합니다.
 */
class ModuleOperationException extends RuntimeException
{
    /**
     * @param  string  $errorKey  다국어 키 (예: 'modules.errors.install_failed')
     * @param  array<string, mixed>  $params  메시지 파라미터
     * @param  Throwable|null  $previous  원인 예외
     */
    public function __construct(
        public readonly string $errorKey,
        public readonly array $params = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(__($errorKey, $params), 0, $previous);
    }
}
