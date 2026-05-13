<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 은행 목록 저장 요청 검증
 *
 * 은행 관리 모달에서 은행 목록만 별도 저장할 때 사용됩니다.
 */
class StoreBanksRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * 현재 로케일의 은행명만 필수이며, 나머지 로케일은 선택입니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locale = app()->getLocale();

        return [
            'banks' => ['nullable', 'array'],
            'banks.*.code' => ['required_with:banks', 'string', 'max:10'],
            'banks.*.name' => ['required_with:banks', 'array'],
            "banks.*.name.{$locale}" => ['required_with:banks', 'string', 'max:100'],
            'banks.*.name.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * 검증 속성명 다국어 처리
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('sirsoft-ecommerce::validation.attributes');
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $locale = app()->getLocale();

        return [
            'banks.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.banks.code.required_with'),
            'banks.*.code.string' => __('sirsoft-ecommerce::validation.custom.banks.code.string'),
            'banks.*.code.max' => __('sirsoft-ecommerce::validation.custom.banks.code.max'),
            'banks.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.banks.name.required_with'),
            'banks.*.name.array' => __('sirsoft-ecommerce::validation.custom.banks.name.array'),
            "banks.*.name.{$locale}.required_with" => __('sirsoft-ecommerce::validation.custom.banks.name.required_with'),
            "banks.*.name.{$locale}.string" => __('sirsoft-ecommerce::validation.custom.banks.name.string'),
            "banks.*.name.{$locale}.max" => __('sirsoft-ecommerce::validation.custom.banks.name.max'),
            'banks.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.banks.name.string'),
            'banks.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.banks.name.max'),
        ];
    }
}
