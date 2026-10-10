<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 운영자의 신청 내용 수정 요청 (S15 — 사유 · 상세 사유 · 교환 재발송지)
 *
 * 사유 코드가 그 유형의 활성 사유인지는 서비스가 판정한다(`resolveReason()` — 운영자는 판매자 귀책
 * 사유도 고른다). 수정 가능 단계도 서비스가 고객 수정과 같은 기준으로 판정한다.
 */
class UpdateClaimRequestOnBehalfRequest extends FormRequest
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
            'reason_code' => ['nullable', 'string', 'max:50'],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            'redelivery_address' => ['nullable', 'array'],
            'redelivery_address.recipient_name' => ['nullable', 'string', 'max:50'],
            'redelivery_address.phone' => ['nullable', 'string', 'max:20'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.address1' => ['nullable', 'string', 'max:255'],
            'redelivery_address.address2' => ['nullable', 'string', 'max:255'],
            'redelivery_address.city' => ['nullable', 'string', 'max:100'],
            'redelivery_address.state' => ['nullable', 'string', 'max:100'],
            'redelivery_address.country_code' => ['nullable', 'string', 'size:2'],
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
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'redelivery_address.array' => __('sirsoft-ecommerce::validation.claims.redelivery_address.array'),
            'redelivery_address.country_code.size' => __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_size'),
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
            'redelivery_address' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address'),
            'redelivery_address.recipient_name' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_recipient_name'),
            'redelivery_address.phone' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_phone'),
            'redelivery_address.zipcode' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_zipcode'),
            'redelivery_address.address1' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address1'),
            'redelivery_address.address2' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_address2'),
            'redelivery_address.city' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_city'),
            'redelivery_address.state' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_state'),
            'redelivery_address.country_code' => __('sirsoft-ecommerce::validation.request_attributes.redelivery_country_code'),
        ];
    }

    /**
     * 서비스에 넘길 수정 페이로드를 반환합니다. (보낸 키만)
     *
     * @return array<string, mixed> 수정 페이로드
     */
    public function payload(): array
    {
        return array_intersect_key($this->validated(), array_flip(['reason_code', 'reason_detail', 'redelivery_address']));
    }
}
