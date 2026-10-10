<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 삭제된 글·댓글 블라인드 거절 예외
 *
 * 블라인드는 「게시 중인 것을 가리는」 처리다. 삭제된 글·댓글에 블라인드를 걸면
 * 저장소의 상태 변경(`updateStatus`)이 deleted_at 을 지워 삭제가 풀리고 blinded 로
 * 되살아났다(2026-10-07 브라우저 실측). 삭제를 되돌리는 길은 명시적인 복구 하나로 두고,
 * 블라인드는 여기서 거절한다.
 *
 * 컨트롤러는 409(현재 상태와 충돌)로 돌려준다 — 요청 형식은 맞지만 대상의 지금 상태에서는
 * 할 수 없는 처리이고, 먼저 복구하면 같은 요청이 통과한다. 일괄 처리는 이 예외 대신
 * 건너뜀 사유 `already_deleted` 로 알린다.
 */
class DeletedContentBlindException extends Exception
{
    /**
     * @param  string  $messageKey  사용자에게 보일 문구의 다국어 키
     */
    public function __construct(private string $messageKey)
    {
        parent::__construct(__($messageKey));
    }

    /**
     * 삭제된 게시글에 대한 예외를 만듭니다.
     *
     * @return self 게시글 거절 예외
     */
    public static function post(): self
    {
        return new self('sirsoft-board::messages.posts.blind_deleted_not_allowed');
    }

    /**
     * 삭제된 댓글에 대한 예외를 만듭니다.
     *
     * @return self 댓글 거절 예외
     */
    public static function comment(): self
    {
        return new self('sirsoft-board::messages.comment.blind_deleted_not_allowed');
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }
}
