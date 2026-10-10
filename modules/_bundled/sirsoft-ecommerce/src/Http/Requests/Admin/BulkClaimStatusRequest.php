<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimTransitionTracking;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 관리자 클레임 일괄 상태 전이 요청
 *
 * 목록이 유형 서브탭 단위이므로 일괄 대상도 한 유형이다 — `type` + `ids[]`.
 * 취소는 전이 대상이 아니라 유형에서 제외한다. 완료 전이의 `completion` 계약은
 * 단건(`UpdateClaimStatusRequest`)과 동일하다 — 세 진입점이 같은 계약을 쓴다(D26).
 *
 * 회수중·발송중은 신청서마다 송장을 싣는다(`trackings: [{id, carrier_id, tracking_number}]`) —
 * 송장은 신청서마다 다르므로 한 쌍을 여러 건에 나눠 쓰지 않는다(#599 후속 운영 결정). 대상 신청서
 * 하나라도 송장이 빠지면 422 다.
 */
class BulkClaimStatusRequest extends FormRequest
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
            // 반려는 사유가 정본이다 — 없이 받으면 구매자 화면의 반려 사유가 빈 칸으로 남는다(#599 후속).
            'reject_reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:2000'],
            'admin_memo' => ['nullable', 'string', 'max:2000'],
            'trackings' => ['nullable', 'required_if:status,'.implode(',', $this->trackingRequiredStatuses()), 'array', 'max:100'],
            'trackings.*' => ['array'],
            'trackings.*.id' => ['required', 'integer'],
            'trackings.*.carrier_id' => ['required', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'trackings.*.tracking_number' => ['required', 'string', 'max:50'],
        ] + $this->completionRules();
    }

    /**
     * 추가 검증 — 회수중·발송중이면 대상 신청서마다 송장이 한 쌍씩 있어야 합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // 규칙이 실패해도 사후 검증은 돈다 — 문자열이 아닌 상태는 규칙 오류(422)로 이미 돌아가므로 여기서 멈춘다.
            $status = $this->input('status');

            if (! is_string($status) || ! $this->requiresTransitionTracking($status)) {
                return;
            }

            $tracked = [];

            foreach ((array) $this->input('trackings', []) as $entry) {
                if (is_array($entry) && isset($entry['id'])) {
                    $tracked[] = (int) $entry['id'];
                }
            }

            $ids = array_map('intval', (array) $this->input('ids', []));

            if (array_diff($ids, $tracked) !== [] || count($tracked) !== count(array_unique($tracked))) {
                $validator->errors()->add('trackings', __('sirsoft-ecommerce::validation.claims.tracking.per_claim_required'));
            }
        });
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
            'reject_reason.required_if' => __('sirsoft-ecommerce::validation.claims.reject_reason.required'),
            'reject_reason.max' => __('sirsoft-ecommerce::validation.claims.reject_reason.max'),
            'admin_memo.max' => __('sirsoft-ecommerce::validation.claims.admin_memo.max'),
            'trackings.required_if' => __('sirsoft-ecommerce::validation.claims.tracking.per_claim_required'),
            'trackings.array' => __('sirsoft-ecommerce::validation.claims.tracking.per_claim_required'),
            'trackings.max' => __('sirsoft-ecommerce::validation.claims.ids.max'),
            'trackings.*.id.required' => __('sirsoft-ecommerce::validation.claims.tracking.per_claim_required'),
            'trackings.*.id.integer' => __('sirsoft-ecommerce::validation.claims.ids.integer'),
            'trackings.*.carrier_id.required' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_required'),
            'trackings.*.carrier_id.integer' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_integer'),
            'trackings.*.carrier_id.exists' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_exists'),
            'trackings.*.tracking_number.required' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_required'),
            'trackings.*.tracking_number.max' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_max'),
        ] + $this->completionMessages();
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'type' => __('sirsoft-ecommerce::validation.request_attributes.claim_type'),
            'ids' => __('sirsoft-ecommerce::validation.request_attributes.claim_ids'),
            'ids.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_id'),
            'status' => __('sirsoft-ecommerce::validation.request_attributes.claim_status'),
            'reject_reason' => __('sirsoft-ecommerce::validation.request_attributes.reject_reason'),
            'admin_memo' => __('sirsoft-ecommerce::validation.request_attributes.admin_memo'),
            'trackings' => __('sirsoft-ecommerce::validation.request_attributes.trackings'),
            'trackings.*' => __('sirsoft-ecommerce::validation.request_attributes.tracking'),
            'trackings.*.id' => __('sirsoft-ecommerce::validation.request_attributes.claim_id'),
            'trackings.*.carrier_id' => __('sirsoft-ecommerce::validation.request_attributes.carrier'),
            'trackings.*.tracking_number' => __('sirsoft-ecommerce::validation.request_attributes.tracking_number'),
        ] + $this->completionAttributes();
    }

    /**
     * 요청 본문의 클레임 유형에 허용되는 관리자 지정 상태 어휘를 반환합니다.
     *
     * @return array<int, string> 허용 상태 값
     */
    protected function assignableStatuses(): array
    {
        // 규칙 계산 중에 읽으므로 검증 전 값이다 — 문자열이 아니면 형 변환하지 않는다(형식 오류는 규칙이 422 로 돌려준다).
        $type = $this->input('type');

        return match (is_string($type) ? ClaimTypeEnum::tryFrom($type) : null) {
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
     * 신청서별 송장을 반환합니다. (회수중·발송중이 아니면 빈 배열)
     *
     * @return array<int, array{carrier_id: int, tracking_number: string}> 신청서 ID => 송장
     */
    public function trackingsById(): array
    {
        if (! $this->requiresTransitionTracking($this->statusValue())) {
            return [];
        }

        $trackings = [];

        foreach ((array) $this->validated('trackings', []) as $entry) {
            $trackings[(int) $entry['id']] = [
                'carrier_id' => (int) $entry['carrier_id'],
                'tracking_number' => (string) $entry['tracking_number'],
            ];
        }

        return $trackings;
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
