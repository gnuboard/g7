<?php

namespace App\Http\Requests\Schedule;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleHistoryMode;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 스케줄 목록 조회 요청
 *
 * 쿠폰 목록과 같은 규약이다 — 검색대상(`search_field`) + 키워드(`search_keyword`), 라디오 필터는
 * 「전체」도 값(`all`)으로 받는다. 키 목록은 화면 data_source params 와 1:1 이다.
 */
class ScheduleListRequest extends FormRequest
{
    /**
     * 검색대상 select 값 (all 제외).
     *
     * - name: 표시 명칭(label 각 로케일) + 이름
     * - identifier: 기계 식별자(name)
     * - command: 명령·URL·Job 클래스
     */
    public const SEARCH_FIELDS = ['name', 'identifier', 'command'];

    /**
     * 다중 검색 조건(`filters[]`)이 쓸 수 있는 컬럼 — 7.2.0 이전 API 호환용.
     */
    public const SEARCHABLE_FIELDS = ['name', 'description', 'command'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 권한 검사는 라우트의 permission 미들웨어가 담당하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $withAll = fn (array $values) => 'nullable|string|in:'.implode(',', array_merge(['all'], $values));
        $owners = array_merge(ExtensionOwnerType::values(), [ScheduleRepositoryInterface::OWNER_TYPE_CUSTOM]);

        $rules = [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',

            // 검색대상 + 키워드
            'search_field' => $withAll(self::SEARCH_FIELDS),
            'search_keyword' => 'nullable|string|max:255',

            // 다중 검색 조건 (7.2.0 이전 API 호환)
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => 'required_with:filters|string|in:all,'.implode(',', self::SEARCHABLE_FIELDS),
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            // 기본 필터
            'extension_type' => $withAll($owners),
            'extension_identifier' => 'nullable|string|max:255',
            'type' => $withAll(ScheduleType::values()),
            'status' => $withAll(['active', 'inactive']),
            'last_result' => $withAll(ScheduleResultStatus::lastResultValues()),

            // 상세 필터
            'frequency' => $withAll(ScheduleFrequency::values()),
            'dispatch_mode' => $withAll(ScheduleDispatchMode::values()),
            'overlap' => $withAll(['on', 'off']),
            // 이 축은 Enum 값에 `all`(모든 실행 기록)이 있어 「전체」를 빈 값으로 받는다 — `all` 은 값 그대로다
            'history_mode' => 'nullable|string|in:'.implode(',', ScheduleHistoryMode::values()),
            'run_in_maintenance' => 'nullable|in:0,1',
            'created_start_date' => 'nullable|date',
            'created_end_date' => 'nullable|date|after_or_equal:created_start_date',
            // 7.2.0 이전 키 (호출 호환)
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date|after_or_equal:created_from',

            // 정렬
            'sort_by' => 'nullable|string|in:created_at,name,next_run_at,last_run_at,is_active,last_result',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.list_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('schedule.validation.page_integer'),
            'page.min' => __('schedule.validation.page_min'),
            'per_page.integer' => __('schedule.validation.per_page_integer'),
            'per_page.min' => __('schedule.validation.per_page_min'),
            'per_page.max' => __('schedule.validation.per_page_max'),
            'search_field.in' => __('schedule.validation.search_field_invalid'),
            'extension_type.in' => __('schedule.validation.extension_type_invalid'),
            'type.in' => __('schedule.validation.type_invalid'),
            'frequency.in' => __('schedule.validation.frequency_invalid'),
            'status.in' => __('schedule.validation.status_invalid'),
            'last_result.in' => __('schedule.validation.last_result_invalid'),
            'dispatch_mode.in' => __('schedule.validation.dispatch_mode_invalid'),
            'history_mode.in' => __('schedule.validation.history_mode_invalid'),
            'overlap.in' => __('schedule.validation.overlap_invalid'),
            'created_start_date.date' => __('schedule.validation.created_from_invalid'),
            'created_end_date.date' => __('schedule.validation.created_to_invalid'),
            'created_end_date.after_or_equal' => __('schedule.validation.created_to_after_from'),
            'created_from.date' => __('schedule.validation.created_from_invalid'),
            'created_to.date' => __('schedule.validation.created_to_invalid'),
            'created_to.after_or_equal' => __('schedule.validation.created_to_after_from'),
            'sort_by.in' => __('schedule.validation.sort_by_invalid'),
            'sort_order.in' => __('schedule.validation.sort_order_invalid'),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string> 필드 => 표시 이름
     */
    public function attributes(): array
    {
        return [
            'page' => __('schedule.attributes.page'),
            'per_page' => __('schedule.attributes.per_page'),
            'search_field' => __('schedule.attributes.search_field'),
            'search_keyword' => __('schedule.attributes.search_keyword'),
            'filters' => __('schedule.attributes.filters'),
            'filters.*.field' => __('schedule.attributes.search_field'),
            'filters.*.value' => __('schedule.attributes.search_keyword'),
            'filters.*.operator' => __('schedule.attributes.filters_operator'),
            'extension_type' => __('schedule.attributes.owner'),
            'extension_identifier' => __('schedule.attributes.owner_identifier'),
            'type' => __('schedule.attributes.type'),
            'status' => __('schedule.attributes.status'),
            'last_result' => __('schedule.attributes.last_result'),
            'frequency' => __('schedule.attributes.frequency'),
            'dispatch_mode' => __('schedule.attributes.dispatch_mode'),
            'overlap' => __('schedule.attributes.without_overlapping'),
            'history_mode' => __('schedule.attributes.history_mode'),
            'run_in_maintenance' => __('schedule.attributes.run_in_maintenance'),
            'created_start_date' => __('schedule.attributes.created_start_date'),
            'created_end_date' => __('schedule.attributes.created_end_date'),
            'created_from' => __('schedule.attributes.created_start_date'),
            'created_to' => __('schedule.attributes.created_end_date'),
            'sort_by' => __('schedule.attributes.sort_by'),
            'sort_order' => __('schedule.attributes.sort_order'),
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        // value가 비어있는 filters 제거
        $filters = $this->filters;
        if (is_array($filters)) {
            $filters = array_filter($filters, function ($filter) {
                return ! empty($filter['value']);
            });
            $filters = array_values($filters);
            $this->merge(['filters' => $filters ?: null]);
        }

        // 기본값 설정
        $this->merge([
            'per_page' => $this->per_page ?? 20,
            'page' => $this->page ?? 1,
            'sort_by' => $this->sort_by ?? 'created_at',
            'sort_order' => $this->sort_order ?? 'desc',
        ]);
    }
}
