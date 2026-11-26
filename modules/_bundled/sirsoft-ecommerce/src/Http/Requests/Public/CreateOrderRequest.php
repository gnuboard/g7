<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Fields\OrderContactFields;
use Modules\Sirsoft\Ecommerce\Rules\CashReceiptIdentifier;
use Modules\Sirsoft\Ecommerce\Services\CheckoutPaymentMethodFilter;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;

/**
 * 주문 생성 (결제하기) 요청
 *
 * 임시 주문을 실제 주문으로 변환합니다.
 *
 * 규칙은 「고정 규칙 + 주문서 항목 집합의 규칙」이다. 주문자 이름과 연락처, 수령인, 배송 주소, 결제 정보는 고정 규칙이고
 * 주문자 이메일, 상세 주소, 배송 메모와 추가 항목(`extra_fields.{키}`)은 운영자가 정한 사용과 필수를 따른다
 * ({@see OrderContactFields}). 꺼진 항목은 규칙에서 빠지고 요청에 실려 와도 저장하지 않는다.
 */
class CreateOrderRequest extends FormRequest
{
    /**
     * 이 요청에서 계산한 주문서 기본 항목의 사용과 필수 (한 번만 계산한다).
     *
     * @var array<string, array{enabled: bool, required: bool}>|null
     */
    private ?array $orderFieldSettings = null;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
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
        $rules = [
            // 주문자 정보 (이메일은 주문서 항목 설정을 따른다. 비회원은 늘 필수)
            'orderer.name' => 'required|string|max:50',
            'orderer.phone' => 'required|string|max:20',

            // 배송지 정보
            'shipping.recipient_name' => 'required|string|max:50',
            'shipping.recipient_phone' => 'required_without:shipping.recipient_tel|nullable|string|max:20',
            'shipping.recipient_tel' => 'required_without:shipping.recipient_phone|nullable|string|max:20',
            'shipping.country_code' => 'nullable|string|size:2',

            // 국내 배송 주소 (국내인 경우 필수)
            'shipping.zipcode' => 'required_without:shipping.intl_postal_code|nullable|string|max:10',
            'shipping.address' => 'required_without:shipping.address_line_1|nullable|string|max:255',
            // 상세주소(shipping.address_detail)는 주문서 항목 설정을 따른다. 국내 폼에만 있어, 필수일 때도
            // 기본 주소(address)와 같은 짝(해외 주소 1행이 없을 때만 필수)으로 판정한다.
            'shipping.address_type_code' => 'nullable|string|in:R,J',

            // 해외 배송 주소 (해외인 경우 필수)
            'shipping.address_line_1' => 'required_without:shipping.address|nullable|string|max:255',
            'shipping.address_line_2' => 'nullable|string|max:255',
            'shipping.intl_city' => 'required_with:shipping.address_line_1|nullable|string|max:100',
            'shipping.intl_state' => 'nullable|string|max:100',
            'shipping.intl_postal_code' => 'required_with:shipping.address_line_1|nullable|string|max:20',

            // 결제 정보
            // 결제수단 화이트리스트는 카탈로그(builtin 8종 + 플러그인 등록 확장수단)가 SSoT.
            // enum cases 로 제한하면 확장 결제수단(간편결제)이 422 로 막혀, 프론트가
            // payment_method 를 'card' 로 위장해 보낼 수밖에 없게 된다.
            'payment_method' => [
                'required',
                'string',
                Rule::in(app(PaymentMethodResolver::class)->allValidIds()),
                // 지금 주문을 받을 수 있는 수단인지 별도 판정 (A2 / 공개 #111 서버 대칭).
                // 공급 확장이 사라진 수단, 지정 PG 가 사라진 수단은 화이트리스트를 통과하지만
                // 주문하면 PG 라우팅이 매칭에 실패해 결제창 없이 주문완료로 넘어간다.
                // 화이트리스트(allValidIds) 자체는 조이지 않는다 — 과거 주문 목록 필터가
                // 같은 목록을 쓰므로, 조이면 예전 수단으로 결제한 주문을 조회할 수 없게 된다.
                function (string $attribute, mixed $value, callable $fail) {
                    if (is_string($value) && ! app(PaymentMethodResolver::class)->isOrderable($value)) {
                        $fail(__('sirsoft-ecommerce::validation.order.payment_method_unavailable'));
                    }
                },
                // 고객이 직접 고를 수 없는 수단 차단 (P10 — `point`/`deposit`/`free`).
                //
                // 코어에는 이 수단들의 **결제 실행 경로가 없다**. 실결제액이 0보다 큰 주문에
                // 고르면 주문은 `PENDING_ORDER` 에 영구 정박한다 — 결제도 취소도 되지 않는
                // 주문이 남고, 어떤 화면에도 그 이유가 뜨지 않는다. 화이트리스트
                // (`allValidIds`)는 조이지 않는다: 과거 주문 목록 필터가 같은 목록을 쓰므로
                // 조이면 예전 수단으로 결제한 주문을 조회할 수 없다.
                //
                // `free` 만 예외다 — **결제할 것이 없는 0원 주문**은 시스템이 이 수단을 고르고
                // 그 경로는 정상 동작한다. 그래서 금액이 0 인 요청에서만 통과시킨다.
                function (string $attribute, mixed $value, callable $fail) {
                    if (! is_string($value) || app(PaymentMethodResolver::class)->isCustomerSelectable($value)) {
                        return;
                    }

                    $isFreeZeroAmountOrder = $value === PaymentMethodEnum::FREE->value
                        && (float) $this->input('expected_total_amount', 0) <= 0.0;

                    if ($isFreeZeroAmountOrder) {
                        return;
                    }

                    $fail(__('sirsoft-ecommerce::validation.order.payment_method_not_selectable'));
                },
                // 결제 통화에서 고를 수 없는 수단 차단. 주문서 표시가 쓰는 판정
                // (CheckoutPaymentMethodFilter, 체크아웃 응답 `allowed_payment_methods`)과 같다 — 표시만 거르면
                // 직접 POST 로 우회된다. 통화는 주문 생성과 같은 규칙(회원 영속 통화 → X-Currency → 기본 통화)으로 구한다.
                function (string $attribute, mixed $value, callable $fail) {
                    if (! is_string($value)) {
                        return;
                    }

                    $filter = app(CheckoutPaymentMethodFilter::class);
                    $currency = $filter->currentCurrency();
                    if (! $filter->isAllowed($value, $currency)) {
                        $fail(__('sirsoft-ecommerce::validation.order.payment_method_not_allowed_for_currency', ['currency' => $currency]));
                    }
                },
            ],
            'expected_total_amount' => 'required|numeric|min:0',

            // 무통장입금 (vbank/dbank) 공통
            'depositor_name' => 'required_if:payment_method,vbank|required_if:payment_method,dbank|nullable|string|max:50',

            // 수동 무통장입금 (dbank) 전용
            'dbank.bank_code' => 'required_if:payment_method,dbank|nullable|string|max:10',
            'dbank.bank_name' => 'nullable|string|max:50',
            'dbank.account_number' => 'required_if:payment_method,dbank|nullable|string|max:50',
            'dbank.account_holder' => 'required_if:payment_method,dbank|nullable|string|max:50',

            // 배송지 저장
            'save_shipping_address' => 'nullable|boolean',
            // 주문서에서 고른 배송지를 «그 자리에서 고쳤을 때» 그 행을 갱신한다. 소유권은
            // 컨트롤러가 확인한다 — 여기서는 형식만 본다(남의 행 ID 를 실어도 갱신되지 않는다).
            'save_shipping_address_id' => 'nullable|integer|min:1',

            // 현금영수증 신청 (무통장입금 전용 — 신청 시에만 하위 3키 필수)
            'cash_receipt_requested' => 'nullable|boolean',
            'cash_receipt_type' => [
                'required_if:cash_receipt_requested,true',
                'nullable',
                'string',
                Rule::in(CashReceiptType::values()),
            ],
            'cash_receipt_identifier_type' => [
                'required_if:cash_receipt_requested,true',
                'nullable',
                'string',
                Rule::in(CashReceiptIdentifierType::values()),
            ],
            'cash_receipt_identifier' => [
                'required_if:cash_receipt_requested,true',
                'nullable',
                'string',
                'max:30',
                new CashReceiptIdentifier($this->resolveCashReceiptType(), $this->resolveCashReceiptIdentifierType()),
            ],

            // 환불 계좌 (선택) — 부분 입력 금지는 withValidator 의 required_with 로 처리
            'refund_bank.bank_code' => 'nullable|string|max:10',
            'refund_bank.account_number' => 'nullable|string|max:50',
            'refund_bank.holder' => 'nullable|string|max:50',
        ];

