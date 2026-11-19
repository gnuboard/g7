<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Traits\PresentsMemberName;

/**
 * 신고 로그 API 리소스
 *
 * boards_report_logs 레코드를 API 응답 형식으로 변환합니다.
 * 신고자 목록 무한스크롤 엔드포인트에서 사용됩니다.
 */
class ReportLogResource extends BaseApiResource
{
    use PresentsMemberName;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 신고자 이름은 관리자 화면의 표시 이름이다. 실명은 회원 조회 권한이 있고 표시 이름과 다를 때만 `real_name` 으로 더한다.
            'reporter' => $this->reporter
                ? $this->boardMemberNameFields($this->reporter, $request) + [
                    'uuid' => $this->reporter->uuid,
                    'email' => $this->canExposeMemberEmail(null, $this->reporter->id) ? $this->reporter->email : null,
                ]
                : null,
            'reason_type' => $this->reason_type?->value ?? null,
            'reason_type_label' => $this->reason_type?->label() ?? null,
            'reason_detail' => $this->reason_detail,
            'snapshot' => $this->snapshot,
            'reported_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
