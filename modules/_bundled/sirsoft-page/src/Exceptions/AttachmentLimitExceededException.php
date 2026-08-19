<?php

namespace Modules\Sirsoft\Page\Exceptions;

use Exception;

/**
 * 첨부파일 개수 상한 초과 예외
 *
 * 모듈 환경설정 `attachment.max_count` 를 초과해 첨부를 연결하려 할 때 발생합니다.
 * 직접 업로드·`temp_key` 연결 어떤 경로로 들어와도 동일하게 이 예외로 차단되며,
 * 컨트롤러가 422 로 매핑합니다.
 */
class AttachmentLimitExceededException extends Exception
{
    /**
     * @param  int  $limit  허용 최대 첨부 개수
     * @param  int  $attempted  연결하려 한 총 첨부 개수
     */
    public function __construct(
        private int $limit,
        private int $attempted,
    ) {
        parent::__construct(__('sirsoft-page::messages.errors.attachment_limit_exceeded', [
            'limit' => $limit,
            'attempted' => $attempted,
        ]));
    }

    /**
     * 허용 최대 개수를 반환합니다.
     *
     * @return int 최대 첨부 개수
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * 시도한 총 개수를 반환합니다.
     *
     * @return int 연결 시도 개수
     */
    public function getAttempted(): int
    {
        return $this->attempted;
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 응답을 만들 때 이미 번역된 `getMessage()` 대신 이 키를 넘긴다 — 번역문을 키로
     * 해석하려 하면 실패해 원문이 그대로 노출된다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return 'sirsoft-page::messages.errors.attachment_limit_exceeded';
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['limit' => $this->limit, 'attempted' => $this->attempted];
    }
}
