<?php

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 토스페이먼츠 결제 성공 콜백 요청 검증
 *
 * GET /plugins/sirsoft-tosspayments/payment/success
 *     ?paymentKey={PK}&orderId={OID}&amount={AMT}
 */
class SuccessCallbackRequest extends FormRequest
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 권한 확인 (PG 콜백은 인증 불필요)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'paymentKey' => ['required', 'string'],
            'orderId' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * 검증 실패 시 결제 실패 페이지로 리다이렉트
     *
     * PG 콜백은 브라우저 리다이렉트이므로 422 대신 실패 페이지로 이동합니다.
     *
     * @param Validator $validator 검증기 인스턴스
     * @return void
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        throw new HttpResponseException(
            redirect($baseUrl.$separator.http_build_query(['error' => 'invalid_params']))
        );
    }
}
