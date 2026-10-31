<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\Sirsoft\PayKginicis\Http\Requests\Concerns\RedirectsInvalidCallbackToFailUrl;

class AuthCallbackRequest extends FormRequest
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
     * rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'resultCode' => ['required', 'string'],
            'resultMsg' => ['nullable', 'string'],
            'authToken' => ['nullable', 'string'],
            'authUrl' => ['nullable', 'string'],
            'checkAckUrl' => ['nullable', 'string'], // authUrl 과 동일 역할, 버전에 따라 다름
            'netCancelUrl' => ['nullable', 'string'],
            'idc_name' => ['nullable', 'string'],
            // 주문번호: 구버전 MOID 또는 신버전 orderNumber
            'MOID' => ['nullable', 'string'],
            'orderNumber' => ['nullable', 'string'],
            // 결제금액: 브라우저를 거친 값이라 승인 판정에 쓰지 않는다 — 승인액은 서버 승인 응답의 TotPrice 만 쓴다
            'TotPrice' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 인증 콜백 검증 실패 시 오류 목록과 입력 키만 로그로 남기고 검증 예외를 던진다.
     *
     * 입력값 자체는 결제 정보를 담을 수 있어 키 이름만 기록한다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 결제 실패 화면으로의 302 (Referer 를 따르지 않는다)
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->redirectInvalidCallback($validator, 'KG Inicis: AuthCallbackRequest validation failed');
    }
}
