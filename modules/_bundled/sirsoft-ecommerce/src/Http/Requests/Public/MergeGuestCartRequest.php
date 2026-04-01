<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use Illuminate\Support\Facades\Auth;

/**
 * 비회원 장바구니 병합 요청
 *
 * 로그인 상태와 X-Cart-Key 헤더 검증을 포함합니다.
 */
class MergeGuestCartRequest extends CartKeyRequest
{
    /**
     * 훅 필터 이름
     *
     * @return string
     */
    protected function hookFilterName(): string
    {
        return 'sirsoft-ecommerce.cart.merge_validation_rules';
    }

    /**
     * 추가 검증 수행
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // 로그인 필수 검증
            if (! Auth::id()) {
                $validator->errors()->add(
                    'auth',
                    __('sirsoft-ecommerce::validation.cart.login_required')
                );

                return;
            }

            // cart_key 필수 검증 (회원이라도 병합 시에는 필수)
            $cartKey = $this->header('X-Cart-Key');
            if (empty($cartKey)) {
                $validator->errors()->add(
                    'cart_key',
                    __('sirsoft-ecommerce::validation.cart.cart_key_required')
                );

                return;
            }

            // cart_key 형식 검증
            if (! preg_match('/^ck_[a-zA-Z0-9]{32}$/', $cartKey)) {
                $validator->errors()->add(
                    'cart_key',
                    __('sirsoft-ecommerce::validation.cart.invalid_cart_key')
                );
            }
        });
    }
}
