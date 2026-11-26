<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 내 활동 기록 조회 요청 (7.2.1)
 *
 * 인자 없이 부르면 1쪽 50건으로 종전과 같은 결과다(관리자 작업 기록이 빠진 것만 다르다).
 * 기간은 사이트 날짜(YYYY-MM-DD)로 받는다.
 */
class UserActivityLogRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한 미들웨어가 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];

        return HookManager::applyFilters('core.user.activity_log_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('validation.activity_log.date_range_invalid'),
        ];
    }
}
