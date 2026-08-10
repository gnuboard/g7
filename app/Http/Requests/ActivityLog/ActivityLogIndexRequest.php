<?php

namespace App\Http\Requests\ActivityLog;

use App\Enums\ActivityLogType;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 활동 로그 목록 조회 요청을 검증합니다.
 */
class ActivityLogIndexRequest extends FormRequest
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
        $rules = [
            'log_type' => ['nullable', 'array'],
            'log_type.*' => ['string', Rule::in(ActivityLogType::values())],
            'action' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'loggable_type' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'search_type' => ['nullable', 'string', Rule::in(['all', 'action', 'description', 'ip_address'])],
            'created_by' => ['nullable', 'string', 'max:36'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // 게이트는 화면이 제공하는 정렬만 연다. ActivityLogRepository::SORTABLE_COLUMNS 는
            // 이보다 넓게(id·user_id 포함) 두어, 확장이 이 규칙을 훅으로 넓혔을 때 저장소가
            // 조용히 기본 정렬로 되돌리지 않도록 한다 (service-repository.md "정렬 컬럼 화이트리스트").
            'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'action', 'log_type'])],
            'sort_order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            // 커서를 주면 목록이 키셋 방식으로 응답한다. 로그는 계속 쌓이기만 하므로 깊은
            // 페이지를 OFFSET 으로 훑으면 건너뛸 행을 실제로 읽어야 한다.
            // 형식이 깨진 값은 KeysetPaginator 가 첫 페이지로 되돌리므로 여기서는 길이만 본다.
            'cursor' => ['nullable', 'string', 'max:500'],
        ];

        return HookManager::applyFilters('core.activity_log.index_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'log_type.*.in' => __('activity_log.validation.log_type_invalid'),
            'date_to.after_or_equal' => __('activity_log.validation.date_range_invalid'),
            'per_page.min' => __('activity_log.validation.per_page_min'),
            'per_page.max' => __('activity_log.validation.per_page_max'),
        ];
    }
}
