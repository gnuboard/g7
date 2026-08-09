<?php

namespace Modules\Sirsoft\Board\Exceptions;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * 반응 불가 예외
 *
 * 반응 기능이 꺼진 게시판, 게시판이 켜지 않은(비활성) 유형, 본인 글 반응 시도 등
 * 반응이 허용되지 않는 상황에서 발생합니다 (이슈 #525 확정 07·08·11).
 *
 * `ReactPostRequest` + `AvailableReactionType` Rule 이 요청 단계에서 선차단하지만,
 * 훅이나 Service 직접 호출처럼 FormRequest 를 거치지 않는 경로가 있으므로 최종
 * 불변조건은 Service 가 보장합니다. 사용자가 고칠 수 있는 상태 문제이므로 422 로 매핑합니다.
 */
class ReactionNotAllowedException extends Exception
{
    /**
     * @param  string  $reason  거절 사유 (disabled | inactive_type | self_post | secret_denied)
     * @param  string  $message  사용자에게 보일 메시지
     */
    public function __construct(
        private string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * 반응 기능이 꺼진 게시판에 대한 예외를 생성합니다.
     *
     * @return self 반응 비활성화 사유 예외
     */
    public static function disabled(): self
    {
        return new self('disabled', __('sirsoft-board::messages.reaction.disabled'));
    }

    /**
     * 게시판이 켜지 않은(비활성) 유형에 대한 예외를 생성합니다.
     *
     * @return self 비활성 유형 사유 예외
     */
    public static function inactiveType(): self
    {
        return new self('inactive_type', __('sirsoft-board::messages.reaction.inactive_type'));
    }

    /**
     * 본인 글 반응 시도에 대한 예외를 생성합니다.
     *
     * @return self 본인 글 사유 예외
     */
    public static function selfPost(): self
    {
        return new self('self_post', __('sirsoft-board::messages.reaction.self_post'));
    }

    /**
     * 열람 권한이 없는 비밀글 반응 시도에 대한 예외를 생성합니다.
     *
     * @return self 비밀글 열람 권한 없음 사유 예외
     */
    public static function secretDenied(): self
    {
        return new self('secret_denied', __('sirsoft-board::messages.reaction.secret_denied'));
    }

    /**
     * 거절 사유를 반환합니다.
     *
     * @return string 거절 사유 (disabled | inactive_type | self_post)
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 컨트롤러 catch 와 동일한 422 응답으로 렌더링합니다.
     *
     * @return JsonResponse 반응 불가 응답
     */
    public function render(): JsonResponse
    {
        return ResponseHelper::error($this->getMessage(), 422, ['code' => 'reaction_not_allowed']);
    }
}
