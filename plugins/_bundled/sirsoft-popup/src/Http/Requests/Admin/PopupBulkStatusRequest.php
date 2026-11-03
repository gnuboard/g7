<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Popup\Models\Popup;

/**
 * 일괄 노출·비노출 요청.
 */
class PopupBulkStatusRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
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
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists(Popup::class, 'id')],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'ids' => __('sirsoft-popup::validation.attributes.ids'),
            'ids.*' => __('sirsoft-popup::validation.attributes.ids_item'),
            'is_active' => __('sirsoft-popup::validation.attributes.is_active'),
        ];
    }
}
