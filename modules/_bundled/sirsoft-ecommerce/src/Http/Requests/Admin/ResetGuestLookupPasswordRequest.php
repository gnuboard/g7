<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 비회원 주문 조회 비밀번호 재설정 요청 (관리자)
 *
 * 관리자가 비회원 주문의 조회 비밀번호를 새 값으로 재설정할 때 사용됩니다.
 * 검증 규칙은 비회원 주문 생성 시(Public\CreateOrderRequest)와 동일하게
 * 8자 이상 + 확인 일치를 강제합니다 (G7 회원가입 정책 min:8|confirmed 와 일치).
 */
class ResetGuestLookupPasswordRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (실제 권한 검증은 미들웨어가 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // G7 회원가입 정책과 일치 — RegisterRequest 의 min:8|confirmed
        return [
            'guest_lookup_password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'guest_lookup_password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * 비회원 주문 생성 시와 동일한 다국어 키를 재사용합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guest_lookup_password.required' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_required'),
            'guest_lookup_password.min' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_min'),
            'guest_lookup_password.confirmed' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_confirmed'),
            'guest_lookup_password_confirmation.required' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_confirmation_required'),
        ];
    }
}
