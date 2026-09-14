<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;
use Modules\Sirsoft\Ecommerce\Support\ClaimTransitionVocabulary;

/**
 * 관리자 신청 상품 단위 일괄 상태 전이 요청 (D25 · §4-3)
 *
 * 상품 행 ID 는 라우트의 신청서에 소속된 것만 통과한다 — 상위 스코프 없는 존재 확인은
 * 다른 신청서의 상품을 이 경로로 움직이게 한다("배열 항목의 상위 스코프" 규정). 스코프에
 * 걸린 ID 는 서비스의 무음 탈락(`resolveItems()`)에 닿기 전에 422 로 드러난다 — 화면이
 * "0건 처리됨" 을 침묵으로 받는 결함의 차단 지점이다.
 *
 * ## 모양은 하나뿐이다 — `items: [{id, quantity?}]`
 *
 * 스칼라 배열(`items: [55, 56]`)을 **함께 받지 않는다.** 두 모양을 받으면 스코프 규칙이
 * 둘로 갈라져 위 결함이 되살아나고, 배포 중에 두 형태가 섞여도 아무 데도 드러나지 않는다.
 * 스칼라가 오면 422 로 드러나야 한다. 소비자는 저장소 안의 핸들러 2곳뿐이고 공개 API 가
 * 아니다(서비스 경계는 `ClaimItemSpecs` 가 두 모양을 받지만, 그것은 이 검증 **뒤쪽**의
 * 내부 표현이다).
 *
 * ## 같은 행을 두 줄로 보내는 것을 막는다
 *
 * 두 줄이 각각 잔여 수량을 보고 통과한 뒤 합계가 그 행의 수량을 넘는다. 서비스는 정규화
 * 단계에서 뒤엣것만 남기므로 **오류 없이 한쪽 지시가 사라진다** — 여기서 422 로 세운다.
 *
 * 결제대기는 상품 단위 어휘에서 뺀다 — 결제는 신청서 단위 축이라 상품 행에는 의미가 없다.
 */
class BulkClaimOptionStatusRequest extends FormRequest
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
        $type = ClaimTypeEnum::fromAdminPathSegment((string) $this->route('claimType'));
        $claimId = (int) $this->route('id');

        // 3항 연산자로 두 유형만 갈라내면 **환불이 반품 테이블로 떨어진다** — 항상 422 이거나,
        // 우연히 같은 ID 가 있으면 남의 신청서 행을 통과시킨다(뒤쪽이 더 나쁘다).
        $itemExists = match ($type) {
            ClaimTypeEnum::EXCHANGE => Rule::exists(OrderExchangeOption::class, 'id')->where('order_exchange_id', $claimId),
            ClaimTypeEnum::RETURN => Rule::exists(OrderReturnOption::class, 'id')->where('order_return_id', $claimId),
            ClaimTypeEnum::REFUND => Rule::exists(OrderRefundOption::class, 'id')->where('order_refund_id', $claimId),
            // 취소는 라우트 어휘에 없다(`adminPathSegment()` 가 null). 여기에 닿으면 어떤 행도
            // 통과시키지 않는다 — 존재하지 않는 신청서 ID 로 스코프를 건다.
            default => Rule::exists(OrderExchangeOption::class, 'id')->where('order_exchange_id', 0),
        };

        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['array'],
            'items.*.id' => ['required', 'integer', $itemExists],
            // 수량 미지정은 "전량" 이다 — 수량을 싣지 않는 화면 경로(전건 전이)가 그대로 돈다.
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(ClaimTransitionVocabulary::assignableValuesFor($type, true))],
        ] + $this->completionRules();
    }

    /**
     * 추가 검증 — 같은 상품 행을 두 줄로 보내는 것을 막습니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = [];

            foreach ((array) $this->input('items', []) as $entry) {
                if (is_array($entry) && isset($entry['id'])) {
                    $ids[] = (int) $entry['id'];
                }
            }

            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('items', __('sirsoft-ecommerce::validation.claims.items.duplicate_id'));
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
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'items.*.array' => __('sirsoft-ecommerce::validation.claims.items.entry_object'),
            'items.*.id.required' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
            'items.*.id.integer' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
            'items.*.id.exists' => __('sirsoft-ecommerce::validation.claims.items.not_in_claim'),
            'items.*.quantity.integer' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'status.required' => __('sirsoft-ecommerce::validation.claims.admin_status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.claims.admin_status.in'),
        ] + $this->completionMessages();
    }

    /**
     * 전이 지시를 반환합니다. (`[{id, quantity}]` — 수량 미지정은 null)
     *
     * @return array<int, array{id: int, quantity: int|null}> 전이 지시
     */
    public function itemSpecs(): array
    {
        return ClaimItemSpecs::normalize((array) $this->validated('items'));
    }

    /**
     * 대상 상품 행 ID 목록을 반환합니다.
     *
     * @return array<int, int> 상품 행 ID 목록
     */
    public function itemIds(): array
    {
        return ClaimItemSpecs::ids($this->itemSpecs());
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
     * 전이 부가 정보를 반환합니다.
     *
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array<string, mixed> 부가 정보
     */
    public function context(?int $processedBy): array
    {
        return ['processed_by' => $processedBy];
    }
}
