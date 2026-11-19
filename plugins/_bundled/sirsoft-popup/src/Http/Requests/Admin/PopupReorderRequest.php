<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Popup\Rules\CompleteOrder;

/**
 * 순서 변경 요청 — 팝업 전체 ID 를 새 순서로 받는다(빠짐·중복 거부).
 */
class PopupReorderRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', new CompleteOrder],
            'ids.*' => ['integer', 'distinct'],
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
