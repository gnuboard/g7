<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 회수중·발송중 전이에 실리는 송장(택배사 + 운송장 번호) 검증 — 클레임 전이 요청 공용
 *
 * 송장은 **상품 행**에 쓰인다 — 상품 칸 요청은 고른 행(수량 지정 시 쪼갠 행)에, 신청서 단건·목록 일괄
 * 요청은 그 신청서의 이동 대상 행 전부에 쓴다. 회수도착·발송도착 직접 전이(P2)는 송장이 선택이다 —
 * 실리면 그 행에 함께 쓴다.
 *
 * 회수중·발송중은 택배가 물건을 싣고 가는 중이라는 사실이라 송장이 있어야 들어간다(#599 후속
 * 운영 결정). 신청서 단건·상품 칸 요청은 한 쌍(`carrier_id`·`tracking_number`)을, 목록 일괄 요청은
 * 신청서마다 한 쌍(`trackings[]`)을 싣는다 — 송장은 신청서마다 다르다. 서비스도 같은 규칙으로
 * 한 번 더 막는다(`RecordsClaimTracking`) — 여기는 입력 형식과 누락을 먼저 422 로 알리는 자리다.
 *
 * 운송장 번호 상한은 DB 칸(`*_tracking_number` varchar 50)과 같다 — 넘으면 저장이 SQL 오류로 끊긴다.
 */
trait ValidatesClaimTransitionTracking
{
    /**
     * 송장이 있어야 들어가는 상태 값을 반환합니다. (교환·반품 공용 — 반품에는 발송중이 없다)
     *
     * @return array<int, string> 상태 값
     */
    protected function trackingRequiredStatuses(): array
    {
        return ['collecting', 'redelivering'];
    }

    /**
     * 송장을 선택으로 함께 실을 수 있는 도착 상태 값을 반환합니다. (P2 — 회수도착·발송도착)
     *
     * @return array<int, string> 상태 값
     */
    protected function trackingOptionalStatuses(): array
    {
        return ['collected', 'redelivered'];
    }

    /**
     * 단건 송장(`carrier_id`·`tracking_number`) 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    protected function transitionTrackingRules(): array
    {
        $requiredIf = 'required_if:status,'.implode(',', $this->trackingRequiredStatuses());

        return [
            'carrier_id' => ['nullable', $requiredIf, 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['nullable', $requiredIf, 'string', 'max:50'],
        ];
    }

    /**
     * 단건 송장 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    protected function transitionTrackingMessages(): array
    {
        return [
            'carrier_id.required_if' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_required'),
            'carrier_id.integer' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_integer'),
            'carrier_id.exists' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_exists'),
            'tracking_number.required_if' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_required'),
            'tracking_number.max' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_max'),
        ];
    }

    /**
     * 단건 송장 필드 표시명을 반환합니다.
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    protected function transitionTrackingAttributes(): array
    {
        return [
            'carrier_id' => __('sirsoft-ecommerce::validation.request_attributes.carrier'),
            'tracking_number' => __('sirsoft-ecommerce::validation.request_attributes.tracking_number'),
        ];
    }

    /**
     * 송장이 필요한 목표 상태인지 반환합니다.
     *
     * @param  string  $status  목표 상태 값
     * @return bool 송장이 필요하면 true
     */
    protected function requiresTransitionTracking(string $status): bool
    {
        return in_array($status, $this->trackingRequiredStatuses(), true);
    }

    /**
     * 전이 부가 정보에 실을 단건 송장을 반환합니다. (송장이 필요한 상태가 아니면 빈 배열)
     *
     * @param  string  $status  목표 상태 값
     * @return array{carrier_id?: int, tracking_number?: string} 송장
     */
    protected function transitionTrackingContext(string $status): array
    {
        if ($this->requiresTransitionTracking($status)) {
            return [
                'carrier_id' => (int) $this->validated('carrier_id'),
                'tracking_number' => (string) $this->validated('tracking_number'),
            ];
        }

        // 도착 직접 전이에 실린 송장은 그 행에 함께 쓴다 — 한쪽만 오면 서비스가 `claim_tracking_incomplete` 로 막는다.
        if (in_array($status, $this->trackingOptionalStatuses(), true)) {
            $carrierId = $this->validated('carrier_id');
            $trackingNumber = $this->validated('tracking_number');

            if (($carrierId === null || $carrierId === '') && ($trackingNumber === null || $trackingNumber === '')) {
                return [];
            }

            return [
                'carrier_id' => $carrierId === null || $carrierId === '' ? null : (int) $carrierId,
                'tracking_number' => $trackingNumber === null ? null : (string) $trackingNumber,
            ];
        }

        return [];
    }
}
