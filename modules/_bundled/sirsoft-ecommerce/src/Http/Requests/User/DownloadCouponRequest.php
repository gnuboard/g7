<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Coupon;

/**
 * 쿠폰 다운로드 요청 검증
 *
 * URL 파라미터의 쿠폰 ID 존재 여부만 검증합니다.
 * 비즈니스 검증(다운로드 가능 여부, 수량, per_user_limit)은 Service에서 수행합니다.
 */
class DownloadCouponRequest extends FormRequest
{
    /**
     * 권한 확인 — auth:sanctum 미들웨어에서 처리
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청 데이터 준비 — URL 파라미터를 검증 대상에 포함
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'coupon_id' => $this->route('couponId'),
        ]);
    }

    /**
     * 검증 규칙 정의
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'coupon_id' => [
                'required',
                'integer',
                Rule::exists(Coupon::class, 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * 검증 에러 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'coupon_id.required' => __('sirsoft-ecommerce::validation.coupon.id_required'),
            'coupon_id.integer' => __('sirsoft-ecommerce::validation.coupon.id_integer'),
            'coupon_id.exists' => __('sirsoft-ecommerce::validation.coupon.id_not_found'),
        ];
    }
}
