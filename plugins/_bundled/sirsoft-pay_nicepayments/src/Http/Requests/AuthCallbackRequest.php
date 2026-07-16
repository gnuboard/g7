<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\Sirsoft\PayNicepayments\Support\UrlHelper;

/**
 * 나이스페이먼츠 결제 승인 콜백 요청 검증
 *
 * POST /plugins/sirsoft-pay_nicepayments/payment/callback
 *
 * 나이스페이먼츠가 브라우저를 통해 POST 방식으로 전달하는 파라미터입니다.
 */
class AuthCallbackRequest extends FormRequest
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

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
     * NicePay 콜백 페이로드 검증 규칙
     *
     * AuthResultCode 가 '0000' 이 아닐 때 (사용자 취소/실패) 는 NextAppURL 등
     * 후속 필드가 없을 수 있어 strict 검증을 풀어 컨트롤러가 직접 분기 처리.
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        // 사용자 취소 / 인증 실패 시 NicePay 는 NextAppURL / TxTid / AuthToken /
        // Signature 등을 보내지 않거나 빈 값으로 보낸다. AuthResultCode 가
        // '0000' 이 아닐 땐 strict 검증을 풀어 controller 가 직접 분기 처리하도록 한다.
        // (그렇지 않으면 validation 단계에서 '?error=invalid_params' 로 redirect 되어
        //  generic 에러 toast 가 뜨고 사용자 취소가 실제 에러처럼 보임)
        $code = $this->input('AuthResultCode');

        if ($code !== null && $code !== '0000') {
            return [
                'AuthResultCode' => ['required', 'string'],
                'AuthResultMsg' => ['nullable', 'string'],
                'Moid' => ['nullable', 'string'],
                'Amt' => ['nullable', 'integer', 'min:1'],
                'MallReserved' => ['nullable', 'string', 'max:1000'],
                'MallReserved1' => ['nullable', 'string', 'max:20'],
            ];
        }

        return [
            'AuthResultCode' => ['required', 'string'],
            'AuthResultMsg' => ['nullable', 'string'],
            'NextAppURL' => ['required', 'string', 'url'],
            'TxTid' => ['required', 'string'],
            'AuthToken' => ['required', 'string'],
            'PayMethod' => ['required', 'string'],
            'MID' => ['required', 'string'],
            'Moid' => ['required', 'string'],
            'Amt' => ['required', 'integer', 'min:1'],
            'NetCancelURL' => ['required', 'string', 'url'],
            'Signature' => ['required', 'string'],
            'MallReserved' => ['nullable', 'string', 'max:1000'],
            'MallReserved1' => ['nullable', 'string', 'max:20'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $url = $baseUrl . $separator . http_build_query(['error' => 'invalid_params']);

        throw new HttpResponseException(
            redirect(UrlHelper::toAbsolute($url))
        );
    }
}
