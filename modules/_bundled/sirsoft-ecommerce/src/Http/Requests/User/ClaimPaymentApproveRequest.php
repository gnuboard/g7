<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * 클레임 추가결제 PG 승인 콜백 요청
 *
 * PG 마다 콜백 파라미터가 다르므로(토스 paymentKey/orderId/amount, 이니시스 authToken/authUrl …) 형태를
 * 고정하지 않고 `callback` 객체로 통째 받는다 — 해석·검증은 `claim_payment.approve` 훅을 구현한 결제
 * 플러그인의 몫이다. 서버는 승인 금액을 청구액과 대조한다.
 */
class ClaimPaymentApproveRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'callback' => ['required', 'array'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'callback.required' => __('sirsoft-ecommerce::validation.claims.callback.required'),
            'callback.array' => __('sirsoft-ecommerce::validation.claims.callback.array'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'callback' => __('sirsoft-ecommerce::validation.request_attributes.payment_callback'),
        ];
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }

    /**
     * PG 콜백 파라미터를 반환합니다.
     *
     * @return array 콜백 파라미터
     */
    public function callback(): array
    {
        return (array) $this->validated('callback');
    }
}
