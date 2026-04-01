<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 카테고리 사용 중 예외 클래스
 *
 * 사용 중인 카테고리 삭제 시도 시 발생하는 예외를 처리합니다.
 */
class CategoryInUseException extends Exception
{
    /**
     * 카테고리 사용 중 예외 생성자
     *
     * @param  string  $categoryName  삭제 시도한 카테고리명
     * @param  int  $postCount  사용 중인 게시글 수
     * @param  int  $code  예외 코드
     * @param  \Throwable|null  $previous  이전 예외
     */
    public function __construct(string $categoryName, int $postCount = 0, int $code = 0, ?\Throwable $previous = null)
    {
        $message = __('sirsoft-board::messages.errors.category_in_use', [
            'category' => $categoryName,
            'count' => $postCount,
        ]);

        parent::__construct($message, $code, $previous);
    }
}
