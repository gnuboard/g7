<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;

/**
 * 관리자 환불 실행 요청 ('환불하기' — D18)
 *
 * 본문의 금액은 선택 사항이다 — 실려 있으면 화면에 입력된 값으로 실행하고(저장 없이 실행 가능),
 * 없으면 저장된 환불액으로 실행한다. 상한(D19)은 서비스가 실행 직전에 재계산해 검증한다.
 */
class ExecuteRefundRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
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
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_points_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_method' => ['nullable', 'string', Rule::in(RefundMethodEnum::values())],
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
            'refund_amount.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'refund_amount.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_points_amount.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'refund_points_amount.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_method.in' => __('sirsoft-ecommerce::validation.claims.refund_method.in'),
        ];
    }

    /**
     * 실행 시점 금액을 보낸 키만 담아 반환합니다.
     *
     * @return array<string, mixed> 실행 시점 금액 (없으면 빈 배열 — 저장값으로 실행)
     */
    public function overrides(): array
    {
        $amounts = [];

        foreach (['refund_amount', 'refund_points_amount', 'refund_method'] as $key) {
            if ($this->has($key)) {
                $amounts[$key] = $this->validated($key);
            }
        }

        return $amounts;
    }
}
