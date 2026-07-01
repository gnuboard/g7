<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class MobileCallbackRequest extends FormRequest
{
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

 * rules

 *

 * @return array

 */

    public function rules(): array
    {
        return [
            // 인증 결과 코드: '00' = 성공
            'P_STATUS'  => ['required', 'string'],
            'P_RMESG1'  => ['nullable', 'string'],
            // 거래번호
            'P_TID'     => ['nullable', 'string'],
            // 서버 승인 URL (IDC 화이트리스트 검증 대상)
            'P_REQ_URL' => ['nullable', 'string'],
            // 결제 금액
            'P_AMT'     => ['nullable', 'string'],
            // 주문번호
            'P_OID'     => ['nullable', 'string'],
            // IDC 센터 코드 (fc/ks/stg)
            'idc_name'  => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        Log::error('KG Inicis: MobileCallbackRequest validation failed', [
            'errors' => $validator->errors()->toArray(),
            'input'  => array_keys($this->all()),
        ]);

        throw new \Illuminate\Validation\ValidationException($validator);
    }
}
