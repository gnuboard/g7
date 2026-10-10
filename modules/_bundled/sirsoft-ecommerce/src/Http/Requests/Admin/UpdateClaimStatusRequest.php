<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimTransitionTracking;

/**
 * 관리자 클레임 단건 상태 전이 요청
 *
 * 상태 어휘는 유형별 Enum 의 `adminAssignableValues()` 로 게이트한다 — 신청·결제완료·철회처럼
 * 소유 경로가 따로 있는 상태의 직접 지정을 막는 쓰기 게이트다(`values()` 전체를 쓰는 목록
 * 필터와 다르다). 완료 전이는 완료 처리 옵션(`completion`, D26)을 함께 실어야 한다 —
 * 재고 복구·환불신청서 접수는 운영자가 모달에서 고르는 값이라 기본값이 없다.
 */
class UpdateClaimStatusRequest extends FormRequest
{
    use ResolvesClaimCompletionOptions;
    use ValidatesClaimTransitionTracking;

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
            'status' => ['required', 'string', Rule::in($this->assignableStatuses())],
            // 반려는 사유가 정본이다 — 없이 받으면 구매자 화면의 반려 사유가 빈 칸으로 남는다(#599 후속).
            'reject_reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:2000'],
            'admin_memo' => ['nullable', 'string', 'max:2000'],
        ] + $this->transitionTrackingRules() + $this->completionRules();
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'status.required' => __('sirsoft-ecommerce::validation.claims.admin_status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.claims.admin_status.in'),
            'reject_reason.required_if' => __('sirsoft-ecommerce::validation.claims.reject_reason.required'),
            'reject_reason.max' => __('sirsoft-ecommerce::validation.claims.reject_reason.max'),
            'admin_memo.max' => __('sirsoft-ecommerce::validation.claims.admin_memo.max'),
        ] + $this->transitionTrackingMessages() + $this->completionMessages();
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'status' => __('sirsoft-ecommerce::validation.request_attributes.claim_status'),
            'reject_reason' => __('sirsoft-ecommerce::validation.request_attributes.reject_reason'),
            'admin_memo' => __('sirsoft-ecommerce::validation.request_attributes.admin_memo'),
        ] + $this->transitionTrackingAttributes() + $this->completionAttributes();
    }

    /**
     * 라우트 세그먼트의 클레임 유형에 허용되는 관리자 지정 상태 어휘를 반환합니다.
     *
     * @return array<int, string> 허용 상태 값
     */
    protected function assignableStatuses(): array
    {
        return match (ClaimTypeEnum::fromAdminPathSegment((string) $this->route('claimType'))) {
            ClaimTypeEnum::EXCHANGE => ExchangeStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::RETURN => ReturnStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::REFUND => RefundStatusEnum::adminAssignableValues(),
            default => [],
        };
    }

    /**
     * 목표 상태 값을 반환합니다.
     *
     * @return string 상태 값
     */
    public function statusValue(): string
    {
        return (string) $this->validated('status');
    }

    /**
     * 전이 부가 정보를 반환합니다. (processed_by / reject_reason / admin_memo)
     *
     * `admin_memo` 는 키 존재 여부가 의미를 갖는다(`applyTransition()` 이 `array_key_exists` 로
     * 판정) — 보내지 않은 요청이 기존 메모를 지우지 않도록 보낸 키만 싣는다.
     *
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array<string, mixed> 부가 정보
     */
    public function context(?int $processedBy): array
    {
        $context = ['processed_by' => $processedBy];

        if ($this->has('reject_reason')) {
            $context['reject_reason'] = $this->validated('reject_reason');
        }

        if ($this->has('admin_memo')) {
            $context['admin_memo'] = $this->validated('admin_memo');
        }

        return $context + $this->transitionTrackingContext($this->statusValue());
    }
}
