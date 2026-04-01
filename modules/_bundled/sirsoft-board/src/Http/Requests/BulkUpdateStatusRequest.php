<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Report;

/**
 * 신고 대량 상태 변경 요청 폼 검증
 */
class BulkUpdateStatusRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
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
            'ids.*' => ['integer', Rule::exists(Report::class, 'id')],
            'status' => ['required', 'string', Rule::in(ReportStatus::values())],
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
            'ids.required' => __('sirsoft-board::validation.report.ids.required'),
            'ids.array' => __('sirsoft-board::validation.report.ids.array'),
            'ids.min' => __('sirsoft-board::validation.report.ids.min'),
            'ids.*.integer' => __('sirsoft-board::validation.report.ids.integer'),
            'ids.*.exists' => __('sirsoft-board::validation.report.ids.exists'),
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
            'ids' => __('sirsoft-board::attributes.report.ids'),
            'status' => __('sirsoft-board::attributes.report.status'),
            'process_note' => __('sirsoft-board::attributes.report.process_note'),
        ];
    }
}
