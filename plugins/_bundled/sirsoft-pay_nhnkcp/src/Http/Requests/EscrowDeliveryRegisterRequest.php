<?php

// audit:allow api-doc-coverage reason: 에스크로 배송등록 엔드포인트의 기존 문서 부재(사전 상태) — 검증 규칙은 본 클래스 주석·CHANGELOG 에 기록, 문서 신설은 후속 백로그

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\PayNhnkcp\Controllers\AdminEscrowDeliveryController;

/**
 * 에스크로 배송 등록 요청 검증
 *
 * 값은 NHN KCP 에스크로 배송등록 API(mod_type=STE1)로 전송되고 payment_meta 로
 * 영속된다. 상한 근거: KCP 운송장번호 30자. 택배사 코드는 공식 코드표(컨트롤러 상수)가 SSoT.
 * 종전 인라인 한글 하드코딩 메시지를 다국어 키로 이관했다 (에스크로 배송등록 3형제 계약 대칭).
 */
class EscrowDeliveryRegisterRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어 체인이 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 에스크로 배송 등록 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'deli_numb' => ['required', 'string', 'max:30'],
            'deli_corp' => ['required', 'string', Rule::in(array_keys(AdminEscrowDeliveryController::COURIER_CODES))],
        ];
    }

    /**
     * 검증 오류 메시지를 반환합니다 (종전 한글 하드코딩을 다국어 키로 이관).
     *
     * @return array<string, string> 규칙별 메시지
     */
    public function messages(): array
    {
        return [
            'deli_numb.required' => __('sirsoft-pay_nhnkcp::messages.escrow.invoice_required'),
            'deli_corp.required' => __('sirsoft-pay_nhnkcp::messages.escrow.courier_required'),
            'deli_corp.in' => __('sirsoft-pay_nhnkcp::messages.escrow.courier_required'),
        ];
    }
}
