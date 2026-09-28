<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 관리자 클레임 목록 조회 요청 (유형 서브탭 단위)
 *
 * 상태 어휘는 유형마다 다르므로(교환 14 / 반품 11 / 환불 7 / 취소 2) 유형별 Enum 을 단일
 * 출처로 게이트한다 — 유저 목록(`User\ClaimListRequest`)과 같은 근거다. 관리자 화면의 상태
 * 필터는 ChipCheckbox 다중선택(D28)이라 유저와 달리 `statuses[]` 배열이다.
 *
 * 귀책·청구 축이 없는 유형(취소는 둘 다, 환불은 청구)에 그 필터를 실으면 `prohibited` 로
 * 거절한다 — 조용히 무시하면 필터가 안 걸린 목록이 "필터 결과" 로 보인다.
 */
class ClaimListRequest extends FormRequest
{
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
        $type = ClaimTypeEnum::tryFrom($this->stringInput('type')) ?? ClaimTypeEnum::EXCHANGE;

        return [
            'type' => ['nullable', 'string', Rule::in(ClaimTypeEnum::values())],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in($this->allowedStatuses())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // 검색 드롭다운 4종 (D27) — 상세 필터에 같은 입력을 두 번 두지 않는다.
            'search_field' => ['nullable', 'in:claim_number,order_number,requester,product_name'],
            'search_keyword' => ['nullable', 'string', 'max:200'],
            'fault_types' => $type === ClaimTypeEnum::CANCEL ? ['prohibited'] : ['nullable', 'array'],
            'fault_types.*' => ['string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            'charge_methods' => in_array($type, [ClaimTypeEnum::CANCEL, ClaimTypeEnum::REFUND], true)
                ? ['prohibited']
                : ['nullable', 'array'],
            'charge_methods.*' => ['string', Rule::in(ClaimChargeMethodEnum::values())],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'requester_type' => ['nullable', 'in:member,guest'],
            // 접수 출처 (D8 — 판매자 접수 / 고객 접수). 취소는 그 축이 없어 받지 않는다.
            'request_source' => $type === ClaimTypeEnum::CANCEL ? ['prohibited'] : ['nullable', 'in:admin,customer'],
            // 선교환 반송 기한 경과 (교환 전용)
            'collection_overdue' => $type === ClaimTypeEnum::EXCHANGE ? ['nullable', 'boolean'] : ['prohibited'],
            // 환불 미접수 (D9 — 교환·반품 전용)
            'pending_refund' => in_array($type, [ClaimTypeEnum::EXCHANGE, ClaimTypeEnum::RETURN], true) ? ['nullable', 'boolean'] : ['prohibited'],
            // 금액은 목록 '금액' 컬럼이 그리는 값과 같은 축이다 — 유형마다 근거 컬럼이 다르지만
            // (교환·반품 = 청구액 / 환불·취소 = 환불액 + 환불 마일리지) 운영자에게는 한 축이다.
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            // gte 는 최소 금액이 실제로 들어왔을 때만 건다 — 무조건 걸면 "최대 금액만" 거르는
            // 정상 조합이 422 가 되고, 화면에는 직전 목록이 그대로 남는다.
            'max_amount' => array_filter([
                'nullable',
                'numeric',
                'min:0',
                $this->filled('min_amount') ? 'gte:min_amount' : null,
            ]),
            'currency_code' => $this->currencyRules($type),
            // 청구 결제수단은 추가결제가 성립하는 유형에만 있다 — 환불·취소는 청구 자체가 없다.
            'charge_payment_methods' => in_array($type, [ClaimTypeEnum::CANCEL, ClaimTypeEnum::REFUND], true)
                ? ['prohibited']
                : ['nullable', 'array'],
            'charge_payment_methods.*' => $this->chargePaymentMethodRules(),
            'page' => array_filter([
                'nullable',
                'integer',
                'min:1',
                ($maxPage = PaginationLimits::maxPage('ecommerce.claims')) !== null ? 'max:'.$maxPage : null,
            ]),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'type.in' => __('sirsoft-ecommerce::validation.claims.type.in'),
            'statuses.array' => __('sirsoft-ecommerce::validation.claims.statuses.array'),
            'statuses.*.in' => __('sirsoft-ecommerce::validation.claims.status.in'),
            'search_field.in' => __('sirsoft-ecommerce::validation.claims.search_field.in'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.claims.search_keyword.max'),
            'fault_types.array' => __('sirsoft-ecommerce::validation.claims.fault_types.array'),
            'fault_types.prohibited' => __('sirsoft-ecommerce::validation.claims.fault_types.prohibited'),
            'fault_types.*.in' => __('sirsoft-ecommerce::validation.claims.fault_types.in'),
            'charge_methods.array' => __('sirsoft-ecommerce::validation.claims.charge_methods.array'),
            'charge_methods.prohibited' => __('sirsoft-ecommerce::validation.claims.charge_methods.prohibited'),
            'charge_methods.*.in' => __('sirsoft-ecommerce::validation.claims.charge_methods.in'),
            'requester_type.in' => __('sirsoft-ecommerce::validation.claims.requester_type.in'),
            'request_source.in' => __('sirsoft-ecommerce::validation.claims.request_source.in'),
            'request_source.prohibited' => __('sirsoft-ecommerce::validation.claims.request_source.prohibited'),
            'collection_overdue.prohibited' => __('sirsoft-ecommerce::validation.claims.collection_overdue.prohibited'),
            'pending_refund.prohibited' => __('sirsoft-ecommerce::validation.claims.pending_refund.prohibited'),
            'min_amount.numeric' => __('sirsoft-ecommerce::validation.claims.min_amount.numeric'),
            'max_amount.numeric' => __('sirsoft-ecommerce::validation.claims.max_amount.numeric'),
            'max_amount.gte' => __('sirsoft-ecommerce::validation.claims.max_amount.gte'),
            'currency_code.in' => __('sirsoft-ecommerce::validation.claims.currency_code.in'),
            'charge_payment_methods.*.in' => __('sirsoft-ecommerce::validation.claims.charge_payment_methods.in'),
            'charge_payment_methods.array' => __('sirsoft-ecommerce::validation.claims.charge_payment_methods.array'),
            'charge_payment_methods.prohibited' => __('sirsoft-ecommerce::validation.claims.charge_payment_methods.prohibited'),
            'page.integer' => __('sirsoft-ecommerce::validation.orders.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.orders.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.orders.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.orders.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.orders.per_page.max'),
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
            'type' => __('sirsoft-ecommerce::validation.request_attributes.claim_type'),
            'statuses' => __('sirsoft-ecommerce::validation.request_attributes.statuses'),
            'statuses.*' => __('sirsoft-ecommerce::validation.request_attributes.status_item'),
            'from' => __('sirsoft-ecommerce::validation.request_attributes.date_from'),
            'to' => __('sirsoft-ecommerce::validation.request_attributes.date_to'),
            'search_field' => __('sirsoft-ecommerce::validation.request_attributes.search_field'),
            'search_keyword' => __('sirsoft-ecommerce::validation.request_attributes.search_keyword'),
            'fault_types' => __('sirsoft-ecommerce::validation.request_attributes.fault_types'),
            'fault_types.*' => __('sirsoft-ecommerce::validation.request_attributes.fault_type'),
            'charge_methods' => __('sirsoft-ecommerce::validation.request_attributes.charge_methods'),
            'charge_methods.*' => __('sirsoft-ecommerce::validation.request_attributes.charge_method'),
            'reason_code' => __('sirsoft-ecommerce::validation.request_attributes.reason_code'),
            'requester_type' => __('sirsoft-ecommerce::validation.request_attributes.requester_type'),
            'request_source' => __('sirsoft-ecommerce::validation.request_attributes.request_source'),
            'collection_overdue' => __('sirsoft-ecommerce::validation.request_attributes.collection_overdue'),
            'pending_refund' => __('sirsoft-ecommerce::validation.request_attributes.pending_refund'),
            'min_amount' => __('sirsoft-ecommerce::validation.request_attributes.min_amount'),
            'max_amount' => __('sirsoft-ecommerce::validation.request_attributes.max_amount'),
            'currency_code' => __('sirsoft-ecommerce::validation.request_attributes.currency_code'),
            'charge_payment_methods' => __('sirsoft-ecommerce::validation.request_attributes.charge_payment_methods'),
            'charge_payment_methods.*' => __('sirsoft-ecommerce::validation.request_attributes.charge_payment_method'),
        ];
    }

    /**
     * 통화 코드 검증 규칙을 반환합니다. (설정 어휘 → 실제 기록값 순서)
     *
     * 코드를 열거하지 않는다 — 상점이 통화를 추가·삭제하면 화면 선택지와 게이트가 함께
     * 움직여야 하고, 갈라지면 화면에 보이는 통화가 422 를 받는다.
     *
     * 설정 어휘만 보면 반대 방향으로 깨진다. 이 필터가 거르는 값은 **거래 시점에 동결된
     * 통화**(교환·반품은 자기 `currency_code`, 환불·취소는 주문의 `currency`)라, 운영자가
     * 그 통화를 설정에서 내려도 그 값으로 기록된 신청서는 그대로 남는다. 게이트가 설정
     * 어휘만 받으면 그 신청서는 **어떤 필터 조합으로도 도달 불가**가 되고, 그 통화가 실린
     * 북마크·뒤로가기 URL 은 목록 전체를 422 로 만든다.
     *
     * tombstone(`removed_default_currencies`)만으로는 절반만 막힌다 — 그 기록은
     * **기본 제공 통화**의 삭제만 남기고(보충 병합이 되살리지 않게 하려는 용도),
     * 운영자가 직접 추가한 통화를 삭제하면 아무 흔적도 남지 않는다. 그래서 마지막 근거는
     * 설정이 아니라 **실제로 기록된 값**이다.
     *
     * 한 번도 쓰인 적 없는 코드는 여전히 거절한다 — 오타가 "결과 없음" 으로 위장하지
     * 않게 하려는 원래 의도는 그대로다. 데이터 조회는 설정 어휘를 벗어난 값에서만 일어난다.
     *
     * @param  ClaimTypeEnum  $type  조회 유형
     * @return array<int, mixed> 검증 규칙
     */
    private function currencyRules(ClaimTypeEnum $type): array
    {
        $rules = ['nullable', 'string', 'max:10'];
        $code = $this->stringInput('currency_code');

        if ($code === '') {
            return $rules;
        }

        $allowed = $this->settingCurrencies();

        if (! in_array($code, $allowed, true) && $this->currencyRecorded($code, $type)) {
            $allowed[] = $code;
        }

        $rules[] = Rule::in($allowed);

        return $rules;
    }

    /**
     * 설정이 아는 통화 코드를 반환합니다. (등록 통화 + 내린 기본 제공 통화)
     *
     * @return array<int, string> 통화 코드
     */
    private function settingCurrencies(): array
    {
        $settings = app(EcommerceSettingsService::class);

        $registered = collect($settings->getSetting('language_currency.currencies', []))
            ->pluck('code');

        // 운영자가 내린 기본 제공 통화 — 과거 신청서가 이 값으로 동결돼 있을 수 있다.
        $removed = collect($settings->getSetting('language_currency.removed_default_currencies', []))
            ->filter(fn ($code) => is_string($code));

        return $registered
            ->merge($removed)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 그 유형의 신청서에 실제로 기록된 통화인지 확인합니다.
     *
     * 저장소가 거르는 컬럼과 같은 것을 본다 — 교환·반품은 자기 `currency_code`,
     * 환불·취소는 주문의 `currency`. 판정 근거가 갈리면 게이트를 통과한 값이 저장소에서는
     * 다른 뜻이 되어 "통과했는데 늘 0건" 이 된다.
     *
     * @param  string  $code  통화 코드
     * @param  ClaimTypeEnum  $type  조회 유형
     * @return bool 기록 존재 여부
     */
    private function currencyRecorded(string $code, ClaimTypeEnum $type): bool
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => app(OrderExchangeRepositoryInterface::class)->hasRecordedCurrency($code),
            ClaimTypeEnum::RETURN => app(OrderReturnRepositoryInterface::class)->hasRecordedCurrency($code),
            default => app(OrderRepositoryInterface::class)->hasRecordedCurrency($code),
        };
    }

