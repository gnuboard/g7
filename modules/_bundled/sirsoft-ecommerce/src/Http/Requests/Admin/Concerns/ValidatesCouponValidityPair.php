<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin\Concerns;

use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 쿠폰 유효기간 쌍(valid_type / valid_days) 정합성 검증 trait
 *
 * `valid_type = days_from_issue` 인데 `valid_days` 가 비면 만료일을 계산할 수 없어
 * 발급 시각과 만료 시각이 같아집니다. 그렇게 저장된 쿠폰은 발급되는 즉시 만료 상태가 되어
 * 받은 회원이 쓸 수 없는데, 예외도 로그도 남지 않아 조용히 흘러갑니다.
 *
 * 판정 기준은 "이번 요청에 무엇이 왔는가" 가 아니라 **저장 후 확정될 조합** 입니다.
 * 생성은 두 필드가 항상 요청에 있지만, 수정은 부분 갱신이라 한쪽만 올 수 있고 나머지는
 * 저장값을 승계해야 하기 때문입니다. 그래서 규칙 배열의 `required_if` 대신 이 trait 로
 * 정책을 단일화합니다 — `required_if` 는 조건 필드(`valid_type`)가 요청에 없으면 아예
 * 발화하지 않아, `valid_days: null` 만 보내는 요청이 그대로 통과합니다(실측).
 *
 * 생성 요청에서는 승계할 저장값이 없으므로 이 trait 가 `required_if` 와 정확히 같은 강도로
 * 동작하고, 수정 요청에서는 그 상위집합이 됩니다.
 */
trait ValidatesCouponValidityPair
{
    /**
     * 유효기간 유형과 일수의 조합 정합성 검증을 등록합니다.
     *
     * 유효기간 쌍을 건드리지 않는 요청(이름만 수정 등)은 판정 대상에서 제외합니다.
     * 그러지 않으면 이미 깨진 조합으로 저장된 쿠폰을 고칠 길이 사라집니다.
     *
     * @param  Validator  $validator  검증기 인스턴스
     */
    protected function validateValidityPair(Validator $validator): void
    {
        if (! $this->has('valid_type') && ! $this->has('valid_days')) {
            return;
        }

        $validator->after(function (Validator $validator) {
            $coupon = $this->existingCouponForValidity();

            $effectiveType = $this->has('valid_type')
                ? $this->input('valid_type')
                : $coupon?->valid_type;

            if ($effectiveType !== 'days_from_issue') {
                return;
            }

            $effectiveDays = $this->has('valid_days')
                ? $this->input('valid_days')
                : $coupon?->valid_days;

            if (is_numeric($effectiveDays) && (int) $effectiveDays > 0) {
                return;
            }

            $validator->errors()->add(
                'valid_days',
                __('sirsoft-ecommerce::validation.coupon.valid_days_required')
            );
        });
    }

    /**
     * 승계 대상이 되는 기존 쿠폰을 조회합니다.
     *
     * 생성 요청에는 라우트 키가 없으므로 항상 null 이며, 그때는 요청값만으로 판정합니다.
     *
     * @return Coupon|null 라우트가 가리키는 쿠폰 (생성 요청·미존재 시 null)
     */
    protected function existingCouponForValidity(): ?Coupon
    {
        $id = $this->route('id');

        if ($id === null) {
            return null;
        }

        return app(CouponRepositoryInterface::class)->findById((int) $id);
    }
}
