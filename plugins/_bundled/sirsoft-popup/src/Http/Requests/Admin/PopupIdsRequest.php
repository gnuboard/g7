<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Popup\Models\Popup;

/**
 * 팝업 ID 목록만 받는 일괄 요청 — 일괄 복사·일괄 삭제.
 */
class PopupIdsRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다(복사 update, 삭제 delete).
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
        ];
    }
}
