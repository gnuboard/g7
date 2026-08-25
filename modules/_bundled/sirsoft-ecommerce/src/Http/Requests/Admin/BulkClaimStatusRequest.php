<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimCompletionOptions;

/**
 * 관리자 클레임 일괄 상태 전이 요청
 *
 * 목록이 유형 서브탭 단위이므로 일괄 대상도 한 유형이다 — `type` + `ids[]`.
 * 취소는 전이 대상이 아니라 유형에서 제외한다. 완료 전이의 `completion` 계약은
 * 단건(`UpdateClaimStatusRequest`)과 동일하다 — 세 진입점이 같은 계약을 쓴다(D26).
 */
class BulkClaimStatusRequest extends FormRequest
{
    use ResolvesClaimCompletionOptions;

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
            'type' => ['required', 'string', Rule::in([
                ClaimTypeEnum::EXCHANGE->value,
                ClaimTypeEnum::RETURN->value,
                ClaimTypeEnum::REFUND->value,
            ])],
            // 상한은 무제한 루프 방어다 — 서비스가 신청서당 개별 트랜잭션을 돌므로
            // 한 요청이 수천 건을 실으면 응답이 상한 없이 길어진다.
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'status' => ['required', 'string', Rule::in($this->assignableStatuses())],
            'reject_reason' => ['nullable', 'string', 'max:2000'],
            'admin_memo' => ['nullable', 'string', 'max:2000'],
        ] + $this->completionRules();
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'type.required' => __('sirsoft-ecommerce::validation.claims.type.required'),
            'type.in' => __('sirsoft-ecommerce::validation.claims.type.in'),
            'ids.required' => __('sirsoft-ecommerce::validation.claims.ids.required'),
            'ids.array' => __('sirsoft-ecommerce::validation.claims.ids.array'),
            'ids.min' => __('sirsoft-ecommerce::validation.claims.ids.min'),
            'ids.max' => __('sirsoft-ecommerce::validation.claims.ids.max'),
            'ids.*.integer' => __('sirsoft-ecommerce::validation.claims.ids.integer'),
            'status.required' => __('sirsoft-ecommerce::validation.claims.admin_status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.claims.admin_status.in'),
            'reject_reason.max' => __('sirsoft-ecommerce::validation.claims.reject_reason.max'),
            'admin_memo.max' => __('sirsoft-ecommerce::validation.claims.admin_memo.max'),
        ] + $this->completionMessages();
    }

    /**
     * 요청 본문의 클레임 유형에 허용되는 관리자 지정 상태 어휘를 반환합니다.
     *
     * @return array<int, string> 허용 상태 값
     */
    protected function assignableStatuses(): array
    {
        return match (ClaimTypeEnum::tryFrom((string) $this->input('type'))) {
            ClaimTypeEnum::EXCHANGE => ExchangeStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::RETURN => ReturnStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::REFUND => RefundStatusEnum::adminAssignableValues(),
            default => [],
        };
    }

    /**
     * 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        return ClaimTypeEnum::from((string) $this->validated('type'));
    }

    /**
     * 대상 신청서 ID 목록을 반환합니다.
     *
     * @return array<int, int> 신청서 ID 목록
     */
    public function ids(): array
    {
        return array_map('intval', (array) $this->validated('ids'));
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
     * 전이 부가 정보를 반환합니다. (단건 요청과 동일 계약)
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

        return $context;
    }
}
