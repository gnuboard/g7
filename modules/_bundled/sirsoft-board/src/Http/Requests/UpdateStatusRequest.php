<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;

/**
 * 신고 상태 변경 요청 폼 검증
 */
class UpdateStatusRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::in(ReportStatus::values()),
                function ($attribute, $value, $fail) {
                    // 현재 신고 조회 (라우트 파라미터명은 {report} — Repository Interface 경유)
                    $report = app(ReportRepositoryInterface::class)->find((int) $this->route('report'));

                    if (! $report) {
                        return; // 404는 컨트롤러에서 처리
                    }

                    // Enum의 전환 규칙 메서드 사용 (deleted 등 최종 상태는 전환 불가 → 422)
                    if (! $report->status->canTransitionTo($value)) {
                        $fail(__('sirsoft-board::validation.report.invalid_status_transition'));
                    }
                },
            ],
            'process_note' => ['nullable', 'string', 'max:1000'],
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
            'status.required' => __('sirsoft-board::validation.report.status.required'),
            'status.in' => __('sirsoft-board::validation.report.status.in'),
            'process_note.max' => __('sirsoft-board::validation.report.process_note.max'),
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
            'status' => __('sirsoft-board::attributes.report.status'),
            'process_note' => __('sirsoft-board::attributes.report.process_note'),
        ];
    }
}
