<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;

/**
 * 관리자 「송장만 저장」 요청 — 고른 상품 행(수량 지정 시 쪼갠 행)에 한 방향 송장을 적는다 (교환·반품)
 *
 * 상태는 바꾸지 않는다(P6·R7). 택배사·운송장을 둘 다 비우면 그 행의 송장을 지운다. 한쪽만 오면
 * 서비스가 `claim_tracking_incomplete` 로 거절한다(R2 — 판정은 서비스가 소유한다).
 *
 * 행 ID 가 이 신청서의 것인지는 서비스가 판정해 404 로 답한다 — 다른 신청서의 행을 422 로 세우면
 * 「그런 행은 있다」 는 사실이 드러난다. 수량 상한(행 수량)도 행을 읽는 서비스가 판정한다.
 *
 * 운송장 상한 50자는 DB 칸(`*_tracking_number` varchar 50) 길이다(P7).
 */
class UpdateClaimItemTrackingRequest extends FormRequest
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
            'carrier_id' => ['nullable', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * 추가 검증 — 같은 상품 행을 두 줄로 보내는 것을 막습니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = [];

            foreach ((array) $this->input('items', []) as $entry) {
                if (is_array($entry) && isset($entry['id'])) {
                    $ids[] = (int) $entry['id'];
                }
            }

            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('items', __('sirsoft-ecommerce::validation.claims.items.duplicate_id'));
            }
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
            'direction.required' => __('sirsoft-ecommerce::validation.claims.tracking.direction_required'),
            'direction.in' => __('sirsoft-ecommerce::validation.claims.tracking.direction_in'),
            'carrier_id.integer' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_integer'),
            'carrier_id.exists' => __('sirsoft-ecommerce::validation.claims.tracking.carrier_id_exists'),
            'tracking_number.max' => __('sirsoft-ecommerce::validation.claims.tracking.tracking_number_max'),
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'items.*.array' => __('sirsoft-ecommerce::validation.claims.items.entry_object'),
            'items.*.id.required' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
            'items.*.id.integer' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
            'items.*.quantity.integer' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.claims.items.quantity_min'),
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
            'items' => __('sirsoft-ecommerce::validation.request_attributes.claim_item'),
            'items.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_item'),
            'items.*.id' => __('sirsoft-ecommerce::validation.request_attributes.claim_item'),
            'items.*.quantity' => __('sirsoft-ecommerce::validation.request_attributes.quantity'),
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
     * @return int|null 택배사 ID (비우면 null)
     */
    public function carrierId(): ?int
    {
        $carrierId = $this->validated('carrier_id');

        return $carrierId === null || $carrierId === '' ? null : (int) $carrierId;
    }

    /**
     * 운송장 번호를 반환합니다.
     *
     * @return string|null 운송장 번호 (비우면 null)
     */
    public function trackingNumber(): ?string
    {
        $trackingNumber = $this->validated('tracking_number');

        return $trackingNumber === null || trim((string) $trackingNumber) === '' ? null : (string) $trackingNumber;
    }

    /**
     * 대상 행 지시를 반환합니다. (`[{id, quantity}]` — 수량 미지정은 null)
     *
     * @return array<int, array{id: int, quantity: int|null}> 대상 행 지시
     */
    public function itemSpecs(): array
    {
        return ClaimItemSpecs::normalize((array) $this->validated('items'));
    }
}
