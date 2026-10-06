<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimQuantityGuard;

/**
 * 주문 옵션 일괄 상태 변경 요청
 */
class BulkChangeOrderOptionStatusRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 권한 미들웨어에 위임하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 필드별 검증 규칙 배열
     */
    public function rules(): array
    {
        // 경로의 주문에 속한 옵션만 허용 (교차 주문 옵션 변경 차단)
        $orderId = $this->route('order')?->id;

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.option_id' => [
                'required',
                'integer',
                Rule::exists(OrderOption::class, 'id')->where('order_id', $orderId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // 클레임 코스 상태(교환진행중/반품진행중/교환완료/반품완료)는 교환·반품 서비스가
            // 신청서와 짝을 맞춰 기록하는 값이라 주문 화면에서 직접 지정할 수 없다.
            'status' => ['required', 'string', Rule::in(OrderStatusEnum::adminAssignableValues())],
            'carrier_id' => ['nullable', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['nullable', 'string', 'max:50'],
            // 수동 결제 처리 확인 창의 세 항목 (Q13) — 보내지 않으면 모두 켬(기존 클라이언트 호환)
            'apply_stock_deduction' => ['sometimes', 'boolean'],
            'apply_mileage_deduction' => ['sometimes', 'boolean'],
            'record_paid_amount' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * 검증 오류 문구에 쓸 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'apply_stock_deduction' => __('sirsoft-ecommerce::validation.attributes.apply_stock_deduction'),
            'apply_mileage_deduction' => __('sirsoft-ecommerce::validation.attributes.apply_mileage_deduction'),
            'record_paid_amount' => __('sirsoft-ecommerce::validation.attributes.record_paid_amount'),
        ];
    }

    /**
     * 추가 검증 로직
     *
     * @param  Validator  $validator
     * @return void 반환값 없음
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);

            // 대상 옵션 스냅샷을 1회 배치 조회 (수량·전이 검증 공용, N+1 회피).
            // Repository 인터페이스 경유 — Model 직접 호출(audit formrequest-direct-data-access) 금지.
            $repo = app(OrderOptionRepositoryInterface::class);
            $guard = app(ClaimQuantityGuard::class);
            $optionIds = collect($items)->pluck('option_id')->filter()->all();
            $snapshots = $repo->getSnapshotsByIds($optionIds); // id 키 스냅샷 (option_status / quantity 포함)

            $target = OrderStatusEnum::tryFrom((string) $this->input('status'));

            foreach ($items as $index => $item) {
                if (! isset($item['option_id'], $item['quantity'])) {
                    continue;
                }

                $snap = $snapshots[$item['option_id']] ?? null;
                if ($snap === null) {
                    continue; // rules 의 exists 검증에 위임
                }

                // 수량 검증 (배치 스냅샷 재사용)
                if ($item['quantity'] > ($snap['quantity'] ?? 0)) {
                    $validator->errors()->add(
                        "items.{$index}.quantity",
                        __('sirsoft-ecommerce::validation.quantity_exceeds_available')
                    );
                }

                // 클레임 코스 상태 차단 (D15 후단) — 진행 중인 교환·반품의 상품은 상태 변경 대상이
                // 아니다. 전이 게이트는 반려·철회 복귀를 위해 코스 → 판매 상태를 허용하므로 그 게이트
                // 만으로는 걸러지지 않는다. 판정은 ClaimQuantityGuard 단일 출처를 읽는다.
                $course = $guard->resolveClaimCourseStatus($snap['option_status'] ?? null);
                if ($course !== null) {
                    $validator->errors()->add(
                        "items.{$index}.status",
                        __('sirsoft-ecommerce::exceptions.claim_option_in_course_status_change', [
                            'status' => $course->label(),
                        ])
                    );

                    continue;
                }

                // 상태 전이 검증 (역방향/비연속 역행 차단, all-or-nothing)
                $currentValue = $snap['option_status'] ?? null;
                $current = $currentValue !== null ? OrderStatusEnum::tryFrom(
                    $currentValue instanceof OrderStatusEnum ? $currentValue->value : $currentValue
                ) : null;
                if ($target !== null && $current !== null && ! $current->canTransitionTo($target)) {
                    $validator->errors()->add("items.{$index}.status", __('sirsoft-ecommerce::validation.orders.status_transition.invalid', [
                        'from' => $current->label(),
                        'to' => $target->label(),
                    ]));
                }
            }

            // 배송 관련 상태 선택 시 택배사/송장번호 필수
            $status = $this->input('status');

            if ($status && in_array($status, OrderStatusEnum::shippingInfoRequiredValues())) {
                if (! $this->input('carrier_id')) {
                    $validator->errors()->add('carrier_id', __('sirsoft-ecommerce::validation.orders.carrier_required'));
                }
                if (! $this->input('tracking_number')) {
                    $validator->errors()->add('tracking_number', __('sirsoft-ecommerce::validation.orders.tracking_number_required'));
                }
            }
        });
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string> 규칙별 다국어 에러 메시지 배열
     */
    public function messages(): array
    {
        return [
            'items.required' => __('sirsoft-ecommerce::validation.order_options.items.required'),
            'items.min' => __('sirsoft-ecommerce::validation.order_options.items.min'),
            'items.*.option_id.required' => __('sirsoft-ecommerce::validation.order_options.option_id.required'),
            'items.*.option_id.exists' => __('sirsoft-ecommerce::validation.order_options.option_id.exists'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.order_options.quantity.required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.order_options.quantity.min'),
            'status.required' => __('sirsoft-ecommerce::validation.order_options.status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.order_options.status.in'),
        ];
    }
}
