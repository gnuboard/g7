<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품 옵션 배치 조회 요청
 *
 * 상품 목록에서 여러 행을 펼쳤을 때 그 상품들의 옵션을 한 번에 가져오기 위한 요청입니다.
 * 상한(100)은 상품 목록의 `per_page` 상한과 같습니다 — 한 페이지를 전부 펼친 경우가 최대치입니다.
 */
class ProductOptionsListRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 인가 여부 (권한 체크는 미들웨어에 위임)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * 존재 여부(`Rule::exists`)는 붙이지 않습니다. 배치 조회는 부분 성공을 계약으로 하며,
     * 존재하지 않거나 권한 밖인 ID 는 422 가 아니라 응답의 `product_ids` 에서 빠지는 방식으로
     * 알려줍니다. (아래 컨트롤러/Repository 주석 참조)
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 오류 메시지
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => __('sirsoft-ecommerce::validation.options_list.product_ids.required'),
            'product_ids.array' => __('sirsoft-ecommerce::validation.options_list.product_ids.array'),
            'product_ids.min' => __('sirsoft-ecommerce::validation.options_list.product_ids.min'),
            'product_ids.max' => __('sirsoft-ecommerce::validation.options_list.product_ids.max'),
            'product_ids.*.integer' => __('sirsoft-ecommerce::validation.options_list.product_ids.integer'),
            'product_ids.*.min' => __('sirsoft-ecommerce::validation.options_list.product_ids.item_min'),
        ];
    }
}
