<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 댓글 작성 불가 게시글 상태 예외
 *
 * 블라인드되었거나 삭제된 게시글에 댓글을 달려 할 때 발생합니다.
 * `CommentValidationRule` 이 요청 단계에서 선차단하지만, 훅이나 Service 직접 호출처럼
 * FormRequest 를 거치지 않는 경로가 있으므로 최종 불변조건은 Service 가 보장합니다.
 *
 * 베이스 `\Exception` 을 던지면 컨트롤러의 제네릭 catch 에 흡수되어 500 + 일반 문구가 되고
 * 거절 사유가 사라집니다. 사용자가 고칠 수 있는 상태 문제이므로 422 로 매핑합니다.
 */
class PostNotCommentableException extends Exception
{
    /**
     * @param  string  $reason  거절 사유 (blinded | deleted)
     * @param  string  $message  사용자에게 보일 메시지
     */
    public function __construct(
        private string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * 블라인드된 게시글에 대한 예외를 생성합니다.
     *
     * @return self 블라인드 사유 예외
     */
    public static function blinded(): self
    {
        return new self('blinded', __('sirsoft-board::messages.comment.post_blinded'));
    }

    /**
     * 삭제된 게시글에 대한 예외를 생성합니다.
     *
     * @return self 삭제 사유 예외
     */
    public static function deleted(): self
    {
        return new self('deleted', __('sirsoft-board::messages.comment.post_deleted'));
    }

    /**
     * 거절 사유를 반환합니다.
     *
     * @return string 거절 사유 (blinded | deleted)
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
