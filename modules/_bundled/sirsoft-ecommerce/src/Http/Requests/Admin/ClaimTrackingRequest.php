<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 관리자 클레임 운송장 입력 요청 (회수·재발송 공용)
 *
 * 택배사·운송장 번호의 "함께 있어야 한다" 검증은 서비스가 담당한다
 * (`registerCollectionTracking()` 의 `claim_tracking_incomplete`) — 여기서는 형식만 닫는다.
 * 운송장 상한 50자는 DB 칸(`*_tracking_number` varchar 50) 길이다 — 넘기면 저장이 SQL 오류로 끊긴다.
 */
class ClaimTrackingRequest extends FormRequest
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
        return [
            'carrier_id' => ['nullable', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['nullable', 'string', 'max:50'],
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
            'carrier_id.integer' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_integer'),
            'carrier_id.exists' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_exists'),
            'tracking_number.max' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_max'),
        ];
    }

    /**
     * 택배사 ID 를 반환합니다.
     *
     * @return int|null 택배사 ID
     */
    public function carrierId(): ?int
    {
        $carrierId = $this->validated('carrier_id');

        return $carrierId === null ? null : (int) $carrierId;
    }

    /**
     * 운송장 번호를 반환합니다.
     *
     * @return string|null 운송장 번호
     */
    public function trackingNumber(): ?string
    {
        $trackingNumber = $this->validated('tracking_number');

        return $trackingNumber === null || $trackingNumber === '' ? null : (string) $trackingNumber;
    }
}
