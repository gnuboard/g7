<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;

/**
 * 완료 처리 모달 미리보기 요청 (D26 — `POST admin/claims/completion-preview`)
 *
 * 세 진입점(목록 일괄·상품 단위·상세 버튼)이 여는 같은 모달의 요약 값을 요청한다.
 * `claims` 상한은 목록 일괄 선택 상한과 같은 100 이다 — 상한이 없으면 실패 시 항목마다
 * 오류를 전수 열거해 수백 KB 짜리 422 가 된다(§4-6 공통 계약과 같은 이유).
 */
class ClaimCompletionPreviewRequest extends FormRequest
{
    /**
     * 신청서 ID => 소속 상품 행 ID 목록 (묶음 조회 결과 메모이즈)
     *
     * @var array<int, array<int, int>>|null
     */
    protected ?array $allowedItemIds = null;

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
     * 완료 모달은 교환·반품 전용이다 — 환불신청서의 완료는 환불 실행(`refunds/{id}/execute`)이,
     * 취소는 즉시 파이프라인이 소유한다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'claim_type' => ['required', 'string', Rule::in([ClaimTypeEnum::EXCHANGE->value, ClaimTypeEnum::RETURN->value])],
            'create_refund_request' => ['required', 'boolean'],
            'claims' => ['required', 'array', 'min:1', 'max:100'],
            'claims.*.id' => ['required', 'integer'],
            'claims.*.item_ids' => ['nullable', 'array', 'max:200'],
            'claims.*.item_ids.*' => $this->itemScopeRule(),
            'claims.*.restore_stock_by_item' => ['nullable', 'array'],
            'claims.*.restore_stock_by_item.*' => ['boolean'],
            // 선교환 「미회수로 완료」 로 고른 행 — 실행과 같은 완료 대상·재고 요약을 내기 위해 받는다(D3·D26).
            // 신청서별 후보와의 교집합은 서비스가 낸다(후보가 아닌 ID 는 조용히 빠진다 — 실행은 422 로 막는다).
            'claims.*.unreturned_item_ids' => ['nullable', 'array', 'max:200'],
            'claims.*.unreturned_item_ids.*' => ['integer'],
        ];
    }

    /**
     * 항목 ID 가 그 항목이 딸린 신청서 소속인지 검사하는 규칙을 반환합니다.
     *
     * `claims.*.id` 가 요소마다 다르므로 정적 `Rule::exists` 로는 상위 스코프를 부착할 수 없다.
     * `Rule::forEach` 로 요소별 부모 신청서 ID 를 읽어 스코프를 건다 — 소속 밖 행 ID 는 422 다.
     * 부착하지 않으면 서비스의 관계 필터가 그 행을 조용히 버리고, 줄어든 미리보기가
     * 그대로 완료 실행의 근거가 된다(S6a `BulkClaimOptionStatusRequest` 와 같은 결).
     *
     * @return NestedRules 요소별 스코프 규칙
     */
    protected function itemScopeRule(): NestedRules
    {
        return Rule::forEach(function (mixed $value, string $attribute) {
            // `claims.N.item_ids.M` → 형제 `claims.N.id` 가 이 항목의 부모 신청서다.
            $claimKey = (string) str($attribute)->beforeLast('.item_ids.');
            $claimId = (int) $this->input($claimKey.'.id');

            return ['integer', Rule::in($this->allowedItemIds()[$claimId] ?? [])];
        });
    }

    /**
     * 요청에 실린 전 신청서의 소속 상품 행 ID 를 한 번에 조회해 신청서별로 묶어 반환합니다.
     *
     * 요소마다 `Rule::exists` 를 걸면 쿼리 수가 `claims`(최대 100) × `item_ids`(최대 200)
     * 의 곱이 된다 — 검증 단계 하나가 최악 20,000 쿼리를 낸다. 원형(`BulkClaimOptionStatusRequest`)
     * 은 신청서 1건 스코프라 곱이 없었고, 다건 요청으로 옮기면서 생긴 축이다.
     * 유형별 1회 묶음 조회로 읽어 두고 소속 판정은 메모리에서 한다.
     *
     * @return array<int, array<int, int>> 신청서 ID => 상품 행 ID 목록
     */
    protected function allowedItemIds(): array
    {
        if ($this->allowedItemIds !== null) {
            return $this->allowedItemIds;
        }

        $claimIds = [];

        foreach ((array) $this->input('claims') as $entry) {
            $id = (int) ($entry['id'] ?? 0);

            if ($id > 0) {
                $claimIds[$id] = $id;
            }
        }

        $claimIds = array_values($claimIds);

        // 검증 중에 읽는 값이다 — 문자열이 아니면 형 변환하지 않는다(형식 오류는 규칙이 422 로 돌려준다).
        $claimType = $this->input('claim_type');

        $this->allowedItemIds = (is_string($claimType) ? ClaimTypeEnum::tryFrom($claimType) : null) === ClaimTypeEnum::EXCHANGE
            ? app(OrderExchangeOptionRepositoryInterface::class)->listOptionIdsByExchangeIds($claimIds)
            : app(OrderReturnOptionRepositoryInterface::class)->listOptionIdsByReturnIds($claimIds);

        return $this->allowedItemIds;
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'claim_type.required' => __('sirsoft-ecommerce::validation.claims.type.required'),
            'claim_type.in' => __('sirsoft-ecommerce::validation.claims.type.in'),
            'create_refund_request.required' => __('sirsoft-ecommerce::validation.claims.completion.create_refund_request_required'),
            'create_refund_request.boolean' => __('sirsoft-ecommerce::validation.claims.completion.create_refund_request_boolean'),
            'claims.required' => __('sirsoft-ecommerce::validation.claims.preview_claims.required'),
            'claims.array' => __('sirsoft-ecommerce::validation.claims.preview_claims.array'),
            'claims.max' => __('sirsoft-ecommerce::validation.claims.preview_claims.max'),
            'claims.min' => __('sirsoft-ecommerce::validation.claims.preview_claims.required'),
            'claims.*.id.required' => __('sirsoft-ecommerce::validation.claims.preview_claims.id_required'),
            'claims.*.id.integer' => __('sirsoft-ecommerce::validation.claims.preview_claims.id_integer'),
            'claims.*.item_ids.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'claims.*.item_ids.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'claims.*.item_ids.*.integer' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
            'claims.*.item_ids.*.in' => __('sirsoft-ecommerce::validation.claims.items.not_in_claim'),
            'claims.*.restore_stock_by_item.array' => __('sirsoft-ecommerce::validation.claims.completion.restore_stock_by_item_array'),
            'claims.*.restore_stock_by_item.*.boolean' => __('sirsoft-ecommerce::validation.claims.completion.restore_stock_by_item_boolean'),
            'claims.*.unreturned_item_ids.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'claims.*.unreturned_item_ids.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'claims.*.unreturned_item_ids.*.integer' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
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
            'claim_type' => __('sirsoft-ecommerce::validation.request_attributes.claim_type'),
            'create_refund_request' => __('sirsoft-ecommerce::validation.request_attributes.create_refund_request'),
            'claims' => __('sirsoft-ecommerce::validation.request_attributes.claims'),
            'claims.*.id' => __('sirsoft-ecommerce::validation.request_attributes.claim_id'),
            'claims.*.item_ids' => __('sirsoft-ecommerce::validation.request_attributes.completion_item_ids'),
            'claims.*.item_ids.*' => __('sirsoft-ecommerce::validation.request_attributes.completion_item_id'),
            'claims.*.restore_stock_by_item' => __('sirsoft-ecommerce::validation.request_attributes.restore_stock_by_item'),
            'claims.*.restore_stock_by_item.*' => __('sirsoft-ecommerce::validation.request_attributes.restore_stock_item'),
            'claims.*.unreturned_item_ids' => __('sirsoft-ecommerce::validation.request_attributes.unreturned_item_ids'),
            'claims.*.unreturned_item_ids.*' => __('sirsoft-ecommerce::validation.request_attributes.unreturned_item_id'),
        ];
    }

    /**
     * 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum 클레임 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        return ClaimTypeEnum::from((string) $this->validated('claim_type'));
    }

    /**
     * 환불신청서 접수 선택 상태를 반환합니다.
     *
     * @return bool 지금 접수 선택 여부
     */
    public function createRefundRequest(): bool
    {
        return (bool) $this->validated('create_refund_request');
    }

    /**
     * 대상 신청서 목록을 반환합니다.
     *
     * @return array<int, array{id: int, item_ids?: array<int, int>, restore_stock_by_item?: array<int|string, bool>, unreturned_item_ids?: array<int, int>}> 대상 목록
     */
    public function claims(): array
    {
        return (array) $this->validated('claims');
    }
}
