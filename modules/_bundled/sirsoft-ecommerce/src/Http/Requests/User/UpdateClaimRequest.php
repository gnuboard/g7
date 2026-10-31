<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesClaimRefundBank;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Rules\ExchangeRedeliveryCountryUnchanged;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Support\RefundBankMask;

/**
 * 클레임 신청 수정 요청 (D13 — 승인 전 사유·상세 사유·첨부 / D14 — 교환 재발송지)
 *
 * **접수(`Store*Request`)와 같은 강도**로 본다. 예전에는 사유를 `max:30` 문자열로만 받아 다른 유형의
 * 사유·비활성 사유·화면에 노출되지 않는 판매자 귀책 사유로 바꿀 수 있었고(귀책이 바뀌면 배송비
 * 부담이 뒤집힌다), 재발송지 국가 불변과 계좌 필수 판정도 없었다 — 접수에서 막는 값이 수정으로는
 * 들어갔다.
 *
 * 그러려면 신청서의 유형과 주문을 알아야 한다. 그래서 이 요청이 클레임 번호를 열람자(회원 본인 /
 * 비회원 조회 토큰의 주문)의 것으로 해석한다(`UserClaimService::findOwned()` — 컨트롤러와 같은
 * 판정). 남의 신청서면 규칙을 세우기 전에 404 로 끝낸다 — 유형별 검증 결과로 남의 신청서의 존재가
 * 새어 나가지 않게 한다.
 */
class UpdateClaimRequest extends FormRequest
{
    use ValidatesClaimRefundBank;

    /**
     * 해석된 신청서 (1회 조회 후 보관)
     */
    protected OrderExchange|OrderReturn|OrderRefund|OrderCancel|null $claim = null;

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
        $claim = $this->ownedClaim();
        $isExchange = $claim instanceof OrderExchange;
        $hasChargeAxis = $claim instanceof OrderExchange || $claim instanceof OrderReturn;

