<?php

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 토스페이먼츠 결제 실패 콜백 요청 검증
 *
 * GET /plugins/sirsoft-tosspayments/payment/fail
 *     ?code={ERR}&message={MSG}&orderId={OID}
 */
class FailCallbackRequest extends FormRequest
{
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
            'code' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
            'orderId' => ['nullable', 'string'],
        ];
    }

    /**
     * 검증 전 기본값 설정
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'code' => 'UNKNOWN',
            'message' => '',
        ]);
    }
}
