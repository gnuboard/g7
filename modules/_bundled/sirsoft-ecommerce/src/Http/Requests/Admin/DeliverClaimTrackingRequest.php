<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 관리자 운송장 단위 도착 처리 요청 — 배송 정보 탭 운송장 묶음의 [도착 처리] (R9)
 *
 * 그 신청서에서 같은 방향·같은 (택배사, 운송장)을 가진 상품 행 전부를 도착 처리한다. 그 운송장을
 * 가진 행이 없으면 서비스가 `claim_tracking_not_found` 로 거절한다.
 */
class DeliverClaimTrackingRequest extends FormRequest
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
            'direction' => ['required', 'string', Rule::in(ClaimTrackingDirectionEnum::values())],
            'carrier_id' => ['required', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['required', 'string', 'max:50'],
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
            'direction.required' => __('sirsoft-ecommerce::validation.claims.tracking.direction_required'),
            'direction.in' => __('sirsoft-ecommerce::validation.claims.tracking.direction_in'),
            'carrier_id.required' => __('sirsoft-ecommerce::validation.claims.tracking.deliver_carrier_required'),
            'carrier_id.integer' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_integer'),
            'carrier_id.exists' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_exists'),
            'tracking_number.required' => __('sirsoft-ecommerce::validation.claims.tracking.deliver_tracking_required'),
            'tracking_number.max' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_max'),
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
            'direction' => __('sirsoft-ecommerce::validation.request_attributes.tracking_direction'),
            'carrier_id' => __('sirsoft-ecommerce::validation.request_attributes.carrier'),
            'tracking_number' => __('sirsoft-ecommerce::validation.request_attributes.tracking_number'),
        ];
    }

    /**
     * 송장 방향을 반환합니다.
     *
     * @return ClaimTrackingDirectionEnum 방향
     */
    public function direction(): ClaimTrackingDirectionEnum
    {
        return ClaimTrackingDirectionEnum::from((string) $this->validated('direction'));
    }

    /**
     * 택배사 ID 를 반환합니다.
     *
     * @return int 택배사 ID
     */
    public function carrierId(): int
    {
        return (int) $this->validated('carrier_id');
    }

    /**
     * 운송장 번호를 반환합니다.
     *
     * @return string 운송장 번호
     */
    public function trackingNumber(): string
    {
        return trim((string) $this->validated('tracking_number'));
    }
}