        // 주문서 항목 집합의 규칙: 주문자 이메일, 상세 주소, 배송 메모(운영자가 정한 사용과 필수)와 추가 항목.
        // 비회원의 주문자 이메일은 알림 수신 통로가 이메일뿐이라 설정과 무관하게 필수다.
        $rules = array_merge($rules, app(OrderContactFields::class)->rules($this->orderActor()));

        // 비회원 주문일 때만 조회 비밀번호 규칙 추가 (회원은 미요구)
        $rules = array_merge($rules, $this->getGuestLookupRules());

        return HookManager::applyFilters('sirsoft-ecommerce.order.create_validation_rules', $rules, $this);
    }

    /**
     * 검증기 확장 — 환불 계좌의 부분 입력을 거부합니다.
     *
     * 3필드는 모두 선택 입력이지만 일부만 채우면 환불 자체가 불가능합니다.
     * 계좌번호만 있고 예금주가 없으면 PG 환불 API 가 거부하고, 무통장은 관리자가 이체할 수 없습니다.
     * 따라서 "전부 비었거나 전부 채워졌거나" 둘 중 하나만 허용합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = ['bank_code', 'account_number', 'holder'];

            $filled = array_filter(
                $fields,
                fn (string $field): bool => filled($this->input("refund_bank.{$field}"))
            );

            // 전부 비었으면 미입력 (허용), 전부 채워졌으면 완전 입력 (허용)
            if ($filled === [] || count($filled) === count($fields)) {
                return;
            }

            foreach (array_diff($fields, $filled) as $missing) {
                $validator->errors()->add(
                    "refund_bank.{$missing}",
                    __('sirsoft-ecommerce::validation.order.refund_bank_required_with')
                );
            }
        });
    }

    /**
     * 비회원 주문 조회 비밀번호 검증 규칙을 반환합니다.
     *
     * 로그인 사용자(회원)는 조회 비밀번호가 필요 없으므로 nullable 로 유지하고,
     * 비로그인 사용자(비회원)에게만 8자 이상 + 확인 일치를 강제합니다 (G7 회원가입 정책과 일치).
     * 실제 해시 저장은 후속 단계(주문 생성 시점)에서 처리합니다.
     *
     * @return array<string, mixed>
     */
    protected function getGuestLookupRules(): array
    {
        // 로그인 사용자는 회원 주문 → 조회 비밀번호 미요구
        if ($this->user()) {
            return [
                'guest_lookup_password' => ['nullable'],
                'guest_lookup_password_confirmation' => ['nullable'],
            ];
        }

        // 비회원 주문 → 8자 이상, 확인 일치 필수 (G7 회원가입 정책과 일치 — RegisterRequest 의 min:8|confirmed)
        return [
            'guest_lookup_password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'guest_lookup_password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 검증 메시지 배열
     */
    public function messages(): array
    {
        return [
            // 운영자가 추가 항목에 적은 형식 오류 문구
            ...app(OrderContactFields::class)->messages($this->orderActor()),

            // 주문자 정보
            'orderer.name.required' => __('sirsoft-ecommerce::validation.order.orderer_name_required'),
            'orderer.phone.required' => __('sirsoft-ecommerce::validation.order.orderer_phone_required'),
            'orderer.email.required' => __('sirsoft-ecommerce::validation.order.orderer_email_required'),
            'orderer.email.email' => __('sirsoft-ecommerce::validation.order.orderer_email_invalid'),

            // 배송지 정보
            'shipping.recipient_name.required' => __('sirsoft-ecommerce::validation.order.recipient_name_required'),
            'shipping.recipient_phone.required_without' => __('sirsoft-ecommerce::validation.order.recipient_phone_required_without'),
            'shipping.recipient_tel.required_without' => __('sirsoft-ecommerce::validation.order.recipient_tel_required_without'),
            'shipping.zipcode.required_without' => __('sirsoft-ecommerce::validation.order.zipcode_required'),
            'shipping.address.required_without' => __('sirsoft-ecommerce::validation.order.address_required'),
            'shipping.address_detail.required_without' => __('sirsoft-ecommerce::validation.order.address_detail_required'),
            'shipping.address_line_1.required_without' => __('sirsoft-ecommerce::validation.order.address_line_1_required'),
            'shipping.intl_city.required_with' => __('sirsoft-ecommerce::validation.order.intl_city_required'),
            'shipping.intl_postal_code.required_with' => __('sirsoft-ecommerce::validation.order.intl_postal_code_required'),

            // 결제 정보
            'payment_method.required' => __('sirsoft-ecommerce::validation.order.payment_method_required'),
            'payment_method.in' => __('sirsoft-ecommerce::validation.order.payment_method_invalid'),
            'expected_total_amount.required' => __('sirsoft-ecommerce::validation.order.expected_total_amount_required'),
            'expected_total_amount.numeric' => __('sirsoft-ecommerce::validation.order.expected_total_amount_numeric'),

            // 무통장입금
            'depositor_name.required_if' => __('sirsoft-ecommerce::validation.order.depositor_name_required'),
            'dbank.bank_code.required_if' => __('sirsoft-ecommerce::validation.order.dbank_bank_code_required'),
            'dbank.account_number.required_if' => __('sirsoft-ecommerce::validation.order.dbank_account_number_required'),
            'dbank.account_holder.required_if' => __('sirsoft-ecommerce::validation.order.dbank_account_holder_required'),

            // 현금영수증 신청
            'cash_receipt_type.required_if' => __('sirsoft-ecommerce::validation.order.cash_receipt_type_required'),
            'cash_receipt_type.in' => __('sirsoft-ecommerce::validation.order.cash_receipt_type_invalid'),
            'cash_receipt_identifier_type.required_if' => __('sirsoft-ecommerce::validation.order.cash_receipt_identifier_type_required'),
            'cash_receipt_identifier_type.in' => __('sirsoft-ecommerce::validation.order.cash_receipt_identifier_type_invalid'),
            'cash_receipt_identifier.required_if' => __('sirsoft-ecommerce::validation.order.cash_receipt_identifier_required'),

            // 비회원 조회 비밀번호
            'guest_lookup_password.required' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_required'),
            'guest_lookup_password.min' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_min'),
            'guest_lookup_password.confirmed' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_confirmed'),
            'guest_lookup_password_confirmation.required' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_confirmation_required'),
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
            // 추가 항목의 이름 (오류 문구가 `extra_fields.{키}` 대신 운영자가 적은 이름으로 나온다)
            ...app(OrderContactFields::class)->attributes($this->orderActor()),
            'save_shipping_address_id' => __('sirsoft-ecommerce::validation.request_attributes.save_shipping_address'),
        ];
    }

    /**
     * 주문자 정보 반환
     *
     * 운영자가 주문자 이메일 항목을 껐으면 요청에 실려 와도 저장하지 않는다(빈 값으로 돌려준다).
     *
     * @return array{name: string, phone: string, email: string} 주문자 정보
     */
    public function getOrdererInfo(): array
    {
        $orderer = $this->input('orderer', []);

        return [
            'name' => $orderer['name'] ?? '',
            'phone' => $orderer['phone'] ?? '',
            'email' => $this->orderFieldEnabled('orderer_email') ? ($orderer['email'] ?? '') : '',
        ];
    }

    /**
     * 배송지 정보 반환
     *
     * 운영자가 상세 주소 항목을 껐으면 요청에 실려 와도 저장하지 않는다(키를 뺀다).
     *
     * @return array<string, mixed> 배송지 입력값 배열
     */
    public function getShippingInfo(): array
    {
        $shipping = (array) $this->input('shipping', []);

        if (! $this->orderFieldEnabled('address_detail')) {
            unset($shipping['address_detail'], $shipping['detail_address']);
        }

        return $shipping;
    }

    /**
     * 배송 메모 반환
     *
     * @return string|null 배송 메모 (운영자가 배송 메모 항목을 껐으면 요청에 실려 와도 null)
     */
    public function getShippingMemo(): ?string
    {
        if (! $this->orderFieldEnabled('shipping_memo')) {
            return null;
        }

        $memo = $this->input('shipping_memo');

        return is_string($memo) ? $memo : null;
    }

    /**
     * 추가 항목 값 반환 (운영자가 추가한 항목, 다른 확장이 기여한 항목)
     *
     * 검증을 통과한 값만 돌려준다. 규칙이 없는 키(꺼진 항목, 없는 항목)는 들어 있지 않다.
     *
     * @return array<string, mixed> 항목 키 => 값 (없으면 빈 배열)
     */
    public function getExtraFields(): array
    {
        $extra = $this->validated()[OrderContactFields::BUNDLE] ?? [];

        return is_array($extra) ? $extra : [];
    }

    /**
     * 주문하는 회원을 반환합니다.
     *
     * @return User|null 회원 (비회원 주문이면 null)
     */
    private function orderActor(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * 주문서 기본 항목(주문자 이메일, 상세 주소, 배송 메모)이 이 주문에서 쓰이는지 반환합니다.
     *
     * @param  string  $key  항목 키 (orderer_email | address_detail | shipping_memo)
     * @return bool 쓰이면 true
     */
    private function orderFieldEnabled(string $key): bool
    {
        $this->orderFieldSettings ??= app(OrderContactFields::class)->builtin($this->orderActor());

        return (bool) ($this->orderFieldSettings[$key]['enabled'] ?? true);
    }

    /**
     * 무통장 수동입금 정보 반환
     *
     * @return array<string, mixed>|null dbank 결제 시 입금 정보, 그 외 null
     */
    public function getDbankInfo(): ?array
    {
        if ($this->input('payment_method') !== PaymentMethodEnum::DBANK->value) {
            return null;
        }

        return $this->input('dbank');
    }

    /**
     * 비회원 주문 조회 비밀번호 반환 (회원 주문이면 null)
     *
     * 평문 비밀번호이며, 주문 생성 시점에 해시로 변환해 저장합니다.
     * 응답/로그에 그대로 노출하지 않습니다.
     *
     * @return string|null 비회원 조회 비밀번호 (회원이면 null)
     */
    public function getGuestLookupPassword(): ?string
    {
        if ($this->user()) {
            return null;
        }

        $password = $this->input('guest_lookup_password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    /**
     * 현금영수증 신청 정보를 반환합니다. (미신청이거나 무통장이 아니면 null)
     *
     * 식별번호는 하이픈·공백이 제거된 원본입니다. 저장 시점에 마스킹본과 암호문으로 분리되며,
     * 원본은 응답·로그에 노출하지 않습니다.
     *
     * 발급 대상은 무통장(dbank)뿐이므로(CashReceiptService 가 재차 차단) 그 외 결제수단에서는
     * 신청 정보를 반환하지 않는다. 발급될 수 없는 주문에 식별번호 암호문을 남기지 않기 위함이다.
     *
     * @return array{type: CashReceiptType, identifier_type: CashReceiptIdentifierType, identifier: string}|null 신청 정보 (미신청·비무통장 시 null)
     */
    public function getCashReceiptInfo(): ?array
    {
        if ($this->input('payment_method') !== PaymentMethodEnum::DBANK->value) {
            return null;
        }

        if (! $this->boolean('cash_receipt_requested')) {
            return null;
        }

        $type = $this->resolveCashReceiptType();
        $identifierType = $this->resolveCashReceiptIdentifierType();
        $identifier = $this->input('cash_receipt_identifier');

        // required_if 를 통과했다면 셋 다 존재한다. 방어적으로 한 번 더 확인한다.
        if ($type === null || $identifierType === null || ! is_string($identifier)) {
            return null;
        }

        return [
            'type' => $type,
            'identifier_type' => $identifierType,
            'identifier' => CashReceiptIdentifier::normalize($identifier),
        ];
    }

    /**
     * 환불 계좌 정보를 반환합니다. (미입력이면 null)
     *
     * withValidator 가 부분 입력을 거부하므로, 값이 있으면 3필드가 모두 채워져 있습니다.
     *
     * @return array{bank_code: string, account_number: string, holder: string}|null 환불 계좌 (미입력 시 null)
     */
    public function getRefundBankInfo(): ?array
    {
        // 환불 계좌가 쓰이지 않는 결제수단(카드·계좌이체·휴대폰·마일리지·예치금·무료)이면 저장하지 않는다.
        // 체크아웃 화면은 결제수단을 바꿔도 입력값을 비우지 않으므로, 무통장에서 계좌를 넣고
        // 카드로 전환하면 그 값이 그대로 전송된다. getCashReceiptInfo() 와 같은 축으로 게이팅한다.
        if (! PaymentMethodEnum::tryFrom((string) $this->input('payment_method'))?->needsRefundBankAccount()) {
            return null;
        }

        $bankCode = $this->input('refund_bank.bank_code');

        if (blank($bankCode)) {
            return null;
        }

        return [
            'bank_code' => (string) $bankCode,
            'account_number' => (string) $this->input('refund_bank.account_number'),
            'holder' => (string) $this->input('refund_bank.holder'),
        ];
    }

    /**
     * 검증 전 입력에서 현금영수증 발급 용도를 해석합니다. (Rule 생성자 주입용)
     *
     * @return CashReceiptType|null 해석된 용도 (미해석 시 null)
     */
    private function resolveCashReceiptType(): ?CashReceiptType
    {
        $value = $this->input('cash_receipt_type');

        return is_string($value) ? CashReceiptType::tryFrom($value) : null;
    }

    /**
     * 검증 전 입력에서 현금영수증 식별번호 종류를 해석합니다. (Rule 생성자 주입용)
     *
     * @return CashReceiptIdentifierType|null 해석된 종류 (미해석 시 null)
     */
    private function resolveCashReceiptIdentifierType(): ?CashReceiptIdentifierType
    {
        $value = $this->input('cash_receipt_identifier_type');

        return is_string($value) ? CashReceiptIdentifierType::tryFrom($value) : null;
    }
}