    /**
     * 청구 결제수단 검증 규칙을 반환합니다. (설정 어휘 → 실제 기록값 순서)
     *
     * 통화 축과 같은 규칙이다. 어휘를 열거하지 않고 클레임 설정
     * (`claim.charge_payment_methods`)을 읽되, 운영자가 나중에 수단을 내려도 그 수단으로
     * 기록된 추가결제는 남으므로 실제 기록값을 마지막 근거로 둔다. 길이만 검사하면
     * 오타가 조용히 "결과 없음" 으로 통과해 필터가 걸린 것처럼 보인다.
     *
     * @return array<int, mixed> 항목 검증 규칙
     */
    private function chargePaymentMethodRules(): array
    {
        $rules = ['string', 'max:50'];

        $allowed = collect(app(EcommerceSettingsService::class)->getSetting('claim.charge_payment_methods', []))
            ->filter(fn ($m) => is_string($m))
            ->values()
            ->all();

        // 설정 어휘를 벗어난 값만 기록을 조회한다 — 정상 조합에는 추가 쿼리가 없다.
        $submitted = collect((array) $this->input('charge_payment_methods', []))
            ->filter(fn ($m) => is_string($m) && $m !== '')
            ->unique()
            ->reject(fn ($m) => in_array($m, $allowed, true));

        $payments = $submitted->isEmpty() ? null : app(ClaimPaymentRepositoryInterface::class);

        foreach ($submitted as $method) {
            if ($payments->hasRecordedPaymentMethod($method)) {
                $allowed[] = $method;
            }
        }

        $rules[] = Rule::in($allowed);

        return $rules;
    }

