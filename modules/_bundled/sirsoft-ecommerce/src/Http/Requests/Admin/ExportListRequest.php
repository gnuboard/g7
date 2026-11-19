<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Repositories\ExportRepository;

/**
 * 내보내기 관리 목록 조회 요청 검증 (배송정책 목록과 같은 규약)
 */
class ExportListRequest extends FormRequest
{
    /**
     * 권한 확인 — 라우트 미들웨어(exports.read)가 담당한다
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 쿼리스트링 불리언(`mine=true`)을 정규화합니다. 해석할 수 없는 값은 건드리지 않는다(422 로 알린다).
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('mine')) {
            return;
        }

        $normalized = filter_var($this->input('mine'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized !== null) {
            $this->merge(['mine' => $normalized]);
        }
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'search_field' => ['nullable', Rule::in(['all', 'file_name', 'requester'])],
            'search_keyword' => ['nullable', 'string', 'max:200'],
            'requester_uuid' => ['nullable', 'string', 'uuid'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(ExportStatusEnum::values())],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(app(ExportTypeRegistry::class)->keys())],
            'personal' => ['nullable', Rule::in(['all', 'with', 'without'])],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'mine' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(ExportRepository::SORTABLE_COLUMNS)],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.export.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'search_field.in' => __('sirsoft-ecommerce::validation.list.search_field.in'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'statuses.*.in' => __('sirsoft-ecommerce::validation.export.status_invalid'),
            'types.*.in' => __('sirsoft-ecommerce::validation.export.type_invalid'),
            'personal.in' => __('sirsoft-ecommerce::validation.export.personal_filter_invalid'),
            'end_date.after_or_equal' => __('sirsoft-ecommerce::validation.export.period_order'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'per_page.min' => __('sirsoft-ecommerce::validation.list.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.list.per_page.max'),
        ];
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 → 라벨
     */
    public function attributes(): array
    {
        return [
            'search_field' => __('sirsoft-ecommerce::validation.export_attributes.search_field'),
            'search_keyword' => __('sirsoft-ecommerce::validation.export_attributes.search_keyword'),
            'requester_uuid' => __('sirsoft-ecommerce::validation.export_attributes.requester_uuid'),
            'statuses' => __('sirsoft-ecommerce::validation.export_attributes.statuses'),
            'statuses.*' => __('sirsoft-ecommerce::validation.export_attributes.statuses'),
            'types' => __('sirsoft-ecommerce::validation.export_attributes.types'),
            'types.*' => __('sirsoft-ecommerce::validation.export_attributes.types'),
            'personal' => __('sirsoft-ecommerce::validation.export_attributes.personal'),
            'start_date' => __('sirsoft-ecommerce::validation.export_attributes.start_date'),
            'end_date' => __('sirsoft-ecommerce::validation.export_attributes.end_date'),
            'mine' => __('sirsoft-ecommerce::validation.export_attributes.mine'),
            'sort_by' => __('sirsoft-ecommerce::validation.export_attributes.sort_by'),
            'sort_order' => __('sirsoft-ecommerce::validation.export_attributes.sort_order'),
            'per_page' => __('sirsoft-ecommerce::validation.export_attributes.per_page'),
            'page' => __('sirsoft-ecommerce::validation.export_attributes.page'),
        ];
    }
}
