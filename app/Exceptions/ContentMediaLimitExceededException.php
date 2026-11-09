<?php

namespace App\Exceptions;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * 본문 미디어 문서당 개수 초과 예외 — 422 (#883 4단계, D8)
 *
 * 본문이 참조하는 본문 미디어 수가 정책의 문서당 최대 개수를 넘으면 저장을 막는다. 첨부 개수 상한과 따로 센다.
 * `render()` 는 컨트롤러가 잡지 않은 경우에도 같은 형태(`errors.code = content_media_limit_exceeded`)로 응답하게 한다.
 *
 * @since 7.1.5
 */
class ContentMediaLimitExceededException extends Exception
{
    /**
     * @param  int  $limit  문서당 최대 개수
     * @param  int  $attempted  본문이 참조한 개수
     */
    public function __construct(
        private readonly int $limit,
        private readonly int $attempted,
    ) {
        parent::__construct(__('attachment.content_media.errors.limit_exceeded', ['limit' => $limit, 'attempted' => $attempted]));
    }

    /**
     * 문서당 최대 개수.
     *
     * @return int 개수
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * 본문이 참조한 개수.
     *
     * @return int 개수
     */
    public function getAttempted(): int
    {
        return $this->attempted;
    }

    /**
     * 다국어 메시지 키.
     *
     * @return string 키
     */
    public function getMessageKey(): string
    {
        return 'attachment.content_media.errors.limit_exceeded';
    }

    /**
     * 메시지 치환 파라미터.
     *
     * @return array<string, int> 파라미터
     */
    public function getMessageParams(): array
    {
        return ['limit' => $this->limit, 'attempted' => $this->attempted];
    }

    /**
     * 422 응답으로 렌더링합니다.
     *
     * @return JsonResponse 응답
     */
    public function render(): JsonResponse
    {
        return ResponseHelper::error(
            $this->getMessageKey(),
            422,
            ['code' => 'content_media_limit_exceeded', 'content' => [$this->getMessage()]],
            $this->getMessageParams()
        );
    }
}
