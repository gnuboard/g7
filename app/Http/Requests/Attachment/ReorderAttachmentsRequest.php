<?php

namespace App\Http\Requests\Attachment;

use App\Extension\HookManager;
use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 첨부파일 순서 변경 요청 검증
 */
class ReorderAttachmentsRequest extends FormRequest
{
    /**
     * 요청 권한 확인
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'order' => ['required', 'array', 'min:1'],
            'order.*.id' => ['required', 'integer', Rule::exists(Attachment::class, 'id')],
            'order.*.order' => ['required', 'integer', 'min:0'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.attachment.reorder_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.required' => __('attachment.validation.order_required'),
            'order.array' => __('attachment.validation.order_array'),
            'order.*.id.required' => __('attachment.validation.order_id_required'),
            'order.*.id.exists' => __('attachment.validation.order_id_exists'),
            'order.*.order.required' => __('attachment.validation.order_value_required'),
            'order.*.order.integer' => __('attachment.validation.order_value_integer'),
        ];
    }
}
