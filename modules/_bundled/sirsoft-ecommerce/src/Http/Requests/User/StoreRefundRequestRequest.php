<?php

// audit:allow api-doc-coverage reason: 접수 시 환불 계좌 요건(T2-D5)은 docs/api/claims.md 「접수 시 환불 계좌 요건」 절에 422 키·문구·판정 술어까지 적었다. 생성 문서에는 반영할 표면 변화가 없다 — 라우트·요청 키·응답 형태가 모두 그대로이고 추가된 것은 기존 `refund_bank` 키의 필수 조건뿐이다(`api:docgen --scope=module:sirsoft-ecommerce --check` drift 0 확인, 2026-09-23).

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimOrder;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimRefundBank;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 취소·환불 신청 요청 (회원 · 비회원 공용 — D2 자동 분기)
 *
 * 즉시취소 / 환불신청서 분기는 서비스(`OrderRefundRequestService::request`)가 상품 상태로 판정한다.
 * 여기서는 형태·주문 소속·환불 계좌만 본다 — 세 칸이 짝을 이루는가와, 돈이 계좌로 돌아가는
 * 주문인데 계좌가 비었는가다. 뒤쪽 판정은 신청 화면이 계좌 칸을 세울 때 쓰는 술어를 그대로 부른다.
 */
class StoreRefundRequestRequest extends FormRequest
{
    use ResolvesClaimOrder;
    use ValidatesClaimRefundBank;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 요청 허용 여부
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
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_option_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'reason_code' => [
                'required',
                'string',
                Rule::exists(ClaimReason::class, 'code')
                    ->where('type', ClaimTypeEnum::REFUND->value)
                    ->where('is_active', true)
                    ->where('is_user_selectable', true),
            ],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            'refund_priority' => ['nullable', 'string', Rule::in(RefundPriorityEnum::values())],
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
            'image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'image_ids.*' => ['integer'],
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
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.*.order_option_id.required' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.claims.items.quantity_required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'reason_code.required' => __('sirsoft-ecommerce::validation.claims.reason_code.required'),
            'reason_code.exists' => __('sirsoft-ecommerce::validation.claims.reason_code.exists'),
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'refund_bank.array' => __('sirsoft-ecommerce::validation.claims.refund_bank.array'),
            'image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'image_ids.max' => trans_choice('sirsoft-ecommerce::validation.claims.image_ids.max', $maxImages = $this->maxImages(), ['max' => $maxImages]),
            'image_ids.*.integer' => __('sirsoft-ecommerce::validation.claims.image_ids.integer'),
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
            'items' => __('sirsoft-ecommerce::validation.request_attributes.items'),
            'items.*.order_option_id' => __('sirsoft-ecommerce::validation.request_attributes.order_option'),
            'items.*.quantity' => __('sirsoft-ecommerce::validation.request_attributes.quantity'),
            'reason_code' => __('sirsoft-ecommerce::validation.request_attributes.reason_code'),
            'reason_detail' => __('sirsoft-ecommerce::validation.request_attributes.reason_detail'),
            'refund_priority' => __('sirsoft-ecommerce::validation.request_attributes.refund_priority'),
            'refund_bank' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank'),
            'refund_bank.bank_code' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_code'),
            'refund_bank.account_number' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_account'),
            'refund_bank.holder' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_holder'),
            'image_ids' => __('sirsoft-ecommerce::validation.request_attributes.image_ids'),
            'image_ids.*' => __('sirsoft-ecommerce::validation.request_attributes.image_id'),
        ];
    }

    /**
     * 추가 검증 로직 — 주문 존재·소유권 확인 후 신청 상품의 주문 소속 검사
     *
     * 소유권 404 가 상품 소속 422 보다 먼저 나야 하므로 순서를 바꾸지 않는다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->assertOrderOwnership();
            $order->loadMissing('options');

            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! $order->options->contains('id', (int) ($item['order_option_id'] ?? 0))) {
                    $validator->errors()->add(
                        "items.{$index}.order_option_id",
                        __('sirsoft-ecommerce::exceptions.claim_option_not_found')
                    );
                }
            }

            // 환불 계좌는 세 필드가 함께 와야 한다 — 하나만 오면 무통장 환불이 계좌 없이 승인된다.
            if (! $this->validateRefundBankComplete($validator)) {
                return;
            }

            // 가린 값이 섞인 계좌는 등록 계좌를 가린 모양 그대로가 아니면 받지 않는다 — 별표째 저장된다.
            if (! $this->validateRefundBankNotMasked($validator, $order)) {
                return;
            }

            // PG·수단이 환불받을 계좌를 요구하는 주문은 **접수에서** 막는다 (S1-D8 · T2-D5).
            // 막지 않으면 계좌 없이 접수·승인까지 통과한 뒤 마지막 [환불하기] 에서 PG 가
            // `MISSING_REFUND_ACCOUNT` 로 거절하거나(가상계좌) 송금 없이 장부만 닫힌다(무통장) —
            // 그때는 이미 승인된 신청서라 **운영자가 되돌릴 수 없다**. 판정은 반품·교환·수정과
            // 같은 한 곳(`ValidatesClaimRefundBank`)이다.
            $this->validateRefundBankPresent($validator, $order);
        });
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }

    /**
     * 서비스에 넘길 신청 페이로드를 반환합니다.
     *
     * @return array 신청 페이로드
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();

        return [
            'items' => $validated['items'],
            'reason_code' => $validated['reason_code'],
            'reason_detail' => $validated['reason_detail'] ?? null,
            'refund_priority' => isset($validated['refund_priority'])
                ? RefundPriorityEnum::from($validated['refund_priority'])
                : RefundPriorityEnum::PG_FIRST,
            'refund_bank' => $this->refundBankPayload($validated['refund_bank'] ?? null, $this->getOrder()),
            'requested_by' => $this->requesterId(),
        ];
    }

    /**
     * 귀속할 미귀속 첨부 ID 목록을 반환합니다.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public function imageIds(): array
    {
        return array_map('intval', (array) ($this->validated('image_ids') ?? []));
    }

    /**
     * 첨부 장수 상한을 반환합니다. (설정 단일 출처)
     *
     * 리터럴을 두면 운영자가 `claim.max_images` 를 바꿨을 때 화면 안내와 실제 거절선이 갈린다 —
     * 상한을 낮추면 요청 게이트가 놓치고 서비스가 뒤늦게 거절하고, 올리면 게이트가 먼저 막는다.
     *
     * @return int 첨부 장수 상한
     */
    protected function maxImages(): int
    {
        return app(ClaimAttachmentService::class)->maxImages();
    }
}
