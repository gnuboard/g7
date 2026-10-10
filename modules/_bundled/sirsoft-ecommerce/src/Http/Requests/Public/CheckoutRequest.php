<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Cart;

/**
 * 주문하기 (체크아웃) 요청
 *
 * 장바구니에서 선택한 아이템으로 임시 주문을 생성합니다.
 */
class CheckoutRequest extends FormRequest
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
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        // item_ids(장바구니 경유) 또는 direct_items(바로 구매, 장바구니 미경유) 택일
        $isDirect = $this->has('direct_items');

        $rules = [
            'item_ids' => [Rule::requiredIf(! $isDirect), 'array', 'min:1'],
            'item_ids.*' => ['integer', Rule::exists(Cart::class, 'id')],
            'direct_items' => [Rule::requiredIf($isDirect), 'array', 'min:1'],
            'direct_items.*.product_id' => ['required_with:direct_items', 'integer'],
            // 메인 옵션 식별자 (옵션 ID 기반). 옵션 없는 상품은 미전송 → 서버가 기본 옵션 자동 해석
            'direct_items.*.product_option_id' => ['nullable', 'integer'],
            'direct_items.*.quantity' => ['required_with:direct_items', 'integer', 'min:1'],
            // 바로구매 추가옵션 선택 (서버에서 value_id 기준 검증/가격 재조회)
            'direct_items.*.additional_option_selections' => ['nullable', 'array'],
            'direct_items.*.additional_option_selections.*.additional_option_id' => ['required_with:direct_items.*.additional_option_selections', 'integer'],
            'direct_items.*.additional_option_selections.*.value_id' => ['required_with:direct_items.*.additional_option_selections', 'integer'],
            // 직접입력 텍스트 (선택지의 allow_custom_text 여부·필수성은 서버에서 재검증)
            'direct_items.*.additional_option_selections.*.custom_text' => ['nullable', 'string', 'max:255'],
            // 주문쿠폰은 `order_coupon_issue_id`(단수) 하나로 받는다 — 컨트롤러가 읽는 자리도
            // 그것뿐이고(`CheckoutController::update`), 주문서 저장 구조
            // (`TempOrder::getPromotions()` = item_coupons · order_coupon_issue_id ·
            // shipping_coupon_issue_id)도 세 슬롯으로 나뉜다.
            //
            // 예전엔 여기에 `coupon_issue_ids`(복수) 규칙이 있었는데 **최초 커밋부터 소비처가
            // 없었다**(72156e6f0 시점의 컨트롤러도 단수형만 읽는다). 검증은 통과하므로 그 필드를
            // 보낸 쪽은 200 을 받고 **할인만 조용히 빠졌다** — 규칙이 계약 문서처럼 읽히는데
            // 실제로는 무시되는, 가장 알아채기 어려운 형태였다.
            //
            // 규칙을 지워도 **요청이 422 가 되지는 않는다**(라라벨은 규칙 없는 키를 조용히 버린다).
            // 바뀌는 것은 **규칙 목록이 더 이상 그 필드를 지원한다고 말하지 않는다**는 것이다 —
            // 계약 문서로서의 오해를 없애는 것이 이 삭제의 목적이고, 동작은 그대로다.
            //
            // 주의: 같은 이름이 `orders.promotions_applied_snapshot['coupon_issue_ids']` 에도
            // 있는데 **그쪽은 서버가 계산해 저장하는 취소·복원 SSoT** 다(무관 · 건드리지 말 것).
            'use_points' => 'nullable|integer|min:0',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.checkout.validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'item_ids.required' => __('sirsoft-ecommerce::validation.checkout.item_ids_required'),
            'item_ids.array' => __('sirsoft-ecommerce::validation.checkout.item_ids_array'),
            'item_ids.min' => __('sirsoft-ecommerce::validation.checkout.item_ids_min'),
            'item_ids.*.exists' => __('sirsoft-ecommerce::validation.cart.item_not_found'),
            'use_points.integer' => __('sirsoft-ecommerce::validation.checkout.use_points_integer'),
            'use_points.min' => __('sirsoft-ecommerce::validation.checkout.use_points_min'),
        ];
    }
}
