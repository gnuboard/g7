<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController;

/**
 * 에스크로 배송 등록 요청 검증
 *
 * 값은 KG 이니시스 에스크로 배송등록 API 로 전송되고 payment_meta 로 영속된다.
 * 상한 근거(KG 이니시스 연동 스펙): 운송장 40자 / 이름 30자 / 전화 20자 /
 * 우편번호 10자 / 주소 200자. 택배사 코드는 공식 코드표(컨트롤러 상수)가 SSoT.
 * report/charge 는 미전송이면 컨트롤러 기본값(I/SH)으로 흡수하고, 비허용 값은 422 로 차단한다.
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
            'invoice' => ['required', 'string', 'max:40'],
            'ex_code' => ['required', 'string', Rule::in(array_keys(AdminEscrowDeliveryController::COURIER_CODES))],
            'report' => ['nullable', 'string', 'in:I,U'],
            'charge' => ['nullable', 'string', 'in:SH,BH'],
            'recv_name' => ['nullable', 'string', 'max:30'],
            'recv_tel' => ['nullable', 'string', 'max:20'],
            'recv_post' => ['nullable', 'string', 'max:10'],
            'recv_addr' => ['nullable', 'string', 'max:200'],
            'regist_name' => ['nullable', 'string', 'max:30'],
            'send_name' => ['nullable', 'string', 'max:30'],
            'send_tel' => ['nullable', 'string', 'max:20'],
            'send_post' => ['nullable', 'string', 'max:10'],
            'send_addr' => ['nullable', 'string', 'max:200'],
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
            'invoice.required' => __('sirsoft-pay_kginicis::messages.escrow.invoice_required'),
            'ex_code.required' => __('sirsoft-pay_kginicis::messages.escrow.courier_required'),
            'ex_code.in' => __('sirsoft-pay_kginicis::messages.escrow.courier_required'),
        ];
    }
}
