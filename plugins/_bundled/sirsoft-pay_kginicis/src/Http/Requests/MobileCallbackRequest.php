<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\Concerns\RedirectsInvalidCallbackToFailUrl;

class MobileCallbackRequest extends FormRequest
{
    use RedirectsInvalidCallbackToFailUrl;

    /**
     * authorize
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 모바일 결제 인증 콜백의 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            // 인증 결과 코드: '00' = 성공
            'P_STATUS' => ['required', 'string'],
            'P_RMESG1' => ['nullable', 'string'],
            // 거래번호
            'P_TID' => ['nullable', 'string'],
            // 서버 승인 URL (IDC 화이트리스트 검증 대상)
            'P_REQ_URL' => ['nullable', 'string'],
            // 결제 금액
            'P_AMT' => ['nullable', 'string'],
            // 주문번호
            'P_OID' => ['nullable', 'string'],
            // IDC 센터 코드 (fc/ks/stg)
            'idc_name' => ['nullable', 'string'],
        ];
    }

    /**
     * 검증 실패를 기록하고 결제 실패 화면으로 보냅니다(Referer 를 따르지 않는다).
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->redirectInvalidCallback($validator, 'KG Inicis: MobileCallbackRequest validation failed');
    }
}
