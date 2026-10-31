<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderDateTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;

/**
 * 주문 목록 조회 요청
 */
class OrderListRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 항상 true (권한 체크는 라우트의 permission 미들웨어에서 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array 주문 목록 조회 파라미터 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // 문자열 검색
            'search_field' => ['nullable', 'in:all,order_number,orderer_name,recipient_name,orderer_phone,recipient_phone,product_name,sku'],
            'search_keyword' => ['nullable', 'string', 'max:200'],

            // 날짜
            'date_type' => ['nullable', Rule::in(OrderDateTypeEnum::values())],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // 날짜를 어느 시간대의 하루로 풀지 — 없으면 보는 사람의 하루. 대시보드 「오늘」 배지 링크만 site 를 실어
            // 배지가 센 하루(사이트 표준시)와 같은 범위로 연다
            'date_basis' => ['nullable', Rule::in(['site'])],

            // 주문상태 (다중선택)
            'order_status' => ['nullable', 'array'],
            'order_status.*' => ['string', Rule::in(OrderStatusEnum::values())],

            // 옵션상태 (다중선택)
            'option_status' => ['nullable', 'array'],
            'option_status.*' => ['string', Rule::in(OrderStatusEnum::values())],

            // 배송유형 (다중선택)
            'shipping_type' => ['nullable', 'array'],
            // 비활성 배송유형으로도 과거 주문을 필터링할 수 있어야 하므로 전체 코드를 허용한다
            // (getActiveCodes() 를 쓰면 비활성 유형의 기존 주문이 조회 불가가 된다).
            'shipping_type.*' => ['string', Rule::in(
                app(ShippingTypeRepositoryInterface::class)->getAll()->pluck('code')->toArray()
            )],

            // 결제수단 (다중선택)
            'payment_method' => ['nullable', 'array'],
            // 확장 결제수단(간편결제)도 주문 목록에서 필터링할 수 있어야 한다 (#475).
            'payment_method.*' => ['string', Rule::in(app(PaymentMethodResolver::class)->allValidIds())],

            // 카테고리
            'category_id' => ['nullable', 'integer'],

            // 금액 범위
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'max_amount' => ['nullable', 'integer', 'min:0'],

            // 배송국가 코드 (다중선택)
            'country_codes' => ['nullable', 'array'],
            'country_codes.*' => ['string', 'size:2'],

            // 주문 디바이스 (다중선택)
            'order_device' => ['nullable', 'array'],
            'order_device.*' => ['string', Rule::in(DeviceTypeEnum::values())],

            // 배송비 범위
            'min_shipping_amount' => ['nullable', 'integer', 'min:0'],
            'max_shipping_amount' => ['nullable', 'integer', 'min:0'],

            // 배송정책
            'shipping_policy_id' => ['nullable', 'integer'],

            // 클레임 기준 필터 (D22) — 이 주문에 접수된 클레임의 유무·유형별 상태·귀책·신청일로 거른다.
            // 저장소는 유형별 whereExists 서브쿼리로 적용한다 (조인 금지 — 1:N 부풀림 방지).
            'claim_presence' => ['nullable', 'in:none,any,active'],
            'claim_exchange_statuses' => ['nullable', 'array'],
            'claim_exchange_statuses.*' => ['string', Rule::in(ExchangeStatusEnum::values())],
            'claim_return_statuses' => ['nullable', 'array'],
            'claim_return_statuses.*' => ['string', Rule::in(ReturnStatusEnum::values())],
            'claim_refund_statuses' => ['nullable', 'array'],
            'claim_refund_statuses.*' => ['string', Rule::in(RefundStatusEnum::values())],
            // 취소 축의 어휘는 상태가 아니라 취소 유형(전체/부분)이다 — 취소는 즉시 파이프라인이라
            // 전이 상태 2종(신청/완료)이 화면 구분 축이 되지 못하고, 확정 시안(§6-4-2)도
            // 부분취소/전체취소 칩이다 (CancelTypeEnum 단일 출처).
            'claim_cancel_types' => ['nullable', 'array'],
            'claim_cancel_types.*' => ['string', Rule::in(CancelTypeEnum::values())],
            'claim_fault_types' => ['nullable', 'array'],
            'claim_fault_types.*' => ['string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            'claim_requested_from' => ['nullable', 'date'],
            'claim_requested_to' => ['nullable', 'date', 'after_or_equal:claim_requested_from'],

            // 회원 ID
            'user_id' => ['nullable', 'integer'],

            // 주문자 UUID (회원 검색 필터용)
            'orderer_uuid' => ['nullable', 'uuid'],

            // 회원 구분 (member: 회원 주문, guest: 비회원 주문)
            'member_type' => ['nullable', 'in:member,guest'],

            // 정렬 및 페이지네이션
            // shipped_at 은 주문이 아니라 배송 테이블 컬럼이다 — OrderRepository 가
            // RELATED_SORTABLE_COLUMNS 로 상관 서브쿼리 정렬을 수행한다.
            'sort_by' => ['nullable', 'in:ordered_at,paid_at,total_amount,shipped_at'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.order.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            // 검색 필드
            'search_field.in' => __('sirsoft-ecommerce::validation.orders.search_field.in'),
            'search_keyword.string' => __('sirsoft-ecommerce::validation.orders.search_keyword.string'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.orders.search_keyword.max'),
            // 날짜
            'date_type.in' => __('sirsoft-ecommerce::validation.orders.date_type.in'),
            'start_date.date' => __('sirsoft-ecommerce::validation.orders.start_date.date'),
            'end_date.date' => __('sirsoft-ecommerce::validation.orders.end_date.date'),
            'end_date.after_or_equal' => __('sirsoft-ecommerce::validation.orders.end_date.after_or_equal'),
            'date_basis.in' => __('sirsoft-ecommerce::validation.orders.date_basis.in'),
            // 주문상태
            'order_status.array' => __('sirsoft-ecommerce::validation.orders.order_status.array'),
            'order_status.*.string' => __('sirsoft-ecommerce::validation.orders.order_status.string'),
            'order_status.*.in' => __('sirsoft-ecommerce::validation.orders.order_status.in'),
            // 옵션상태
            'option_status.array' => __('sirsoft-ecommerce::validation.orders.option_status.array'),
            'option_status.*.string' => __('sirsoft-ecommerce::validation.orders.option_status.string'),
            'option_status.*.in' => __('sirsoft-ecommerce::validation.orders.option_status.in'),
            // 배송유형
            'shipping_type.array' => __('sirsoft-ecommerce::validation.orders.shipping_type.array'),
            'shipping_type.*.string' => __('sirsoft-ecommerce::validation.orders.shipping_type.string'),
            'shipping_type.*.in' => __('sirsoft-ecommerce::validation.orders.shipping_type.in'),
            // 결제수단
            'payment_method.array' => __('sirsoft-ecommerce::validation.orders.payment_method.array'),
            'payment_method.*.string' => __('sirsoft-ecommerce::validation.orders.payment_method.string'),
            'payment_method.*.in' => __('sirsoft-ecommerce::validation.orders.payment_method.in'),
            // 카테고리
            'category_id.integer' => __('sirsoft-ecommerce::validation.orders.category_id.integer'),
            // 금액 범위
            'min_amount.integer' => __('sirsoft-ecommerce::validation.orders.min_amount.integer'),
            'min_amount.min' => __('sirsoft-ecommerce::validation.orders.min_amount.min'),
            'max_amount.integer' => __('sirsoft-ecommerce::validation.orders.max_amount.integer'),
            'max_amount.min' => __('sirsoft-ecommerce::validation.orders.max_amount.min'),
            // 배송국가 코드
            'country_codes.array' => __('sirsoft-ecommerce::validation.orders.country_codes.array'),
            'country_codes.*.string' => __('sirsoft-ecommerce::validation.orders.country_codes.string'),
            'country_codes.*.size' => __('sirsoft-ecommerce::validation.orders.country_codes.size'),
            // 주문 디바이스
            'order_device.array' => __('sirsoft-ecommerce::validation.orders.order_device.array'),
            'order_device.*.string' => __('sirsoft-ecommerce::validation.orders.order_device.string'),
            'order_device.*.in' => __('sirsoft-ecommerce::validation.orders.order_device.in'),
            // 배송비 범위
            'min_shipping_amount.integer' => __('sirsoft-ecommerce::validation.orders.min_shipping_amount.integer'),
            'min_shipping_amount.min' => __('sirsoft-ecommerce::validation.orders.min_shipping_amount.min'),
            'max_shipping_amount.integer' => __('sirsoft-ecommerce::validation.orders.max_shipping_amount.integer'),
            'max_shipping_amount.min' => __('sirsoft-ecommerce::validation.orders.max_shipping_amount.min'),
            // 배송정책
            'shipping_policy_id.integer' => __('sirsoft-ecommerce::validation.orders.shipping_policy_id.integer'),
            // 클레임 기준 필터 (D22)
            'claim_presence.in' => __('sirsoft-ecommerce::validation.orders.claim_presence.in'),
            'claim_exchange_statuses.array' => __('sirsoft-ecommerce::validation.orders.claim_statuses.array'),
            'claim_exchange_statuses.*.in' => __('sirsoft-ecommerce::validation.orders.claim_statuses.in'),
            'claim_return_statuses.array' => __('sirsoft-ecommerce::validation.orders.claim_statuses.array'),
            'claim_return_statuses.*.in' => __('sirsoft-ecommerce::validation.orders.claim_statuses.in'),
            'claim_refund_statuses.array' => __('sirsoft-ecommerce::validation.orders.claim_statuses.array'),
            'claim_refund_statuses.*.in' => __('sirsoft-ecommerce::validation.orders.claim_statuses.in'),
            // 취소 축은 상태가 아니라 유형(full/partial)이다 — 상태 문구를 재사용하면
            // 어휘 단일 출처가 화면 문구에서 다시 흐려진다.
            'claim_cancel_types.array' => __('sirsoft-ecommerce::validation.orders.claim_cancel_types.array'),
            'claim_cancel_types.*.in' => __('sirsoft-ecommerce::validation.orders.claim_cancel_types.in'),
            'claim_fault_types.array' => __('sirsoft-ecommerce::validation.orders.claim_fault_types.array'),
            'claim_fault_types.*.in' => __('sirsoft-ecommerce::validation.orders.claim_fault_types.in'),
            'claim_requested_from.date' => __('sirsoft-ecommerce::validation.orders.claim_requested_from.date'),
            'claim_requested_to.date' => __('sirsoft-ecommerce::validation.orders.claim_requested_to.date'),
            'claim_requested_to.after_or_equal' => __('sirsoft-ecommerce::validation.orders.claim_requested_to.after_or_equal'),
            // 회원 ID
            'user_id.integer' => __('sirsoft-ecommerce::validation.orders.user_id.integer'),
            // 주문자 UUID
            'orderer_uuid.uuid' => __('sirsoft-ecommerce::validation.orders.orderer_uuid.uuid'),
            'member_type.in' => __('sirsoft-ecommerce::validation.orders.member_type.in'),
            // 정렬 및 페이지네이션
            'sort_by.in' => __('sirsoft-ecommerce::validation.orders.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.orders.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.orders.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.orders.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.orders.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.orders.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.orders.page.min'),
        ];

        // 훅을 통한 validation messages 확장
        return HookManager::applyFilters('sirsoft-ecommerce.order.list_validation_messages', $messages, $this);
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'claim_presence' => __('sirsoft-ecommerce::validation.request_attributes.claim_presence'),
            'claim_exchange_statuses' => __('sirsoft-ecommerce::validation.request_attributes.claim_exchange_statuses'),
            'claim_exchange_statuses.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_exchange_status'),
            'claim_return_statuses' => __('sirsoft-ecommerce::validation.request_attributes.claim_return_statuses'),
            'claim_return_statuses.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_return_status'),
            'claim_refund_statuses' => __('sirsoft-ecommerce::validation.request_attributes.claim_refund_statuses'),
            'claim_refund_statuses.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_refund_status'),
            'claim_cancel_types' => __('sirsoft-ecommerce::validation.request_attributes.claim_cancel_types'),
            'claim_cancel_types.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_cancel_type'),
            'claim_fault_types' => __('sirsoft-ecommerce::validation.request_attributes.fault_types'),
            'claim_fault_types.*' => __('sirsoft-ecommerce::validation.request_attributes.fault_type'),
            'claim_requested_from' => __('sirsoft-ecommerce::validation.request_attributes.claim_requested_from'),
            'claim_requested_to' => __('sirsoft-ecommerce::validation.request_attributes.claim_requested_to'),
        ];
    }
}
