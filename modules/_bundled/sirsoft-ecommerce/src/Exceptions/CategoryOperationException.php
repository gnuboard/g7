<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 카테고리 조작 실패 예외
 *
 * 카테고리를 찾을 수 없거나, 하위 카테고리·연결 상품이 있어 삭제할 수 없는 경우에 사용합니다.
 * 컨트롤러의 기존 catch (\Exception) 흐름에서 400 응답으로 처리됩니다.
 */
class CategoryOperationException extends RuntimeException
{
    /**
     * CategoryOperationException 생성자
     *
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-ecommerce::exceptions.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     */
    public function __construct(string $messageKey, array $replace = [])
    {
        parent::__construct(__($messageKey, $replace));
    }
}