        return [
            // 사유는 접수와 같은 카탈로그 게이트 — 그 신청서 유형의 활성·고객 선택 가능 사유만.
            'reason_code' => [
                'nullable',
                'string',
                'max:30',
                Rule::exists(ClaimReason::class, 'code')
                    ->where('type', $this->reasonCatalogType($claim))
                    ->where('is_active', true)
                    ->where('is_user_selectable', true),
            ],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            // 청구 방식은 교환·반품에만 있다 — 환불신청서에 실리면 조용히 버리지 않고 거절한다.
            'charge_method' => $hasChargeAxis
                ? ['nullable', 'string', Rule::in(ClaimChargeMethodEnum::values())]
                : ['prohibited'],
            'add_image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'add_image_ids.*' => ['integer'],
            'remove_image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'remove_image_ids.*' => ['integer'],
            // 재발송지는 교환에만 있다.
            'redelivery_address' => $isExchange ? ['nullable', 'array'] : ['prohibited'],
            'redelivery_address.recipient_name' => ['nullable', 'string', 'max:50'],
            'redelivery_address.phone' => ['nullable', 'string', 'max:20'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.address1' => ['nullable', 'string', 'max:255'],
            'redelivery_address.address2' => ['nullable', 'string', 'max:255'],
            'redelivery_address.city' => ['nullable', 'string', 'max:100'],
            'redelivery_address.state' => ['nullable', 'string', 'max:100'],
            'redelivery_address.country_code' => [
                'nullable',
                'string',
                'size:2',
                // 접수와 같은 가드 — 교환은 해외 재발송을 지원하지 않는다.
                new ExchangeRedeliveryCountryUnchanged(fn (): ?Order => $this->ownedClaim()?->order),
            ],
            'redelivery_address.changed_by_user' => ['nullable', 'boolean'],
            // 환불 계좌 — 접수(Store*Request)와 같은 키·같은 상한을 쓴다. 형태가 갈리면
            // 같은 값이 접수에서는 통과하고 수정에서만 거절되어, 구매자는 무엇이 잘못됐는지
            // 알 수 없다.
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 추가 검증 규칙을 적용합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // 환불 계좌는 세 필드가 함께 와야 한다 — 하나만 오면 은행만 바뀌고 계좌번호는
            // 옛 값으로 남아, 운영자가 존재하지 않는 계좌로 이체를 시도한다. 접수 경로와 같은 등식이다.
            if (! $this->validateRefundBankComplete($validator)) {
                return;
            }

            $claim = $this->ownedClaim();
            $order = $claim?->order;

            // 가린 값이 섞인 계좌는 원문으로 되돌릴 대상(주문 등록 계좌 · 이 신청서에 적힌 계좌를 가린
            // 모양 그대로)이 아니면 받지 않는다 — 한 칸만 고친 채 보내면 별표째 저장된다.
            if ($order !== null && ! $this->validateRefundBankNotMasked($validator, $order, $this->claimBankOriginal($claim))) {
                return;
            }

            // 수정 뒤에도 신청서에 보낼 계좌가 남는지 본다 — 이미 적혀 있으면 그것으로 충분하고,
            // 교환은 돌려줄 차액이 있을 때만 계좌가 필요하다(접수와 같은 조건).
            if ($order === null || $claim instanceof OrderCancel || filled($claim->refund_bank_code)) {
                return;
            }

            if ($claim instanceof OrderExchange && (float) $claim->refund_product_amount <= 0) {
                return;
            }

            $this->validateRefundBankPresent($validator, $order);
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
            'reason_code.exists' => __('sirsoft-ecommerce::validation.claims.reason_code.exists'),
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'charge_method.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'add_image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'add_image_ids.max' => trans_choice('sirsoft-ecommerce::validation.claims.image_ids.max', $maxImages = $this->maxImages(), ['max' => $maxImages]),
            'remove_image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'redelivery_address.array' => __('sirsoft-ecommerce::validation.claims.redelivery_address.array'),
            'redelivery_address.prohibited' => __('sirsoft-ecommerce::validation.claims.amounts.prohibited'),
            'redelivery_address.country_code.size' => __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_size'),
            'redelivery_address.changed_by_user.boolean' => __('sirsoft-ecommerce::validation.claims.redelivery_address.changed_by_user_boolean'),
            'refund_bank.array' => __('sirsoft-ecommerce::validation.claims.refund_bank.array'),
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
            'reason_code' => __('sirsoft-ecommerce::validation.request_attributes.reason_code'),
            'reason_detail' => __('sirsoft-ecommerce::validation.request_attributes.reason_detail'),
            'charge_method' => __('sirsoft-ecommerce::validation.request_attributes.charge_method'),
            'add_image_ids' => __('sirsoft-ecommerce::validation.request_attributes.add_image_ids'),
            'add_image_ids.*' => __('sirsoft-ecommerce::validation.request_attributes.image_id'),
            'remove_image_ids' => __('sirsoft-ecommerce::validation.request_attributes.remove_image_ids'),
            'remove_image_ids.*' => __('sirsoft-ecommerce::validation.request_attributes.image_id'),
            'redelivery_address' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address'),
            'redelivery_address.recipient_name' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_recipient_name'),
            'redelivery_address.phone' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_phone'),
            'redelivery_address.zipcode' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_zipcode'),
            'redelivery_address.address1' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address1'),
            'redelivery_address.address2' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address2'),
            'redelivery_address.city' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_city'),
            'redelivery_address.state' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_state'),
            'redelivery_address.country_code' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_country_code'),
            'redelivery_address.changed_by_user' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_changed_by_user'),
            'refund_bank' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank'),
            'refund_bank.bank_code' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_code'),
            'refund_bank.account_number' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_account'),
            'refund_bank.holder' => __('sirsoft-ecommerce::validation.request_attributes.refund_bank_holder'),
        ];
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
     * 서비스에 넘길 수정 페이로드를 반환합니다. (전송된 키만)
     *
     * @return array 수정 페이로드
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();
        $payload = [];

        foreach (['reason_code', 'reason_detail', 'charge_method', 'redelivery_address'] as $key) {
            if (array_key_exists($key, $validated)) {
                $payload[$key] = $validated[$key];
            }
        }

        // 환불 계좌는 세 값이 다 찬 경우에만 싣는다. 빈 채로 실으면 구매자가 손대지 않은
        // 화면에서 저장했을 때 이미 기록된 계좌가 지워진다.
        //
        // 표시 응답의 계좌는 가린 값이라, 수정 화면이 그 값을 그대로 되돌려 보낼 수 있다. 신청서에
        // 적힌 계좌를 가린 모양과 같으면 **바꾸지 않은 것**이므로 싣지 않는다 — 실으면 별표가 섞인
        // 계좌번호가 저장된다. 등록 계좌를 가린 모양이면 원문으로 되돌린다(`refundBankPayload()`).
        $claim = $this->ownedClaim();
        $bank = $validated['refund_bank'] ?? null;

        if (is_array($bank) && $claim !== null && ! $this->isUnchangedClaimBank($bank, $claim)) {
            $restored = $claim->order ? $this->refundBankPayload($bank, $claim->order) : null;

            if ($restored !== null) {
                $payload['refund_bank'] = $restored;
            }
        }

        return $payload;
    }

