<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;

/**
 * 내보내기 카탈로그 조회 요청 검증 — 요청 권한 + 그 유형의 조회 권한
 */
class ExportCatalogRequest extends FormRequest
{
    /**
     * 요청한 유형을 레지스트리에서 찾습니다.
     *
     * @return ExportTypeInterface|null 유형
     */
    public function exportType(): ?ExportTypeInterface
    {
        return app(ExportTypeRegistry::class)->find((string) $this->input('type'));
    }

    /**
     * 권한 확인 — 요청 권한은 라우트 미들웨어, 유형 조회 권한은 유형이 검증된 뒤 컨트롤러가 본다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(app(ExportTypeRegistry::class)->keys())],
            // 목록의 조회 기간 — 창이 그 기간을 그대로 쓸 수 있는지(없거나 1년 초과면 기본 기간) 판정하는 데만 쓴다
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.export.catalog_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'type.required' => __('sirsoft-ecommerce::validation.export.type_invalid'),
            'type.in' => __('sirsoft-ecommerce::validation.export.type_invalid'),
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
            'type' => __('sirsoft-ecommerce::validation.export_attributes.type'),
            'start_date' => __('sirsoft-ecommerce::validation.export_attributes.start_date'),
            'end_date' => __('sirsoft-ecommerce::validation.export_attributes.end_date'),
        ];
    }
}
