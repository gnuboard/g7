<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 게시글을 찾을 수 없을 때 발생하는 예외
 */
class PostNotFoundException extends NotFoundHttpException
{
    private int $id;

    /**
     * PostNotFoundException 생성자
     *
     * @param int $id 게시글 ID
     */
    public function __construct(int $id)
    {
        $this->id = $id;

        // 다국어 메시지 (ID 파라미터 제거)
        $message = __('sirsoft-board::messages.posts.error_404');

        parent::__construct($message);
    }

    /**
     * 게시글 ID 반환
     *
     * @return int 게시글 ID
     */
    public function getId(): int
    {
        return $this->id;
    }
}