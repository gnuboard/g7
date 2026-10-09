<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 원글이 삭제된 댓글의 단건 복구 거절 예외
 *
 * 원글을 지우면 그 글의 댓글도 함께(연쇄) 지워진다. 원글이 지워진 채로 댓글만 복구하면
 * 보이지 않는 글 아래 댓글만 게시 상태로 살아나는 고아 댓글이 생겼다(2026-10-07 실측).
 * 원글을 복구하면 함께 지워진 댓글도 돌아오므로, 댓글 단건 복구는 원글 복구를 먼저 안내한다.
 *
 * 컨트롤러는 409(현재 상태와 충돌)로 돌려준다 — 원글을 복구한 뒤 같은 요청이 통과한다.
 */
class CommentParentDeletedException extends Exception
{
    /**
     * 사용자에게 보일 문구의 다국어 키
     */
    private const MESSAGE_KEY = 'sirsoft-board::messages.comment.restore_parent_deleted';

    /**
     * 예외를 만듭니다.
     */
    public function __construct()
    {
        parent::__construct(__(self::MESSAGE_KEY));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}
