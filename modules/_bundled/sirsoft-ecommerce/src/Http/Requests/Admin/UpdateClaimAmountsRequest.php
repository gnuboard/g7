<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;

/**
 * 관리자 클레임 금액 수정 요청 (D18)
 *
 * 유형 세그먼트에 따라 받는 축이 다르다 — 교환·반품은 배송비·청구방식·메모, 환불신청서는
 * 환불액(PG/마일리지)·환불수단이다. 다른 유형의 축은 `prohibited` 로 명시 거절한다 —
 * 조용히 버리면 화면은 수정됐다고 믿는데 서버에는 아무 일도 없는 형태가 된다.
 *
 * 환불액의 상한(D19 취소 가능 금액)은 서비스가 저장·실행 두 지점에서 같은 식으로 재계산해
 * 검증한다 — 여기서는 형식(음수 금지·어휘)만 게이트한다.
 */
class UpdateClaimAmountsRequest extends FormRequest
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
        if ($this->claimType() === ClaimTypeEnum::REFUND) {
            return [
                'refund_amount' => ['nullable', 'numeric', 'min:0'],
                'refund_points_amount' => ['nullable', 'numeric', 'min:0'],
                'refund_method' => ['nullable', 'string', Rule::in(RefundMethodEnum::values())],
                'claim_shipping_fee' => ['prohibited'],
                'charge_method' => ['prohibited'],
                'admin_memo' => ['prohibited'],
            ];
        }

        return [
            'claim_shipping_fee' => ['nullable', 'numeric', 'min:0'],
            'charge_method' => ['nullable', 'string', Rule::in(ClaimChargeMethodEnum::values())],
            'admin_memo' => ['nullable', 'string', 'max:2000'],
            'refund_amount' => ['prohibited'],
            'refund_points_amount' => ['prohibited'],
            'refund_method' => ['prohibited'],
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
            'claim_shipping_fee.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'claim_shipping_fee.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'claim_shipping_fee.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'charge_method.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'admin_memo.max' => __('sirsoft-ecommerce::validation.claims.admin_memo.max'),
            'admin_memo.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'refund_amount.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'refund_amount.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_amount.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'refund_points_amount.numeric' => __('sirsoft-ecommerce::validation.claims.amounts.numeric'),
            'refund_points_amount.min' => __('sirsoft-ecommerce::validation.claims.amounts.min'),
            'refund_points_amount.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'refund_method.in' => __('sirsoft-ecommerce::validation.claims.refund_method.in'),
            'refund_method.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
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
            'refund_amount' => __('sirsoft-ecommerce::validation.request_attributes.refund_amount'),
            'refund_points_amount' => __('sirsoft-ecommerce::validation.request_attributes.refund_points_amount'),
            'refund_method' => __('sirsoft-ecommerce::validation.request_attributes.refund_method'),
            'claim_shipping_fee' => __('sirsoft-ecommerce::validation.request_attributes.claim_shipping_fee'),
            'charge_method' => __('sirsoft-ecommerce::validation.request_attributes.charge_method'),
            'admin_memo' => __('sirsoft-ecommerce::validation.request_attributes.admin_memo'),
        ];
    }

    /**
     * 라우트 세그먼트의 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum|null 클레임 유형
     */
    public function claimType(): ?ClaimTypeEnum
    {
        return ClaimTypeEnum::fromAdminPathSegment((string) $this->route('claimType'));
    }

    /**
     * 수정값을 보낸 키만 담아 반환합니다.
     *
     * 키 존재 여부가 의미를 갖는다 — 서비스가 `array_key_exists` 로 판정하므로 보내지 않은
     * 축은 기존 값을 유지한다.
     *
     * @return array<string, mixed> 수정값
     */
    public function amounts(): array
    {
        $keys = $this->claimType() === ClaimTypeEnum::REFUND
            ? ['refund_amount', 'refund_points_amount', 'refund_method']
            : ['claim_shipping_fee', 'charge_method', 'admin_memo'];

        $amounts = [];

        foreach ($keys as $key) {
            if ($this->has($key)) {
                $amounts[$key] = $this->validated($key);
            }
        }

        return $amounts;
    }
}