    /**
     * 귀속할 첨부 ID 목록을 반환합니다.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public function addImageIds(): array
    {
        return array_map('intval', (array) ($this->validated('add_image_ids') ?? []));
    }

    /**
     * 삭제할 첨부 ID 목록을 반환합니다.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public function removeImageIds(): array
    {
        return array_map('intval', (array) ($this->validated('remove_image_ids') ?? []));
    }

    /**
     * 열람자의 것으로 확인된 신청서를 반환합니다. (부재·남의 것이면 404 로 끝낸다)
     *
     * 열람자는 컨트롤러와 같은 기준이다 — 비회원 조회 토큰이 검증한 주문(`guest_order`)이 있으면 그
     * 주문, 없으면 로그인 회원.
     *
     * @return OrderExchange|OrderReturn|OrderRefund|OrderCancel|null 신청서 (라우트에 번호가 없으면 null)
     */
    protected function ownedClaim(): OrderExchange|OrderReturn|OrderRefund|OrderCancel|null
    {
        if ($this->claim !== null) {
            return $this->claim;
        }

        $claimNumber = (string) $this->route('claimNumber');

        if ($claimNumber === '') {
            return null;
        }

        $guestOrder = $this->attributes->get('guest_order');

        try {
            $resolved = app(UserClaimService::class)->findOwned(
                $claimNumber,
                $guestOrder instanceof Order ? null : Auth::id(),
                $guestOrder instanceof Order ? $guestOrder : null,
            );
        } catch (OrderClaimException) {
            abort(ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.claim_not_found', 404));
        }

        $resolved['claim']->loadMissing('order');

        return $this->claim = $resolved['claim'];
    }

    /**
     * 신청서 유형의 사유 카탈로그 유형을 반환합니다.
     *
     * 환불신청서는 스스로 카탈로그를 안다(`OrderRefund::reasonCatalogType()`) — 구매자가 고칠 수 있는
     * 환불신청서는 직접 신청 건뿐이라 환불 카탈로그다. 취소신청서는 수정 대상이 아니지만(컨트롤러가
     * 거절한다) 규칙을 세울 수 있게 환불 카탈로그를 쓴다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel|null  $claim  신청서
     * @return string 카탈로그 유형
     */
    protected function reasonCatalogType(OrderExchange|OrderReturn|OrderRefund|OrderCancel|null $claim): string
    {
        return match (true) {
            $claim instanceof OrderExchange => ClaimTypeEnum::EXCHANGE->value,
            $claim instanceof OrderReturn => ClaimTypeEnum::RETURN->value,
            $claim instanceof OrderRefund => $claim->reasonCatalogType(),
            default => ClaimTypeEnum::REFUND->value,
        };
    }

    /**
     * 신청서에 이미 적힌 환불 계좌 원문을 반환합니다. (세 값이 모두 있을 때만)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel|null  $claim  신청서
     * @return array{bank_code: string, account_number: string, holder: string}|null 신청서 계좌
     */
    protected function claimBankOriginal(OrderExchange|OrderReturn|OrderRefund|OrderCancel|null $claim): ?array
    {
        if ($claim === null || $claim instanceof OrderCancel
            || ! filled($claim->refund_bank_code) || ! filled($claim->refund_bank_account) || ! filled($claim->refund_bank_holder)) {
            return null;
        }

        return [
            'bank_code' => (string) $claim->refund_bank_code,
            'account_number' => (string) $claim->refund_bank_account,
            'holder' => (string) $claim->refund_bank_holder,
        ];
    }

    /**
     * 제출된 계좌가 신청서에 이미 적힌 계좌를 가린 모양 그대로인지 반환합니다.
     *
     * @param  array  $bank  제출된 계좌
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return bool 바꾸지 않았으면 true
     */
    protected function isUnchangedClaimBank(array $bank, OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): bool
    {
        if ($claim instanceof OrderCancel || ! filled($claim->refund_bank_code)) {
            return false;
        }

        $current = [
            'bank_code' => (string) $claim->refund_bank_code,
            'account_number' => (string) $claim->refund_bank_account,
            'holder' => (string) $claim->refund_bank_holder,
        ];

        // 원문 그대로 보낸 경우도, 가린 모양 그대로 보낸 경우도 "바꾸지 않았다" 다.
        $submitted = [
            'bank_code' => (string) ($bank['bank_code'] ?? ''),
            'account_number' => (string) ($bank['account_number'] ?? ''),
            'holder' => (string) ($bank['holder'] ?? ''),
        ];

        return $submitted === $current || RefundBankMask::restoreRegistered($submitted, $current) === $current;
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
