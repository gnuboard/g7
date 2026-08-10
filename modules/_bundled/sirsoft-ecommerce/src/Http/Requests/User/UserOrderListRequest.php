<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 사용자 주문 목록 조회 요청
 */
class UserOrderListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 쿼리 문자열 불리언을 정규화합니다.
     *
     * 쿼리 파라미터는 문자열로 도착하므로 `boolean` 규칙만으로는 `"true"`/`"false"` 를 통과시키지
     * 못한다. 해석 가능한 형태만 캐스팅하고, 알 수 없는 값은 그대로 둬서 422 로 걸리게 한다
     * (null 로 바꾸면 오타 파라미터가 "미지정" 으로 조용히 통과한다).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('with_items')) {
            return;
        }

        $normalized = filter_var($this->input('with_items'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($normalized !== null) {
            $this->merge(['with_items' => $normalized]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'string', Rule::in(OrderStatusEnum::values())],
            // 주문별 아이템 전량 포함 여부. 기본은 **미포함**(대표 1건 + 개수)이고, 아이템을
            // 순회하는 화면이 `with_items=1` 로 켠다 — 마이페이지 주문내역이 그렇게 요청한다
            // (`templates/_bundled/sirsoft-basic/layouts/mypage/orders.json`).
            'with_items' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('sirsoft-ecommerce::validation.orders.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.orders.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.orders.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.orders.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.orders.per_page.max'),
            'status.in' => __('sirsoft-ecommerce::validation.orders.order_status.in'),
        ];
    }
}
