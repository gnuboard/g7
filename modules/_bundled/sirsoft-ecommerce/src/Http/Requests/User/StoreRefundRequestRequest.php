<?php

// audit:allow api-doc-coverage reason: 접수 시 환불 계좌 요건(T2-D5)은 docs/api/claims.md 「접수 시 환불 계좌 요건」 절에 422 키·문구·판정 술어까지 적었다. 생성 문서에는 반영할 표면 변화가 없다 — 라우트·요청 키·응답 형태가 모두 그대로이고 추가된 것은 기존 `refund_bank` 키의 필수 조건뿐이다(`api:docgen --scope=module:sirsoft-ecommerce --check` drift 0 확인, 2026-09-23).

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimOrder;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;

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

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
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
            'image_ids.max' => __('sirsoft-ecommerce::validation.claims.image_ids.max'),
            'image_ids.*.integer' => __('sirsoft-ecommerce::validation.claims.image_ids.integer'),
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
            $bank = (array) $this->input('refund_bank', []);
            $filled = array_filter(['bank_code', 'account_number', 'holder'], fn (string $f) => filled($bank[$f] ?? null));

            if ($filled !== [] && count($filled) !== 3) {
                $validator->errors()->add('refund_bank', __('sirsoft-ecommerce::validation.claims.refund_bank.incomplete'));

                return;
            }

            // PG·수단이 환불받을 계좌를 요구하는 주문은 **접수에서** 막는다 (S1-D8).
            // 막지 않으면 계좌 없이 접수·승인까지 통과한 뒤 마지막 [환불하기] 에서 PG 가
            // `MISSING_REFUND_ACCOUNT` 로 거절한다 — 그때는 이미 승인된 신청서라 화면에
            // 계좌를 넣을 자리가 없어 **운영자가 되돌릴 수 없다**.
            // 판정은 신청 화면이 계좌 칸을 세울 때 쓰는 것과 **같은 술어**다
            // (OrderRefundRequestService::refundsToBankAccount).
            $this->validateRefundBankRequired($validator, $order, $bank);
        });
    }

    /**
     * 환불받을 계좌가 필요한 주문인데 비어 있으면 접수를 막습니다. (S1-D8)
     *
     * **돈이 계좌로 돌아가는 주문 전부**에 건다 — 사람이 송금하는 수단(무통장입금 등)과
     * 입금이 끝난 가상계좌 둘 다다. 판정은 `OrderRefundRequestService::refundsToBankAccount()`
     * 하나뿐이다 — 신청 화면이 계좌 칸을 세울지 정할 때 쓰는 값(`refund_account.required`)과
     * 같은 술어여서, 「칸은 없는데 서버가 막는다」·「칸은 있는데 빈 채로 통과한다」가 생기지 않는다.
     *
     * 예전에는 가상계좌에만 걸었다. 무통장은 「운영자가 승인 전에 계좌를 채운다」는 전제였는데
     * **그 입력란이 화면에 없어서** 계좌가 빈 채로 승인·[환불하기]를 지나 송금 없이 장부만
     * `completed` 로 닫혔다(2026-09-22 #599 3차 실측 L-8 · T2-D5).
     *
     * 주문 시점에 환불계좌를 넣어 둔 주문(`order_payments.refund_bank_code`)은 그것을 쓴다.
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  대상 주문
     * @param  array  $bank  요청에 실린 환불 계좌
     * @return void
     */
    protected function validateRefundBankRequired(Validator $validator, $order, array $bank): void
    {
        $payment = $order->payment;

        if (! $payment) {
            return;
        }

        // **돈이 계좌로 돌아가는 주문은 계좌 없이 접수하지 않는다.** 두 축이다 —
        // ⓐ 사람이 송금하는 수단(`refundMethod() === BANK`, 무통장입금 등)
        // ⓑ PG 가 환불받을 계좌를 요구하는 수단(입금완료된 가상계좌)
        //
        // 예전에는 ⓑ 만 막았다. 무통장은 「운영자가 승인 전에 계좌를 채워 넣을 수 있다」는 전제로
        // 접수를 받았는데, **그 자리가 화면에 없었다** — 클레임 상세에도 주문 상세에도 계좌 입력
        // 요소가 하나도 없다. 그래서 계좌 3칸이 비어 있는 채 접수·승인을 지나 [환불하기]에서
        // 송금 없이 장부만 `completed` 로 닫혔고, 되돌릴 경로도 남지 않았다
        // (2026-09-22 #599 3차 실측 L-8 · T2-D5·D6).
        //
        // 수단 코드를 여기서 다시 열거하지 않는다. 같은 규칙을 세 벌(이 요청 · 화면에 내려 주는
        // `refund_account.required` · 관리자 [주문취소])로 두면 확장 결제수단이 늘 때 판정이
        // 갈리고, 어긋난 쪽은 오류 없이 「계좌 칸 없는 계좌 환불」 또는 「쓸모없는 계좌 요구」가 된다.
        if (! app(OrderRefundRequestService::class)->refundsToBankAccount($order)) {
            return;
        }

        // 주문 시점에 넣어 둔 계좌가 있으면 그것으로 충분하다.
        if (filled($payment->refund_bank_code)) {
            return;
        }

        if (filled($bank['bank_code'] ?? null)) {
            return;
        }

        // 사유가 수단마다 다르다 — 「사람이 송금해야 한다」와 「가상계좌라서 PG 가 요구한다」는
        // 구매자가 할 일은 같아도 왜 요구받는지가 달라, 한 문구로 뭉뚱그리면 카드로 결제한 줄
        // 알았던 사람이 이유를 납득하지 못한다. 관리자 [주문취소]가 쓰는 문구 두 벌을 그대로
        // 쓴다 — 같은 상황에 두 화면이 다른 말을 하지 않게 한다.
        $messageKey = $payment->refundMethod() === RefundMethodEnum::BANK
            ? 'sirsoft-ecommerce::validation.order.refund_bank_required_for_manual_transfer'
            : 'sirsoft-ecommerce::validation.order.refund_bank_required_for_vbank';

        $validator->errors()->add('refund_bank', __($messageKey));
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
        $bank = $validated['refund_bank'] ?? null;
        $hasBank = is_array($bank) && filled($bank['bank_code'] ?? null) && filled($bank['account_number'] ?? null) && filled($bank['holder'] ?? null);

        return [
            'items' => $validated['items'],
            'reason_code' => $validated['reason_code'],
            'reason_detail' => $validated['reason_detail'] ?? null,
            'refund_priority' => isset($validated['refund_priority'])
                ? RefundPriorityEnum::from($validated['refund_priority'])
                : RefundPriorityEnum::PG_FIRST,
            'refund_bank' => $hasBank ? [
                'bank_code' => (string) $bank['bank_code'],
                'account_number' => (string) $bank['account_number'],
                'holder' => (string) $bank['holder'],
            ] : null,
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
