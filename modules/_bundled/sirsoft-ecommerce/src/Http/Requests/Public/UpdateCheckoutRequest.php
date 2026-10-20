<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesGuestCartKeyHeader;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;

/**
 * 임시 주문 업데이트 요청
 *
 * 쿠폰, 마일리지, 배송 주소 변경 시 임시 주문을 재계산합니다.
 */
class UpdateCheckoutRequest extends FormRequest
{
    use ValidatesGuestCartKeyHeader;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 허용(주인 판정은 헤더 검증이 맡음)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 필드별 검증 규칙(훅 필터 적용 뒤)
     */
    public function rules(): array
    {
        $rules = [
            // 상품별 쿠폰 (상품 옵션 ID를 키로, 쿠폰 ID 배열을 값으로)
            'item_coupons' => 'nullable|array',
            'item_coupons.*' => 'array|max:2', // 상품당 최대 2개
            'item_coupons.*.*' => ['integer', Rule::exists(CouponIssue::class, 'id')],

            // 주문 쿠폰 (단일)
            'order_coupon_issue_id' => ['nullable', 'integer', Rule::exists(CouponIssue::class, 'id')],

            // 배송비 쿠폰 (단일)
            'shipping_coupon_issue_id' => ['nullable', 'integer', Rule::exists(CouponIssue::class, 'id')],

            // 마일리지
            'use_points' => 'nullable|integer|min:0',

            // 우편번호 (도서산간 여부 판별용)
            'zipcode' => 'nullable|string|max:10',

            // 배송 국가 코드 (ISO 3166-1 alpha-2)
            'country_code' => 'nullable|string|size:2',

            // 결제 수단 (향후 결제수단별 할인/수수료 계산 확장용)
            'payment_method' => 'nullable|string|max:50',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.checkout.update_validation_rules', $rules, $this);
    }

    /**
     * 비회원 X-Cart-Key 헤더를 검증합니다 — 조회·연장·삭제(CartKeyRequest)와 같은 판정.
     * 키 없는 비회원 요청이 주인 없는(user_id·cart_key 모두 null) 임시 주문을 만들지 않게 한다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateCartKeyHeader($validator);
        });
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 규칙별 다국어 메시지
     */
    public function messages(): array
    {
        return [
            'item_coupons.array' => __('sirsoft-ecommerce::validation.checkout.item_coupons_array'),
            'item_coupons.*.max' => __('sirsoft-ecommerce::validation.checkout.item_coupons_max'),
            'item_coupons.*.*.integer' => __('sirsoft-ecommerce::validation.checkout.item_coupon_integer'),
            'item_coupons.*.*.exists' => __('sirsoft-ecommerce::validation.checkout.item_coupon_not_found'),
            'order_coupon_issue_id.integer' => __('sirsoft-ecommerce::validation.checkout.order_coupon_integer'),
            'order_coupon_issue_id.exists' => __('sirsoft-ecommerce::validation.checkout.order_coupon_not_found'),
            'shipping_coupon_issue_id.integer' => __('sirsoft-ecommerce::validation.checkout.shipping_coupon_integer'),
            'shipping_coupon_issue_id.exists' => __('sirsoft-ecommerce::validation.checkout.shipping_coupon_not_found'),
            'use_points.integer' => __('sirsoft-ecommerce::validation.checkout.use_points_integer'),
            'use_points.min' => __('sirsoft-ecommerce::validation.checkout.use_points_min'),
            'zipcode.max' => __('sirsoft-ecommerce::validation.checkout.zipcode_max'),
        ];
    }
}
