<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin\Concerns;

use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\CouponFirstPurchaseTrigger;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 쿠폰 발급방법·발급조건·발급 알림 규칙 검증 trait
 *
 * 판정 기준은 **저장 후 확정될 조합**이다 — 수정은 부분 갱신이라 한쪽만 올 수 있고 나머지는 저장값을 승계한다
 * ({@see ValidatesCouponValidityPair} 와 같은 방식). 생성·수정 요청이 같은 trait 를 써서 강도를 맞춘다.
 *
 * 1. 자동발급 ↔ 가입·첫구매·생일, 직접·다운로드 ↔ 수동
 * 2. 첫구매면 발급 시점이 있어야 한다(생성 요청에 키가 없으면 구매확정으로 채운다). 다른 조건이면 시점을 비운다
 * 3. 자동발급이면 회원당 한도는 0 으로 고정한다 — 횟수는 발급 키와 DB 유니크가 정한다
 * 4. 다운로드 쿠폰은 발급 알림 대상이 아니다(회원이 직접 받는다)
 */
trait ValidatesCouponIssueRules
{
    /**
     * 발급 규칙 필드를 저장 가능한 모양으로 정규화합니다. `prepareForValidation()` 에서 부릅니다.
     *
     * @param  bool  $isCreate  생성 요청이면 true
     * @return array<string, mixed> 병합할 값
     */
    protected function normalizeIssueRuleInput(bool $isCreate): array
    {
        $merge = [];

        foreach (['notify_enabled', 'notify_marketing_only'] as $field) {
            if ($this->has($field)) {
                $normalized = filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) {
                    $merge[$field] = $normalized;
                }
            }
        }

        $coupon = $isCreate ? null : $this->existingCouponForIssueRules();
        $method = $this->has('issue_method') ? (string) $this->input('issue_method') : $coupon?->issue_method?->value;
        $condition = $this->has('issue_condition') ? (string) $this->input('issue_condition') : $coupon?->issue_condition?->value;

        if ($method === CouponIssueMethod::AUTO->value && ($isCreate || $this->has('issue_method') || $this->has('per_user_limit'))) {
            $merge['per_user_limit'] = 0;
        }

        if ($method === CouponIssueMethod::DOWNLOAD->value && ($isCreate || $this->has('issue_method') || $this->has('notify_enabled'))) {
            $merge['notify_enabled'] = false;
        }

        if ($condition === CouponIssueCondition::FIRST_PURCHASE->value) {
            if ($isCreate && ! $this->has('first_purchase_trigger_status')) {
                $merge['first_purchase_trigger_status'] = CouponFirstPurchaseTrigger::CONFIRMED->value;
            }
        } elseif ($condition !== null && ($isCreate || $this->has('issue_condition') || $this->has('first_purchase_trigger_status'))) {
            $merge['first_purchase_trigger_status'] = null;
        }

        return $merge;
    }

    /**
     * 발급 규칙 필드의 기본 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    protected function issueRuleRules(): array
    {
        return [
            'first_purchase_trigger_status' => 'nullable|string|in:'.implode(',', CouponFirstPurchaseTrigger::values()),
            'notify_enabled' => 'sometimes|boolean',
            'notify_marketing_only' => 'sometimes|boolean',
        ];
    }

    /**
     * 발급방법·조건 조합과 첫구매 시점 검증을 등록합니다.
     *
     * @param  Validator  $validator  검증기 인스턴스
     * @return void 반환값 없음
     */
    protected function validateIssueRules(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['issue_method', 'issue_condition'])) {
                return;
            }

            $coupon = $this->existingCouponForIssueRules();
            $method = $this->has('issue_method') ? (string) $this->input('issue_method') : $coupon?->issue_method?->value;
            $condition = $this->has('issue_condition') ? (string) $this->input('issue_condition') : $coupon?->issue_condition?->value;

            if ($method === null || $condition === null) {
                return;
            }

            $isAuto = $method === CouponIssueMethod::AUTO->value;
            $isManualCondition = $condition === CouponIssueCondition::MANUAL->value;

            if ($isAuto && $isManualCondition) {
                $validator->errors()->add('issue_condition', __('sirsoft-ecommerce::validation.coupon.auto_condition_invalid'));

                return;
            }

            if (! $isAuto && ! $isManualCondition) {
                $validator->errors()->add('issue_condition', __('sirsoft-ecommerce::validation.coupon.manual_condition_required'));

                return;
            }

            if ($condition === CouponIssueCondition::FIRST_PURCHASE->value) {
                $trigger = $this->has('first_purchase_trigger_status')
                    ? $this->input('first_purchase_trigger_status')
                    : $coupon?->first_purchase_trigger_status;

                if ($trigger === null || $trigger === '') {
                    $validator->errors()->add('first_purchase_trigger_status', __('sirsoft-ecommerce::validation.coupon.first_purchase_trigger_required'));
                }
            }
        });
    }

    /**
     * 승계 대상이 되는 기존 쿠폰을 조회합니다. (생성 요청은 null)
     *
     * @return Coupon|null 라우트가 가리키는 쿠폰
     */
    protected function existingCouponForIssueRules(): ?Coupon
    {
        $id = $this->route('id');

        if ($id === null) {
            return null;
        }

        return app(CouponRepositoryInterface::class)->findById((int) $id);
    }
}
