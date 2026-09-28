<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimPaymentMethod;

/**
 * 클레임 추가결제 실행 요청 (결제 수단 확정 → PG 결제창 파라미터 또는 마일리지 즉시 결제)
 */
class ClaimPaymentPayRequest extends FormRequest
{
    use ValidatesClaimPaymentMethod;

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
            'payment_method' => $this->paymentMethodRules(),
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
            'payment_method.in' => __('sirsoft-ecommerce::validation.claims.payment_method.in'),
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
            'payment_method' => __('sirsoft-ecommerce::validation.request_attributes.payment_method'),
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
     * 결제 수단을 반환합니다. (미지정이면 null)
     *
     * @return string|null 결제 수단
     */
    public function paymentMethod(): ?string
    {
        $method = $this->validated('payment_method');

        return $method !== null && $method !== '' ? (string) $method : null;
    }
}
