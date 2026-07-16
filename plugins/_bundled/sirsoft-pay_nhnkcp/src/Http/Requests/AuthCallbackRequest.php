<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * KCP 결제 승인 콜백 요청 검증
 *
 * POST /plugins/sirsoft-pay_nhnkcp/payment/callback
 *
 * KCP가 브라우저를 통해 POST 방식으로 전달하는 파라미터입니다.
 */
class AuthCallbackRequest extends FormRequest
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    /**
     * 콜백 요청 인가 — 외부 PG 호출이라 항상 true
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * KCP 콜백 페이로드 검증 규칙
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        return [
            'res_cd' => ['present', 'nullable', 'string'],
            'res_msg' => ['nullable', 'string'],
            'enc_data' => ['nullable', 'string'],
            'enc_info' => ['nullable', 'string'],
            'tno' => ['nullable', 'string'],
            'ordr_idxx' => ['required', 'string'],
            'good_mny' => ['nullable', 'numeric', 'min:1'],
            'use_pay_method' => ['nullable', 'string'],
            'param_opt_1' => ['nullable', 'string', 'max:50'],
            'nhnkcp_easy_pay_method' => ['nullable', 'string', 'max:50'],
            // 모바일 SmartPhone Pay 가상계좌 콜백 (enc_data 없이 평문 전달)
            // KCP가 보내는 변종 키 모두 허용 — handleVbankIssued 에서 우선순위로 처리
            'bankname' => ['nullable', 'string'],
            'bank_name' => ['nullable', 'string'],
            'account' => ['nullable', 'string'],
            'depositor' => ['nullable', 'string'],
            'account_holder' => ['nullable', 'string'],
            'va_date' => ['nullable', 'string'],
            'vnbank_expire_date' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('KCP: authCallback validation failed', [
            'errors' => $validator->errors()->toArray(),
            'input' => array_keys($this->all()),
        ]);

        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        throw new HttpResponseException(
            redirect($baseUrl . $separator . http_build_query(['error' => 'invalid_params']))
        );
    }
}
