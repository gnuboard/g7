<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 가상계좌 입금 완료 건 환불 요청 검증
 *
 * 환불 계좌 정보는 나이스페이먼츠 취소 API 로 전송되고 도메인 취소 사유로
 * 영속되는 금전 표면이다. 상한 근거(나이스페이먼츠 연동 스펙):
 * TID 30자 / Moid 64자 / CancelAmt 12자리 / CancelMsg 100자(EUC-KR) /
 * RefundAcctNo 16자리 숫자 / RefundBankCd 3자리 은행코드 / RefundAcctNm 10자.
 */
class VbankRefundRequest extends FormRequest
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
     * 가상계좌 환불 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'tid' => ['required', 'string', 'max:30'],
            'moid' => ['required', 'string', 'max:64'],
            'cancel_amt' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'cancel_msg' => ['nullable', 'string', 'max:100'],
            'refund_acct_no' => ['required', 'string', 'max:16', 'regex:/^\d+$/'],
            'refund_bank_cd' => ['required', 'string', 'regex:/^\d{3}$/'],
            'refund_acct_nm' => ['required', 'string', 'max:10'],
        ];
    }

    /**
     * 검증 오류 메시지에 쓰일 필드 표시명을 반환합니다.
     *
     * @return array<string, string> 필드별 표시명
     */
    public function attributes(): array
    {
        return [
            'tid' => __('sirsoft-pay_nicepayments::messages.fields.tid'),
            'moid' => __('sirsoft-pay_nicepayments::messages.fields.moid'),
            'cancel_amt' => __('sirsoft-pay_nicepayments::messages.fields.cancel_amt'),
            'cancel_msg' => __('sirsoft-pay_nicepayments::messages.fields.cancel_msg'),
            'refund_acct_no' => __('sirsoft-pay_nicepayments::messages.fields.refund_acct_no'),
            'refund_bank_cd' => __('sirsoft-pay_nicepayments::messages.fields.refund_bank_cd'),
            'refund_acct_nm' => __('sirsoft-pay_nicepayments::messages.fields.refund_acct_nm'),
        ];
    }
}
