<?php

namespace Modules\Sirsoft\Board\Exceptions;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * 첨부파일 개수 상한 초과 예외
 *
 * 게시판 설정 `max_file_count` 를 초과해 첨부를 연결하려 할 때 발생합니다.
 * 직접 업로드·`attachment_ids` 연결·`temp_key` 연결 등 어떤 경로로 들어와도 동일하게
 * 이 예외로 차단되며, 컨트롤러가 422 로 매핑합니다.
 *
 * `render()` 는 컨트롤러 밖(요청 단계 선차단)에서 던져진 경우를 위한 것입니다 — 응답 형태를
 * 컨트롤러 catch 와 **동일하게** 유지해, 같은 조건에 두 가지 응답 형태가 생기지 않게 합니다.
 */
class AttachmentLimitExceededException extends Exception
{
    /**
     * @param  int  $limit  게시판이 허용하는 최대 첨부 개수
     * @param  int  $attempted  연결하려 한 총 첨부 개수
     */
    public function __construct(
        private int $limit,
        private int $attempted,
    ) {
        parent::__construct(__('sirsoft-board::messages.errors.attachment_limit_exceeded', [
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
        return 'sirsoft-board::messages.errors.attachment_limit_exceeded';
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

    /**
     * 컨트롤러 catch 와 동일한 422 응답으로 렌더링합니다.
     *
     * @return JsonResponse 상한 초과 응답
     */
    public function render(): JsonResponse
    {
        return ResponseHelper::error(
            $this->getMessageKey(),
            422,
            ['code' => 'attachment_limit_exceeded'],
            $this->getMessageParams()
        );
    }
}
