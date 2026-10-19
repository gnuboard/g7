<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 내보내기 선택 삭제 요청 검증
 *
 * 없는 ID·진행중·권한 범위 밖 작업은 422 로 거절하지 않고 서비스가 건너뛴 뒤 그 수를 알린다 — 목록을 연 뒤
 * 다른 사람이 지웠거나 상태가 바뀐 행이 섞여도 나머지는 지워져야 한다.
 */
class ExportBulkDeleteRequest extends FormRequest
{
    /**
     * 권한 확인 — 라우트 미들웨어(exports.delete)가 담당한다
     *
     * @return bool 항상 true
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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.export.bulk_delete_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.export.ids_required'),
            'ids.array' => __('sirsoft-ecommerce::validation.export.ids_required'),
            'ids.min' => __('sirsoft-ecommerce::validation.export.ids_required'),
            'ids.max' => __('sirsoft-ecommerce::validation.export.ids_too_many'),
            'ids.*.integer' => __('sirsoft-ecommerce::validation.export.ids_required'),
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
            'ids' => __('sirsoft-ecommerce::validation.export_attributes.ids'),
            'ids.*' => __('sirsoft-ecommerce::validation.export_attributes.ids'),
        ];
    }
}
