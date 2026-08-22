<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class AuthCallbackRequest extends FormRequest
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
            'resultCode'  => ['required', 'string'],
            'resultMsg'   => ['nullable', 'string'],
            'authToken'   => ['nullable', 'string'],
            'authUrl'     => ['nullable', 'string'],
            'checkAckUrl' => ['nullable', 'string'], // authUrl 과 동일 역할, 버전에 따라 다름
            'netCancelUrl' => ['nullable', 'string'],
            'idc_name'    => ['nullable', 'string'],
            // 주문번호: 구버전 MOID 또는 신버전 orderNumber
            'MOID'        => ['nullable', 'string'],
            'orderNumber' => ['nullable', 'string'],
            // 결제금액: 콜백에 없을 수 있음 → 컨트롤러에서 주문 조회로 보완
            'TotPrice'    => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        Log::error('KG Inicis: AuthCallbackRequest validation failed', [
            'errors' => $validator->errors()->toArray(),
            'input'  => array_keys($this->all()),
        ]);

        throw new \Illuminate\Validation\ValidationException($validator);
    }
}
