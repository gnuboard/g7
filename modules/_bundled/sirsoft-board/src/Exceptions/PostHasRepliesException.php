<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 답글 보유 글 삭제 차단 예외
 *
 * 게시판 설정 `reply_delete_policy` 가 'block' 인 게시판에서, 살아 있는 답글이
 * 달린 글을 삭제하려 할 때 발생합니다. 판정은 before_delete 훅 발화 전에 수행되어
 * 차단 시 부수효과가 전혀 남지 않습니다.
 *
 * 훅 경유 삭제(이커머스 문의 연동 등 `cascade_replies` 옵션 전달)는 시스템 생성
 * 답글을 함께 정리해야 하므로 이 정책의 적용을 받지 않습니다.
 */
class PostHasRepliesException extends Exception
{
    public function __construct()
    {
        parent::__construct(__('sirsoft-board::validation.post.delete.has_replies'));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return 'sirsoft-board::validation.post.delete.has_replies';
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return [];
    }
}
