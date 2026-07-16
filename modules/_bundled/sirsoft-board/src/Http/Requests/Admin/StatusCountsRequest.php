<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\ReportStatus;

/**
 * 관리자 신고 상태별 건수 조회 요청 폼 검증
 *
 * 일괄 처리 전 선택한 신고들의 상태 분포를 보여주기 위한 요청을 검증합니다.
 */
class StatusCountsRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행합니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'target_status' => ['nullable', 'string', Rule::in(ReportStatus::values())],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-board::messages.reports.no_reports_selected'),
            'ids.array' => __('sirsoft-board::messages.reports.no_reports_selected'),
            'ids.min' => __('sirsoft-board::messages.reports.no_reports_selected'),
            'target_status.in' => __('sirsoft-board::validation.report.status.in'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ids' => __('sirsoft-board::attributes.report.ids'),
        ];
    }
}
