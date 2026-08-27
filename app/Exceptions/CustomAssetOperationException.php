<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 사용자 추가 에셋(`custom/`) 관리 흐름에서 발생하는 운영 오류 예외.
 *
 * 다국어 키 + 파라미터를 보존해 컨트롤러가 원본 키로 응답할 수 있게 한다. 이미 번역된
 * 문장을 응답의 메시지 **키** 자리에 넘기면 키 해석에 실패해 원문이 그대로 화면에 나간다.
 *
 * `TemplateOperationException` 과 같은 형태지만 별도 클래스로 둔다 — 컨트롤러가 이
 * 예외만 골라 4xx 로 바꾸기 위해서다. 부모(`RuntimeException`)를 잡으면 인프라 예외까지
 * 입력 오류로 위장된다.
 */
class CustomAssetOperationException extends RuntimeException
{
    /**
     * @param  string  $errorKey  다국어 키 (예: 'custom_assets.errors.not_found')
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
