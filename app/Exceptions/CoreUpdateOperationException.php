<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 코어 업데이트 흐름에서 발생하는 운영 오류 예외.
 *
 * 다국어 키 + 파라미터를 보존하여 컨트롤러/리스너가 원본 키를 활용 가능. 코어
 * 업데이트의 다운로드/검증/추출/composer 실행/소유권 복원 등 단계에서 회복 불가
 * 한 실패가 발생할 때 사용한다.
 */
class CoreUpdateOperationException extends RuntimeException
{
    /**
     * @param  string  $errorKey  다국어 키 (예: 'settings.core_update.zip_file_not_found')
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
