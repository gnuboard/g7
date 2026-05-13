<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 게시판 첨부파일 순서 변경 요청 검증
 */
class ReorderAttachmentsRequest extends FormRequest
{
    /**
     * 요청 권한 확인
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
            'order.*.id' => ['required', 'integer'],
            'order.*.order' => ['required', 'integer', 'min:0'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('sirsoft-board.attachment.reorder_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.required' => __('sirsoft-board::validation.attachment.orders_required'),
            'order.array' => __('sirsoft-board::validation.attachment.orders_array'),
            'order.*.id.required' => __('sirsoft-board::validation.attachment.order_id_required'),
            'order.*.id.integer' => __('sirsoft-board::validation.attachment.order_id_integer'),
            'order.*.order.required' => __('sirsoft-board::validation.attachment.order_value_required'),
            'order.*.order.integer' => __('sirsoft-board::validation.attachment.order_value_integer'),
        ];
    }
}
