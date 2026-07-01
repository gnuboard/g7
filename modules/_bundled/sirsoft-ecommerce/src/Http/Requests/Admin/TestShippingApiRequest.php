<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiAuthType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiHttpMethod;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiRequestField;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiResponseType;

/**
 * 배송정책 계산 API 테스트 호출 요청
 *
 * 관리자가 폼에서 입력 중인 단일 API 구성으로 외부 API 를 1회 실호출하기 위한 입력 검증.
 */
class TestShippingApiRequest extends FormRequest
{
    /**
     * 권한 검사는 라우트의 permission 미들웨어가 담당하므로 통과시킵니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 테스트 호출 입력 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:500'],
            'request_fields' => ['nullable', 'array'],
            'request_fields.*' => ['string', Rule::in(ShippingApiRequestField::values())],

            'config' => ['nullable', 'array'],
            'config.http_method' => ['nullable', 'string', Rule::in(ShippingApiHttpMethod::values())],
            'config.auth_type' => ['nullable', 'string', Rule::in(ShippingApiAuthType::values())],
            'config.auth_token' => ['nullable', 'string', 'max:1000'],
            'config.auth_header_name' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/'],
            'config.response_type' => ['nullable', 'string', Rule::in(ShippingApiResponseType::values())],
            'config.response_path' => ['nullable', 'string', 'max:200'],
            'config.field_map' => ['nullable', 'array'],
            'config.field_map.*' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-]+$/'],

            // 테스트용 샘플 값 (관리자 입력, 더미 자동 채움 보조)
            'sample' => ['nullable', 'array'],
            'sample.group_total' => ['nullable', 'numeric', 'min:0'],
            'sample.total_quantity' => ['nullable', 'integer', 'min:0'],
            'sample.country_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
