<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 비회원 주문 조회 인증 요청
 *
 * 주문번호 + 전화번호 + 조회 비밀번호로 본인 확인을 수행합니다.
 * 권한 체크는 라우트 미들웨어가 담당하며, 본 요청은 입력 형식만 검증합니다.
 */
class VerifyGuestOrderRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 권한 보유 여부 (라우트 미들웨어가 실제 인증 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, array<int, string>> 필드별 규칙 배열
     */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:50'],
            'orderer_phone' => ['required', 'string', 'max:20'],
            'guest_lookup_password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 검증 규칙 키 → 다국어 메시지
     */
    public function messages(): array
    {
        return [
            'order_number.required' => __('sirsoft-ecommerce::validation.guest_order.order_number_required'),
            'orderer_phone.required' => __('sirsoft-ecommerce::validation.guest_order.orderer_phone_required'),
            'guest_lookup_password.required' => __('sirsoft-ecommerce::validation.guest_order.guest_lookup_password_required'),
        ];
    }
}
