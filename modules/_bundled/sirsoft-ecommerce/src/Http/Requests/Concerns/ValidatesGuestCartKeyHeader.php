<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

/**
 * 비회원 X-Cart-Key 헤더 검증 트레이트
 *
 * 비회원의 장바구니·임시 주문은 cart_key 로만 주인이 가려진다. 키 없이 들어온 비회원 쓰기 요청은
 * user_id·cart_key 가 모두 null 인 행을 만들고, 그 행은 누구도 조회·삭제하지 못한다(조회·삭제 요청은
 * 키를 요구하므로). 그래서 임시 주문의 조회·연장·삭제(CartKeyRequest)뿐 아니라 생성·갱신 요청도
 * 같은 판정을 쓴다 — 판정을 한 곳에 둬 요청마다 다르게 고쳐지지 않게 한다.
 *
 * 회원은 user_id 로 주인이 가려지므로 키가 없어도 통과한다.
 */
trait ValidatesGuestCartKeyHeader
{
    /**
     * X-Cart-Key 헤더를 검증합니다 — 비회원이면 필수, `ck_` + 32자 영숫자.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function validateCartKeyHeader($validator): void
    {
        $userId = Auth::id();
        $cartKey = $this->header('X-Cart-Key');

        // 회원인 경우 cart_key 검증 불필요
        if ($userId !== null) {
            return;
        }

        // 비회원인 경우 cart_key 필수
        if (empty($cartKey)) {
            $validator->errors()->add(
                'cart_key',
                __('sirsoft-ecommerce::validation.cart.cart_key_required')
            );

            return;
        }

        // cart_key 형식 검증: ck_ + 32자 영숫자
        if (! preg_match('/^ck_[a-zA-Z0-9]{32}$/', $cartKey)) {
            $validator->errors()->add(
                'cart_key',
                __('sirsoft-ecommerce::validation.cart.invalid_cart_key')
            );
        }
    }
}
