<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 게시판을 찾을 수 없을 때 발생하는 예외
 */
class BoardNotFoundException extends NotFoundHttpException
{
    private string $slug;

    /**
     * BoardNotFoundException 생성자
     *
     * @param string $slug 게시판 슬러그
     */
    public function __construct(string $slug)
    {
        $this->slug = $slug;

        // 다국어 메시지 템플릿 + slug 직접 포함
        $messageTemplate = __('sirsoft-board::messages.boards.error_404');
        $message = str_replace(':slug', $slug, $messageTemplate);

        parent::__construct($message);
    }

    /**
     * 게시판 슬러그 반환
     *
     * @return string 게시판 슬러그
     */
    public function getSlug(): string
    {
        return $this->slug;
    }
}