    /**
     * 조회 유형에 허용되는 상태 어휘를 반환합니다. (유형별 Enum 단일 출처)
     *
     * @return array<int, string> 허용 상태 값
     */
    private function allowedStatuses(): array
    {
        return match (ClaimTypeEnum::tryFrom($this->stringInput('type'))) {
            ClaimTypeEnum::RETURN => ReturnStatusEnum::values(),
            ClaimTypeEnum::REFUND => RefundStatusEnum::values(),
            ClaimTypeEnum::CANCEL => CancelStatusEnum::values(),
            default => ExchangeStatusEnum::values(),
        };
    }

    /**
     * 규칙 계산에 쓸 문자열 입력을 읽습니다. (문자열이 아니면 빈 문자열)
     *
     * 규칙 계산은 검증보다 먼저 돌므로 값의 형식이 보장되지 않는다. 목록 등이 오면 형 변환하지 않고 빈
     * 값으로 읽는다 — 그 칸의 형식 오류는 규칙이 422 로 돌려준다(`(string)` 변환은 500 을 낸다).
     *
     * @param  string  $key  입력 키
     * @return string 문자열 입력 (아니면 빈 문자열)
     */
    private function stringInput(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : '';
    }

    /**
     * 조회 유형을 반환합니다. (기본 교환 — 서브탭 순서 D16)
     *
     * @return ClaimTypeEnum 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        $type = $this->validated('type');

        return $type ? ClaimTypeEnum::from((string) $type) : ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 저장소 필터를 반환합니다. (`paginateForAdmin()` 계약)
     *
     * @return array<string, mixed> 필터
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'statuses' => $validated['statuses'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'search_field' => $validated['search_field'] ?? null,
            'search_keyword' => $validated['search_keyword'] ?? null,
            'fault_types' => $validated['fault_types'] ?? null,
            'charge_methods' => $validated['charge_methods'] ?? null,
            'reason_code' => $validated['reason_code'] ?? null,
            'requester_type' => $validated['requester_type'] ?? null,
            'min_amount' => $validated['min_amount'] ?? null,
            'max_amount' => $validated['max_amount'] ?? null,
            'currency_code' => $validated['currency_code'] ?? null,
            'charge_payment_methods' => $validated['charge_payment_methods'] ?? null,
            'request_source' => $validated['request_source'] ?? null,
            'collection_overdue' => (bool) ($validated['collection_overdue'] ?? false),
            'pending_refund' => (bool) ($validated['pending_refund'] ?? false),
        ];
    }

    /**
     * 서브탭 뱃지 건수용 유형 공통 필터를 반환합니다. (`countForAdmin()` 계약)
     *
     * 상태·귀책·청구·사유 필터는 유형 종속 어휘라 다른 탭의 건수에 적용할 수 없어 뺀다.
     *
     * @return array<string, mixed> 유형 공통 필터
     */
    public function countFilters(): array
    {
        $validated = $this->validated();

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'search_field' => $validated['search_field'] ?? null,
            'search_keyword' => $validated['search_keyword'] ?? null,
            'requester_type' => $validated['requester_type'] ?? null,
            // 금액·통화는 유형마다 근거 컬럼이 다르지만 "그 행의 금액" 이라는 한 축이라
            // 다른 탭 건수에도 같은 뜻으로 적용된다. 청구 결제수단은 교환·반품에만 있는
            // 축이라 뺀다 — 넣으면 환불·취소 탭 건수가 항상 0 이 된다.
            'min_amount' => $validated['min_amount'] ?? null,
            'max_amount' => $validated['max_amount'] ?? null,
            'currency_code' => $validated['currency_code'] ?? null,
        ];
    }

    /**
     * 페이지당 건수를 반환합니다.
     *
     * @return int 페이지당 건수
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
