<?php

namespace Modules\Sirsoft\Board\Exceptions;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Enums\ReportStatus;

/**
 * 신고 취소 불가 예외
 *
 * 신고는 **접수(pending)** 상태에서만 취소할 수 있다 (PO 결정 R10). 운영자가 이미 검토에
 * 착수했거나 처리를 끝낸 신고를 신고자가 되돌리면 처리 이력과 조치(자동 숨김 등)의
 * 근거가 사라지기 때문이다.
 */
class ReportCancelNotAllowedException extends Exception
{
    /**
     * @param  ReportStatus  $status  현재 신고 상태
     */
    public function __construct(
        private ReportStatus $status,
    ) {
        parent::__construct(__('sirsoft-board::messages.report.cancel_not_allowed', [
            'status' => $status->label(),
        ]));
    }

    /**
     * 현재 신고 상태를 반환합니다.
     *
     * @return ReportStatus 신고 상태
     */
    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return 'sirsoft-board::messages.report.cancel_not_allowed';
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['status' => $this->status->label()];
    }

    /**
     * 컨트롤러 catch 와 동일한 403 응답으로 렌더링합니다.
     *
     * @return JsonResponse 취소 불가 응답
     */
    public function render(): JsonResponse
    {
        return ResponseHelper::error(
            $this->getMessageKey(),
            403,
            ['code' => 'report_cancel_not_allowed'],
            $this->getMessageParams()
        );
    }
}
