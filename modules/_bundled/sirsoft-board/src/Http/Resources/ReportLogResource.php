<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 신고자 로그 API 리소스
 *
 * reporters 페이지네이션 엔드포인트 전용.
 * ReportDetailResource::formatLogItem()과 동일한 구조를 반환합니다.
 */
class ReportLogResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'reporter'         => $this->reporter
                ? [
                    'uuid'  => $this->reporter->uuid,
                    'name'  => $this->reporter->name,
                    'email' => $this->reporter->email,
                ]
                : null,
            'reason_type'       => $this->reason_type?->value ?? null,
            'reason_type_label' => $this->reason_type?->label() ?? null,
            'reason_detail'     => $this->reason_detail,
            'snapshot'          => $this->snapshot,
            'reported_at'       => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
