<?php

namespace App\Http\Requests\ActivityLog;

use App\Models\ActivityLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 활동 로그 일괄 삭제 요청을 검증합니다.
 */
class ActivityLogBulkDeleteRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 항상 true (권한은 permission 미들웨어에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', Rule::exists(ActivityLog::class, 'id')],
        ];
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('activity_log.validation.ids_required'),
            'ids.min' => __('activity_log.validation.ids_min'),
            'ids.*.exists' => __('activity_log.validation.id_not_found'),
        ];
    }
}
