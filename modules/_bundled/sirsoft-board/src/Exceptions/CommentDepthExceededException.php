<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 대댓글 깊이 상한 초과 예외
 *
 * 게시판 설정 `max_comment_depth` 를 넘는 깊이로 댓글을 달려 할 때 발생합니다.
 * `CommentValidationRule` 이 요청 단계에서 선차단하지만, 훅이나 Service 직접 호출처럼
 * FormRequest 를 거치지 않는 경로가 있으므로 최종 불변조건은 Service 가 보장합니다.
 *
 * 클램프하지 않고 예외를 던집니다 — 요청한 위치와 다른 자리에 조용히 댓글이 붙으면
 * 사용자는 자기 답글이 엉뚱한 대화에 달린 것을 알 수 없습니다.
 */
class CommentDepthExceededException extends Exception
{
    /**
     * @param  int  $limit  게시판이 허용하는 최대 깊이
     * @param  int  $attempted  달려고 한 깊이
     */
    public function __construct(
        private int $limit,
        private int $attempted,
    ) {
        parent::__construct(__('sirsoft-board::validation.comment.depth.exceeded', [
            'max' => $limit,
        ]));
    }

    /**
     * 허용 최대 깊이를 반환합니다.
     *
     * @return int 최대 깊이
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * 시도한 깊이를 반환합니다.
     *
     * @return int 시도 깊이
     */
    public function getAttempted(): int
    {
        return $this->attempted;
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return 'sirsoft-board::validation.comment.depth.exceeded';
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['max' => $this->limit];
    }
}
