<?php

namespace App\Http\Resources;

use App\Models\PrivacyManualRun;
use App\Privacy\ManualRun\PrivacyManualRunService;
use Illuminate\Http\Request;

/**
 * 개인정보 조치 직권 실행 기록 리소스
 *
 * 설정 화면 카드의 상태 줄(「처리 대기/처리 중」 · 「마지막 직권 실행: 일시 · 실행자 · N건」)이
 * 그대로 그린다. 시각은 보는 관리자의 시간대 `Y-m-d H:i:s` 다. 접수자가 삭제된 회원이면
 * `requester_name` 이 null 이다.
 *
 * @mixin PrivacyManualRun
 */
class PrivacyManualRunResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열
     */
    public function toArray(Request $request): array
    {
        /** @var PrivacyManualRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'target' => $run->target,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'is_active' => $run->status->isActive(),
            'skip_waiting' => (bool) $run->skip_waiting,
            'processed_count' => $run->processed_count,
            'remaining_count' => $run->remaining_count,
            'mode' => $run->mode,
            // 코어가 붙인 사유는 번역 키로 저장돼 있다 — 보는 관리자의 언어로 푼다.
            'error' => PrivacyManualRunService::displayError($run->error),
            'requester_name' => $run->requester?->name,
            'queued_at' => $this->formatDateTimeStringForUser($run->queued_at),
            'started_at' => $this->formatDateTimeStringForUser($run->started_at),
            'finished_at' => $this->formatDateTimeStringForUser($run->finished_at),
        ];
    }
}
